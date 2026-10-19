<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Text cells are written as plain strings so a name like "=HYPERLINK(...)" never runs as a formula.
 */
class MonthlySummarySheet extends StringValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithStyles, WithTitle
{
    /** Rows that hold section headings, styled in styles(). */
    private array $headingRows = [];

    public function __construct(private array $report)
    {
        $this->setNumericConversion(false);
    }

    public function title(): string
    {
        return $this->report['period'] === 'daily' ? 'Ringkasan Harian' : 'Ringkasan Bulanan';
    }

    public function array(): array
    {
        $r = $this->report;
        $rows = [
            [($r['period'] === 'daily' ? 'LAPORAN HARIAN' : 'LAPORAN BULANAN').' SISTEM TICKETING HELPDESK & CS'],
            ["Periode: {$r['period_label']}"],
            [],
            ['Indikator KPI', 'Nilai'],
            ['Total Tiket Masuk', $r['total']],
            ['Tiket Selesai (Tutup)', $r['closed']],
            ['Tiket Masih Berjalan', $r['in_progress']],
            ['Diselesaikan Remote', $r['remote_resolved']],
            ['Ditangani Teknisi', $r['technician_assigned']],
            ['Sempat Terkendala (Cek Ulang)', $r['rechecked']],
            ['Resolution Rate', $r['resolution_rate'].'%'],
            [],
            ['Kategori Gangguan', 'Jumlah', 'Persentase'],
        ];
        $this->headingRows = [4, 13];

        foreach ($r['categories'] as $category) {
            $rows[] = [$category['category'], $category['count'], $category['percentage'].'%'];
        }

        $rows[] = [];
        $rows[] = ['Kinerja Teknisi', 'Jumlah Tiket'];
        $this->headingRows[] = count($rows);
        foreach ($r['technicians'] as $technician) {
            $rows[] = [$technician['name'], $technician['count']];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): ?array
    {
        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            2 => ['font' => ['italic' => true]],
        ];
        foreach ($this->headingRows as $row) {
            $styles[$row] = [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['argb' => 'FF1E3A8A']],
            ];
        }

        return $styles;
    }
}
