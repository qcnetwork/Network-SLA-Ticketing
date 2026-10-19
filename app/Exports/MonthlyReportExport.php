<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Two-sheet workbook: KPI summary and the full ticket list for the month.
 */
class MonthlyReportExport implements Export, WithMultipleSheets
{
    public function __construct(private array $report) {}

    public function sheets(): array
    {
        return [
            new MonthlySummarySheet($this->report),
            new MonthlyTicketsSheet($this->report),
        ];
    }
}
