<?php

namespace App\Services;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Brings the main warehouse's stock in from the "Sisa Stok" sheet of the warehouse admin workbook:
 * a header row (KODE PRODUK, NAMA PRODUK, SATUAN, STOK MINIMUM) over STOK AWAL, MASUK and KELUAR per item.
 *
 * Each item is created or kept in the item master. A counted item's main-warehouse stock is set to
 * AWAL + MASUK - KELUAR (the sheet's own AKHIR column often holds a formula error). Devices are left at
 * zero: their stock is counted from scanned SNs, which this sheet does not list. Running it again sets
 * the same figures, so it only records movements for what changed since.
 */
class StockSheetImport
{
    public const SHEET = 'Sisa Stok';

    public function __construct(private InventoryService $inventory) {}

    /**
     * @return array{rows: int, created: list<string>, stocked: int, quantity: int, unchanged: int, empty: int, devices: list<string>, negative: list<string>}
     */
    public function import(string $path, User $actor, bool $dryRun = false): array
    {
        $rows = $this->read($path);
        $report = ['rows' => $rows->count(), 'created' => [], 'stocked' => 0, 'quantity' => 0, 'unchanged' => 0, 'empty' => 0, 'devices' => [], 'negative' => []];

        $apply = function () use ($rows, $actor, &$report) {
            foreach ($rows as $row) {
                $item = Item::query()->firstOrNew(['code' => $row['code']]);
                if (! $item->exists) {
                    $category = ItemCategory::guess($row['code'], $row['name']);
                    $item->fill([
                        'name' => mb_substr($row['name'], 0, 150),
                        'unit' => $row['unit'],
                        'min_stock' => $row['min'],
                        'item_category_id' => $category?->id,
                        'tracks_serial' => str_starts_with($row['code'], 'MDM') || (bool) $category?->requires_serial,
                        'active' => true,
                    ])->save();
                    $report['created'][] = $row['code'];
                }

                $quantity = $row['awal'] + $row['masuk'] - $row['keluar'];
                if ($quantity < 0) {
                    $report['negative'][] = "{$row['code']} {$row['name']} ({$quantity})";
                    $quantity = 0;
                }
                if ($item->tracks_serial) {
                    if ($quantity > 0) {
                        $report['devices'][] = "{$row['code']} {$row['name']} = {$quantity}";
                    }

                    continue;
                }
                $quantity > 0 ? $report['stocked']++ : $report['empty']++;
                $report['quantity'] += $quantity;
                if (! $this->inventory->setMainStock($item, $quantity, $actor, 'Stok awal dari sheet Sisa Stok') && $quantity > 0) {
                    $report['unchanged']++;
                }
            }
        };

        if ($dryRun) {
            DB::beginTransaction();
            try {
                $apply();
            } finally {
                DB::rollBack();
            }
        } else {
            DB::transaction($apply);
        }

        return $report;
    }

    /**
     * One row per item code under the header, with the cached values of the sheet's formulas.
     *
     * @return Collection<int, array{code: string, name: string, unit: string, min: int, awal: int, masuk: int, keluar: int}>
     */
    public function read(string $path): Collection
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([self::SHEET]);
        $sheet = $reader->load($path)->getSheetByName(self::SHEET)
            ?? throw new \RuntimeException('Sheet "'.self::SHEET.'" tidak ditemukan');

        $columns = null;
        $rows = collect();
        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            foreach ($row->getCellIterator('A', $sheet->getHighestDataColumn()) as $cell) {
                // Formulas keep the value the spreadsheet last saved; they reference other sheets.
                $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
                $cells[] = is_string($value) ? trim(preg_replace('/\s+/', ' ', $value)) : $value;
            }
            $upper = array_map(fn ($value) => strtoupper((string) $value), $cells);

            if (! $columns) {
                if (in_array('KODE', $upper, true) && in_array('NAMA PRODUK', $upper, true)) {
                    $code = array_search('KODE', $upper, true);
                    // STOK MINIMUM, AWAL, MASUK, KELUAR sit side by side after SATUAN.
                    $columns = ['code' => $code, 'name' => $code + 1, 'unit' => $code + 2, 'min' => $code + 3, 'awal' => $code + 4, 'masuk' => $code + 5, 'keluar' => $code + 6];
                }

                continue;
            }
            $code = strtoupper((string) ($cells[$columns['code']] ?? ''));
            $name = (string) ($cells[$columns['name']] ?? '');
            if ($code === '' || $code === 'PRODUK' || $name === '' || ! preg_match('/^[A-Z0-9][A-Z0-9\-_.]{1,29}$/', $code)) {
                continue;
            }
            $number = fn (string $key) => is_numeric($cells[$columns[$key]] ?? null) ? (int) round((float) $cells[$columns[$key]]) : 0;
            $rows->push([
                'code' => $code,
                'name' => strtoupper($name),
                'unit' => strtolower((string) ($cells[$columns['unit']] ?? '')) ?: 'pcs',
                'min' => max(0, $number('min')),
                'awal' => $number('awal'),
                'masuk' => $number('masuk'),
                'keluar' => $number('keluar'),
            ]);
        }
        $sheet->getParent()->disconnectWorksheets();

        return $rows->unique('code')->values();
    }
}
