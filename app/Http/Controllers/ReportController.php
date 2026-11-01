<?php

namespace App\Http\Controllers;

use App\Exports\MonthlyReportExport;
use App\Models\TelegramLog;
use App\Models\Ticket;
use App\Services\ComplaintMapService;
use App\Services\MonthlyReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    public function __construct(private MonthlyReportService $reports) {}

    public function index(Request $request): View
    {
        return view('reports.index', [
            'report' => $this->report($request),
            'months' => MonthlyReportService::MONTH_NAMES,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $report = $this->report($request);
        $filename = $report['period'] === 'daily'
            ? 'Laporan_Ticketing_Harian_'.str_replace('-', '_', $report['date']).'.xlsx'
            : sprintf('Laporan_Ticketing_%d_%02d.xlsx', $report['year'], $report['month']);

        return Excel::download(new MonthlyReportExport($report), $filename);
    }

    /**
     * Heatmap of where complaints come from, over ?from=&to= (the last 90 days by default).
     */
    public function heatmap(Request $request, ComplaintMapService $map): View
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'category' => ['nullable', 'string', 'max:100'],
        ]);
        $to = isset($data['to']) ? Carbon::parse($data['to']) : now();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->subDays(89);

        return view('reports.heatmap', [
            'map' => $map->build($from, $to, $data['category'] ?? null),
            // From the tickets, so categories retired since still filter their old tickets.
            'categories' => Ticket::query()->distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    public function telegramLogs(): View
    {
        return view('telegram.logs', ['logs' => TelegramLog::query()->latest('id')->paginate(20)]);
    }

    /**
     * The daily report for ?period=daily&date=Y-m-d, otherwise the monthly report for ?year=&month=.
     */
    private function report(Request $request): array
    {
        $data = $request->validate([
            'period' => ['nullable', 'in:daily,monthly'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);

        if (($data['period'] ?? 'monthly') === 'daily') {
            return $this->reports->buildDaily(isset($data['date']) ? Carbon::parse($data['date']) : now());
        }

        return $this->reports->build((int) ($data['year'] ?? now()->year), (int) ($data['month'] ?? now()->month));
    }
}
