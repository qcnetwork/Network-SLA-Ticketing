<?php

namespace App\Exports;

use App\Models\SerialUnit;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * SN units as the Cari SN page lists them, idle or used,, one row per SN. Every value is written as plain text, so an SN
 * like 48575443... never turns into a number and nothing typed in the sheet runs as a formula.
 */
class TrackingExport extends StringValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithStyles, WithTitle
{
    /**
     * @param  Collection<int, SerialUnit>  $units
     */
    public function __construct(private Collection $units)
    {
        $this->setNumericConversion(false);
    }

    public function title(): string
    {
        return 'Unit SN';
    }

    public function array(): array
    {
        $rows = [['SN', 'MAC', 'Kode Barang', 'Nama Barang', 'Jenis', 'Merk', 'Status', 'Tujuan', 'Dipasang di / Posisi', 'Teknisi / Pemegang', 'Tanggal Ambil', 'Idle Sejak', 'Lama Idle (hari)', 'Status Pemasangan', 'Alat Diganti', 'Keterangan', 'Tiket']];
        foreach ($this->units as $unit) {
            $idle = $unit->warehouse_id !== null;
            $rows[] = [
                $unit->serial_number,
                $unit->formattedMac() ?? '',
                $unit->item->code,
                $unit->item->name,
                $unit->item->variant ?? '',
                $unit->item->brand ?? '',
                $idle ? 'Idle' : 'Terpakai',
                SerialUnit::DESTINATIONS[$unit->destination()] ?? '',
                $unit->placeName(),
                ($idle ? $unit->warehouse?->user?->name : $unit->holder_name) ?? '',
                $idle ? '' : ($unit->taken_at?->format('Y-m-d') ?? ''),
                $idle ? ($unit->idleSince()?->format('Y-m-d') ?? '') : '',
                $idle ? (string) ($unit->idleDays() ?? '') : '',
                $idle ? '' : ($unit->install_status ?? ''),
                $idle ? '' : ($unit->replaced_device ?? ''),
                collect([$unit->condition && $unit->destination() === 'retur' ? $unit->condition : null, $unit->notes])->filter()->implode(' · '),
                $unit->ticket?->ticket_number ?? '',
            ];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
