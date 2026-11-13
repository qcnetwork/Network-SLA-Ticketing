<?php

namespace App\Services;

use App\Models\Customer;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Brings the billing system's "Data Seluruh Pelanggan" export into the customer directory. The export
 * comes either as a real .xlsx or as an HTML table saved with an .xls name; both carry the same columns
 * (ID Pelanggan, Nama Pelanggan, Alamat, Tlp, Tlp2, Titik Koordinat Lokasi, ...).
 *
 * A customer already in the directory keeps what CS wrote on their tickets: the export only fills in
 * a phone, address or location the directory is still missing. Running it again is safe.
 *
 * Many locations are maps.app.goo.gl short links. Those are opened only when a resolver is passed, and
 * only for customers still without a location, so a re-run asks Google just for the ones left.
 */
class CustomerImport
{
    /**
     * @param  list<string>  $files
     * @param  (callable(string): ?array{0: float, 1: float})|null  $resolveLink  opens a short Maps link
     * @param  (callable(int): void)|null  $linksFound  told how many links will be opened, before the first
     * @return array{rows: int, skipped: int, created: int, filled: int, unchanged: int, no_phone: int, no_location: int, links_resolved: int, links_failed: int}
     */
    public function import(array $files, bool $dryRun = false, ?callable $resolveLink = null, ?callable $linksFound = null): array
    {
        $report = ['rows' => 0, 'skipped' => 0, 'created' => 0, 'filled' => 0, 'unchanged' => 0, 'no_phone' => 0, 'no_location' => 0, 'links_resolved' => 0, 'links_failed' => 0];

        $customers = [];
        foreach ($files as $file) {
            foreach ($this->rows($file) as $row) {
                $report['rows']++;
                $customer = $this->customer($row);
                if (! $customer) {
                    $report['skipped']++;

                    continue;
                }
                // The same ID twice: the later file wins, as it is the newer export.
                $customers[mb_strtolower($customer['code'])] = $customer;
            }
        }

        $existing = Customer::query()->get()->keyBy(fn (Customer $c) => mb_strtolower(trim($c->code)));

        if ($resolveLink) {
            $pending = array_filter($customers, fn ($customer, $key) => $customer['latitude'] === null && $customer['link'] !== null
                && $existing->get($key)?->latitude === null, ARRAY_FILTER_USE_BOTH);
            if ($linksFound) {
                $linksFound(count($pending));
            }
            foreach ($pending as $key => $customer) {
                $coordinates = $resolveLink($customer['link']);
                $report[$coordinates ? 'links_resolved' : 'links_failed']++;
                if ($coordinates) {
                    [$customers[$key]['latitude'], $customers[$key]['longitude']] = $coordinates;
                }
            }
        }

        $now = now();
        $inserts = [];
        $updates = [];

        foreach ($customers as $key => $customer) {
            unset($customer['link']);
            $report['no_phone'] += $customer['phone'] === '' ? 1 : 0;
            $report['no_location'] += $customer['latitude'] === null ? 1 : 0;

            $current = $existing->get($key);
            if (! $current) {
                $inserts[] = $customer + ['created_at' => $now, 'updated_at' => $now];

                continue;
            }
            $fill = array_filter([
                'phone' => trim((string) $current->phone) === '' && $customer['phone'] !== '' ? $customer['phone'] : null,
                'address' => trim((string) $current->address) === '' ? $customer['address'] : null,
            ]);
            if ($current->latitude === null && $customer['latitude'] !== null) {
                $fill += ['latitude' => $customer['latitude'], 'longitude' => $customer['longitude']];
            }
            if ($fill) {
                $updates[$current->id] = $fill;
            }
        }

        $report['created'] = count($inserts);
        $report['filled'] = count($updates);
        $report['unchanged'] = count($customers) - count($inserts) - count($updates);

        if (! $dryRun) {
            DB::transaction(function () use ($inserts, $updates, $now) {
                foreach (array_chunk($inserts, 500) as $batch) {
                    DB::table('customers')->insert($batch);
                }
                foreach ($updates as $id => $fill) {
                    DB::table('customers')->where('id', $id)->update($fill + ['updated_at' => $now]);
                }
            });
        }

        return $report;
    }

    /**
     * One customer row in the directory's own form, or null when it has no ID or name.
     *
     * @param  array<string, mixed>  $row
     * @return array{code: string, name: string, phone: string, address: ?string, latitude: ?float, longitude: ?float, link: ?string}|null
     */
    private function customer(array $row): ?array
    {
        $code = $this->clean($row['ID Pelanggan'] ?? null);
        $name = $this->clean($row['Nama Pelanggan'] ?? null);
        if ($code === '' || $name === '' || mb_strlen($code) > 50) {
            return null;
        }
        $phone = $this->phone($row['Tlp'] ?? null);
        if ($phone === '') {
            $phone = $this->phone($row['Tlp2'] ?? null);
        }
        $address = $this->clean($row['Alamat'] ?? null);
        $location = $this->clean($row['Titik Koordinat Lokasi'] ?? null);
        [$latitude, $longitude] = $this->coordinates($location) ?? [null, null];
        preg_match('~https?://(?:maps\.app\.goo\.gl|goo\.gl|g\.co)/\S+~i', $location, $link);

        return [
            'code' => $code,
            'name' => mb_substr($name, 0, 150),
            'phone' => $phone,
            'address' => $address === '' ? null : mb_substr($address, 0, 255),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'link' => $latitude === null ? ($link[0] ?? null) : null,
        ];
    }

