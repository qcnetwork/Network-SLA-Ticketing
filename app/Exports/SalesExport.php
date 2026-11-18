<?php

namespace App\Exports;

use App\Models\Sale;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The sales log, one row per item sold. Typed text stays plain strings (never a formula); prices stay numbers so they add up.
 */
class SalesExport extends StringValueBinder implements FromArray, ShouldAutoSize, WithColumnFormatting, WithCustomValueBinder, WithStyles, WithTitle
{
    /**
     * @param  Collection<int, Sale>  $sales
     */
    public function __construct(private Collection $sales)
    {
        $this->setNumericConversion(false);
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function title(): string
    {
        return 'Penjualan';
    }

    public function array(): array
    {
        $rows = [['No. Penjualan', 'Tanggal', 'Pembeli', 'Jenis Pembeli', 'Kode Barang', 'Nama Barang', 'SN', 'Jumlah', 'Satuan', 'Harga Satuan', 'Subtotal', 'Total Nota', 'Status Bayar', 'Tanggal Lunas', 'Dicatat oleh', 'Keterangan']];

        foreach ($this->sales as $sale) {
            foreach ($sale->lines as $line) {
                $rows[] = [
                    $sale->sale_number,
                    $sale->sold_at->format('d/m/Y'),
                    $sale->buyer_name,
                    $sale->partner_id ? 'Mitra' : 'Non-mitra',
                    $line->item->code,
                    $line->item->name,
                    $sale->serialUnits->where('item_id', $line->item_id)->pluck('serial_number')->implode(', ') ?: '-',
                    $line->quantity,
                    $line->item->unit,
                    $line->unit_price,
                    $line->subtotal,
                    $sale->total,
                    $sale->payment_status->label(),
                    $sale->paid_at?->format('d/m/Y') ?? '-',
                    $sale->user?->name ?? '-',
                    $sale->notes ?? '',
                ];
            }
        }

        return $rows;
    }

    public function columnFormats(): array
    {
        return ['J' => '#,##0', 'K' => '#,##0', 'L' => '#,##0'];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
