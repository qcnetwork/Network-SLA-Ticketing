<?php

namespace App\Exports;

use App\Models\RemovedDevice;
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
 * Laporan Alat Cabutan as a sheet, one row per device. SNs and customer IDs stay text.
 */
class RemovedDeviceExport extends StringValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithStyles, WithTitle
{
    /**
     * @param  Collection<int, RemovedDevice>  $devices
     */
    public function __construct(private Collection $devices)
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

    public function array(): array
    {
        $date = fn ($value) => $value?->format('d/m/Y H:i') ?? '';

        return [
            ['Tanggal Cabut', 'No. Tiket', 'ID Pelanggan', 'Nama Pelanggan', 'Teknisi', 'Alat', 'SN', 'MAC', 'Jumlah', 'Kondisi (teknisi)', 'Catatan',
                'Status', 'Batas Serah', 'Terlambat', 'Diterima', 'Diterima Oleh', 'Dicek', 'Dicek Oleh', 'Masuk Stok Sebagai', 'Catatan Gudang'],
            ...$this->devices->map(fn (RemovedDevice $device) => [
                $date($device->created_at),
                $device->ticket?->ticket_number ?? '',
                $device->customer_code ?? '',
                $device->customer_name ?? '',
                $device->technician?->name ?? '',
                $device->name,
                $device->serial_number ?? '',
                $device->formattedMac() ?? '',
                $device->quantity,
                $device->fieldConditionLabel(),
                $device->notes ?? '',
                $device->status->label(),
                $date($device->due_at),
                $device->isOverdue() ? 'Ya' : '',
                $date($device->received_at),
                $device->receiver?->name ?? '',
                $date($device->checked_at),
                $device->checker?->name ?? '',
                $device->item ? "{$device->item->code} {$device->item->name}" : '',
                $device->check_notes ?? '',
            ])->all(),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }

    public function title(): string
    {
        return 'Alat Cabutan';
    }
}
