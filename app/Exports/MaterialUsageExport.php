<?php

namespace App\Exports;

use App\Models\MaterialUsage;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The usage log in the columns of the office's old "Laporan Pemasangan" sheet, one row per item.
 */
class MaterialUsageExport extends StringValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithStyles, WithTitle
{
    /**
     * @param  Collection<int, MaterialUsage>  $usages
     */
    public function __construct(private Collection $usages)
    {
        $this->setNumericConversion(false);
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_int($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function title(): string
    {
        return 'Pemakaian Alat';
    }

    public function array(): array
    {
        $rows = [['NO', 'TANGGAL', 'TEKNISI', 'PROJECT', 'NAMA BARANG', 'REPORT', 'QTY', 'STOK TEKNISI', 'CATATAN', 'SN', 'MAC', 'LOKASI PEMASANGAN', 'ALAT CABUTAN', 'CATATAN TAMBAHAN', 'NO. TIKET']];
        $number = 0;

        foreach ($this->usages as $usage) {
            foreach ($usage->lines as $line) {
                $rows[] = [
                    ++$number,
                    $usage->used_at->format('d/m/Y'),
                    $usage->team ?? '',
                    $usage->purpose->label(),
                    $line->item_name,
                    $usage->project ?? '',
                    $line->quantity,
                    $line->warehouse?->name ?? $usage->holder_name ?? '',
                    $line->notes ?? '',
                    $line->serial_number ?? '',
                    $line->formattedMac() ?? '',
                    $usage->location,
                    $usage->removed_device ?? '',
                    $usage->notes ?? '',
                    $usage->ticket?->ticket_number ?? '',
                ];
            }
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
