<?php

namespace App\Exports;

use App\Models\Ticket;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Customer-entered text is written as plain strings so a value like "=HYPERLINK(...)" never runs as a formula.
 */
class MonthlyTicketsSheet extends StringValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithStyles, WithTitle
{
    public function __construct(private array $report)
    {
        $this->setNumericConversion(false);
    }

    public function title(): string
    {
        return 'Data Tiket Lengkap';
    }

    public function array(): array
    {
        $rows = [[
            'No. Tiket', 'Tanggal Masuk', 'Pelanggan', 'ID Pelanggan', 'WhatsApp', 'Alamat', 'Kategori',
            'Prioritas', 'Kendala', 'Modem', 'Status', 'Helpdesk', 'Teknisi', 'Solusi', 'Tanggal Selesai',
        ]];

        foreach ($this->report['tickets'] as $ticket) {
            /** @var Ticket $ticket */
            $rows[] = [
                $ticket->ticket_number,
                $ticket->created_at->format('d/m/Y H:i'),
                $ticket->customer_name,
                $ticket->customer_id,
                $ticket->customer_phone,
                $ticket->customer_address,
                $ticket->category,
                $ticket->priority->label(),
                $ticket->complaint,
                $ticket->modem ?? '-',
                $ticket->status->label(),
                $ticket->helpdesk?->name ?? '-',
                $ticket->technicianNames() ?: '-',
                $ticket->technician_notes ?? $ticket->remote_resolution_notes ?? $ticket->confirmation_notes ?? '-',
                $ticket->closed_at?->format('d/m/Y H:i') ?? '-',
            ];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): ?array
    {
        return [1 => [
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['argb' => 'FF1E3A8A']],
        ]];
    }
}
