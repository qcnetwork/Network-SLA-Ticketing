<?php

namespace App\Console\Commands;

use App\Enums\UsagePurpose;
use App\Models\Item;
use App\Models\MaterialUsage;
use App\Models\SerialUnit;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Bring rows of the office's "Laporan Pemasangan" sheet into Pemakaian Alat as history. Stock is never
 * touched: those items left the warehouse long ago, and today's counts already reflect it. Rows of the same
 * job (date, crew, purpose, place) become one usage; importing the same rows again adds nothing.
 */
class ImportMaterialUsage extends Command
{
    protected $signature = 'gudang:impor-pemakaian {file : File Excel kertas kerja pemasangan} {--limit=100 : Ambil N baris terakhir (0 = semua)}';

    protected $description = 'Impor riwayat Pemakaian Alat dari sheet "PEMASANGAN ALAT" (tanpa mengubah stok)';

    private const HEADERS = ['TANGGAL', 'TEKNISI', 'PROJECT', 'NAMA BARANG', 'QTY', 'LOKASI PEMASANGAN'];

    public function handle(): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $this->error("File tidak ditemukan: {$path}");

            return self::FAILURE;
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        [$sheet, $columns, $headerRow] = $this->findSheet($reader->load($path)) ?? [null, null, null];
        if (! $sheet) {
            $this->error('Sheet dengan kolom '.implode(', ', self::HEADERS).' tidak ditemukan');

            return self::FAILURE;
        }
        $this->info("Sheet: {$sheet->getTitle()}");

        $rows = $this->rows($sheet, $columns, $headerRow);
        $limit = (int) $this->option('limit');
        // The latest rows: by date, keeping the sheet's order within a day.
        $rows = $rows->sortBy(fn ($row) => sprintf('%s %06d', $row['date']->toDateString(), $row['row']))->values();
        if ($limit > 0) {
            $rows = $rows->slice(-$limit)->values();
        }

        $items = Item::query()->get()->keyBy(fn (Item $item) => $this->key($item->name));
        $created = $skipped = 0;

        DB::transaction(function () use ($rows, $items, &$created, &$skipped) {
            $rows->groupBy(fn ($row) => implode('|', [$row['date']->toDateString(), $row['team'], $row['purpose'], $row['project'], $row['location'], $row['holder']]))
                ->each(function ($group, $groupKey) use ($items, &$created, &$skipped) {
                    $first = $group->first();
                    $importKey = hash('sha256', $groupKey.'|'.$group->pluck('row')->implode(','));
                    if (MaterialUsage::query()->where('import_key', $importKey)->exists()) {
                        $skipped++;

                        return;
                    }
                    $usage = MaterialUsage::query()->create([
                        'used_at' => $first['date'],
                        'purpose' => UsagePurpose::fromSheet($first['purpose']),
                        'project' => $this->cut($first['project'], 100),
                        'location' => $this->cut($first['location'], 255) ?? '-',
                        'team' => $this->cut($first['team'], 150),
                        'holder_name' => $this->cut($first['holder'], 100),
                        'removed_device' => $this->cut($group->pluck('removed')->filter()->unique()->implode('; '), 255),
                        'notes' => $this->cut($group->pluck('extra')->filter()->unique()->implode('; '), 255),
                        'imported_at' => now(),
                        'import_key' => $importKey,
                    ]);
                    foreach ($group as $row) {
                        $sn = SerialUnit::normalizeSerial($row['sn']);
                        $usage->lines()->create([
                            'item_id' => $items[$this->key($row['item'])]->id ?? null,
                            'item_name' => $this->cut($row['item'], 150),
                            'serial_unit_id' => $sn !== '' ? SerialUnit::query()->where('serial_number', $sn)->value('id') : null,
                            'serial_number' => $sn !== '' ? mb_substr($sn, 0, 100) : null,
                            'mac' => $this->cut(preg_replace('/[^0-9A-Fa-f]/', '', (string) $row['mac']) ?: null, 20),
                            'quantity' => max(1, (int) $row['qty']),
                            'notes' => $this->cut($row['notes'], 255),
                        ]);
                    }
                    $created++;
                });
        });

        $this->info("{$rows->count()} baris dibaca: {$created} pemakaian baru, {$skipped} sudah pernah diimpor.");

        return self::SUCCESS;
    }

    /**
     * The first sheet whose header row carries every column we need, with where each column is.
     *
     * @return array{0: Worksheet, 1: array<string, string>, 2: int}|null
     */
    private function findSheet($book): ?array
    {
        foreach ($book->getWorksheetIterator() as $sheet) {
            $lastColumn = $sheet->getHighestDataColumn();
            for ($row = 1; $row <= min(20, $sheet->getHighestDataRow()); $row++) {
                $cells = $sheet->rangeToArray("A{$row}:{$lastColumn}{$row}", null, true, false, true)[$row];
                $columns = [];
                foreach ($cells as $column => $value) {
                    $label = strtoupper(trim((string) $value));
                    if ($label !== '' && ! isset($columns[$label])) {
                        $columns[$label] = $column;
                    }
                }
                if (count(array_intersect(self::HEADERS, array_keys($columns))) === count(self::HEADERS)) {
                    return [$sheet, $columns, $row];
                }
            }
        }

        return null;
    }

    private function rows(Worksheet $sheet, array $columns, int $headerRow)
    {
        // Several columns are formulas (=UPPER(P12)); read the value Excel saved for them.
        $get = function (int $row, string $label) use ($sheet, $columns) {
            if (! isset($columns[$label])) {
                return null;
            }
            $cell = $sheet->getCell($columns[$label].$row);

            return $cell->isFormula() ? ($cell->getOldCalculatedValue() ?? rescue(fn () => $cell->getCalculatedValue(), null, false)) : $cell->getValue();
        };
        $rows = collect();
        for ($row = $headerRow + 1; $row <= $sheet->getHighestDataRow(); $row++) {
            $date = $get($row, 'TANGGAL');
            $item = trim((string) $get($row, 'NAMA BARANG'));
            if ($item === '' || $date === null || $date === '') {
                continue;
            }
            $date = is_numeric($date) ? Carbon::instance(ExcelDate::excelToDateTimeObject($date)) : rescue(fn () => Carbon::parse($date), null, false);
            if (! $date) {
                continue;
            }
            $rows->push([
                'row' => $row,
                'date' => $date->startOfDay(),
                'team' => trim((string) $get($row, 'TEKNISI')),
                'purpose' => trim((string) $get($row, 'PROJECT')),
                'project' => trim((string) $get($row, 'REPORT')),
                'item' => $item,
                'qty' => $get($row, 'QTY'),
                'holder' => trim((string) $get($row, 'STOK TEKNISI')),
                'notes' => trim((string) $get($row, 'CATATAN')),
                'sn' => trim((string) $get($row, 'SN')),
                'mac' => trim((string) $get($row, 'MAC')),
                'location' => trim((string) $get($row, 'LOKASI PEMASANGAN')),
                'removed' => trim((string) $get($row, 'ALAT CABUTAN')),
                'extra' => trim((string) $get($row, 'CATATAN TAMBAHAN')),
            ]);
        }

        return $rows;
    }

    private function key(string $name): string
    {
        return preg_replace('/[^A-Z0-9]+/', '', strtoupper($name));
    }

    private function cut(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