    /**
     * The WhatsApp number in the 628xx form tickets use. The export drops the leading 0 on some
     * numbers (8xx), and some cells hold only "0" or other stubs, which count as no number.
     */
    private function phone(mixed $value): string
    {
        $digits = preg_replace('/\D/', '', (string) $value);
        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        return str_starts_with($digits, '628') && strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : '';
    }

    /**
     * A latitude/longitude pair inside Indonesia, wherever it sits in the cell: "lat, lng", with comma
     * decimals ("-6,95, 109,63"), with a trailing accuracy ("...,500m") or after an address. Short
     * Google Maps links cannot be read without opening them, so they give no location here.
     *
     * @return array{0: float, 1: float}|null
     */
    public function coordinates(string $text): ?array
    {
        // Degrees, minutes, seconds as Google Maps shows them: 6°58'00.0"S 109°40'00.0"E
        if (preg_match_all('/(\d{1,3})°\s*(\d{1,2})[\'′]\s*([\d.]+)["″]?\s*([NSEW])/iu', $text, $dms, PREG_SET_ORDER) === 2) {
            $values = [];
            foreach ($dms as [, $deg, $min, $sec, $hemisphere]) {
                $value = $deg + $min / 60 + $sec / 3600;
                $values[strtoupper($hemisphere)] = in_array(strtoupper($hemisphere), ['S', 'W'], true) ? -$value : $value;
            }
            $latitude = $values['S'] ?? $values['N'] ?? null;
            $longitude = $values['E'] ?? $values['W'] ?? null;
            if ($latitude !== null && $longitude !== null && $this->isLatitude($latitude) && $this->isLongitude($longitude)) {
                return [round($latitude, 7), round($longitude, 7)];
            }
        }

        preg_match_all('/-?\d{1,3}[.,]\d{2,}/', $text, $matches);
        $numbers = array_map(function ($n) {
            $value = (float) str_replace(',', '.', $n);

            // Indonesia reaches only 6°N, so 6.5-11.5 is a southern latitude that lost its minus sign.
            return $value > 6.5 && $value <= 11.5 ? -$value : $value;
        }, $matches[0]);

        for ($i = 0; $i + 1 < count($numbers); $i++) {
            [$a, $b] = [$numbers[$i], $numbers[$i + 1]];
            if ($this->isLatitude($a) && $this->isLongitude($b)) {
                return [$a, $b];
            }
            if ($this->isLongitude($a) && $this->isLatitude($b)) {
                return [$b, $a];
            }
        }

        return null;
    }

    private function isLatitude(float $value): bool
    {
        return $value >= -11.5 && $value <= 6.5 && $value != 0.0;
    }

    private function isLongitude(float $value): bool
    {
        return $value >= 94.5 && $value <= 141.5;
    }

    private function clean(mixed $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value));
    }

    /**
     * The data rows of the export keyed by header, from the row holding "ID Pelanggan" onwards.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $file): array
    {
        $grid = $this->isHtml($file) ? $this->htmlGrid($file) : $this->spreadsheetGrid($file);

        foreach ($grid as $index => $cells) {
            $header = array_map(fn ($cell) => $this->clean($cell), $cells);
            if (in_array('ID Pelanggan', $header, true) && in_array('Nama Pelanggan', $header, true)) {
                $rows = [];
                foreach (array_slice($grid, $index + 1) as $cells) {
                    $cells = array_pad(array_slice($cells, 0, count($header)), count($header), null);
                    $rows[] = array_combine($header, $cells);
                }

                return $rows;
            }
        }

        throw new RuntimeException('Kolom "ID Pelanggan" tidak ditemukan di '.basename($file));
    }

    /** The billing system's ".xls" is really an HTML table. */
    private function isHtml(string $file): bool
    {
        $handle = fopen($file, 'rb');
        $start = ltrim((string) fread($handle, 512));
        fclose($handle);

        return str_starts_with($start, '<');
    }

    /**
     * Read with DOM rather than a spreadsheet reader, which would turn phone numbers into numbers
     * and lose their leading 0.
     *
     * @return list<list<string>>
     */
    private function htmlGrid(string $file): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<meta charset="utf-8">'.file_get_contents($file));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $grid = [];
        foreach ($document->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array($cell->tagName, ['td', 'th'], true)) {
                    $cells[] = $cell->textContent;
                }
            }
            $grid[] = $cells;
        }

        return $grid;
    }

    /** @return list<list<mixed>> */
    private function spreadsheetGrid(string $file): array
    {
        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);

        return $reader->load($file)->getActiveSheet()->toArray(null, false, false, false);
    }
}
