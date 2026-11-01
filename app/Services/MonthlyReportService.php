<?php

namespace App\Services;

use App\Enums\TicketAction;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Support\Carbon;

class MonthlyReportService
{
    public const MONTH_NAMES = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    /**
     * A closed ticket counts for the furthest team it reached: any ticket sent to a technician is the
     * technicians', one Helpdesk checked without needing a visit is Helpdesk's, and one Helpdesk never
     * checked was settled by CS. A gamas only counts when the ticket reached neither technician nor Helpdesk.
     */
    public const CLOSURE_LABELS = [
        'closed_cs' => 'Ditutup oleh CS (tanpa dicek Helpdesk)',
        'closed_helpdesk' => 'Ditutup oleh Helpdesk (dicek HD, tanpa teknisi)',
        'closed_teknisi' => 'Ditutup oleh Teknisi (sempat ditangani teknisi)',
        'closed_gamas' => 'Ditutup lewat Gamas',
        'pending' => 'Pending: menunggu respon pelanggan',
        'in_progress' => 'Masih diproses',
    ];

    /**
     * KPI summary for tickets created in the given month.
     */
    public function build(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();

        return $this->summarize($start, $start->copy()->endOfMonth()) + [
            'period' => 'monthly',
            'period_label' => self::MONTH_NAMES[$month].' '.$year,
        ];
    }

    /**
     * KPI summary for tickets created on the given day.
     */
    public function buildDaily(Carbon $date): array
    {
        $start = $date->copy()->startOfDay();

        return $this->summarize($start, $start->copy()->endOfDay()) + [
            'period' => 'daily',
            'period_label' => $start->day.' '.self::MONTH_NAMES[$start->month].' '.$start->year,
            'date' => $start->toDateString(),
        ];
    }

    private function summarize(Carbon $start, Carbon $end): array
    {
        $year = $start->year;
        $month = $start->month;
        $tickets = Ticket::query()
            ->with(['technicians', 'helpdesk'])
            ->withExists([
                'histories as was_rechecked' => fn ($query) => $query->where('action', TicketAction::KONFIRMASI_TERKENDALA),
                'histories as reached_technician' => fn ($query) => $query->where('action', TicketAction::PERLU_TEKNISI),
                'histories as reached_helpdesk' => fn ($query) => $query->where('action', TicketAction::KLAIM_HELPDESK),
                'histories as resolved_by_gamas' => fn ($query) => $query->where('action', TicketAction::GAMAS_SELESAI),
            ])
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at')
            ->get();

        $total = $tickets->count();
        $closed = $tickets->where('status', TicketStatus::TUTUP)->count();
        $percent = fn (int $count) => $total > 0 ? (int) round($count / $total * 100) : 0;

        $categories = $tickets->countBy('category')->sortDesc()
            ->map(fn ($count, $category) => ['category' => $category, 'count' => $count, 'percentage' => $percent($count)])
            ->values();

        $technicians = $tickets->flatMap(fn (Ticket $t) => $t->technicians->pluck('name'))
            ->countBy()->sortDesc()
            ->map(fn ($count, $name) => ['name' => $name, 'count' => $count])
            ->values();

        $priorities = collect(TicketPriority::cases())
            ->mapWithKeys(fn (TicketPriority $p) => [$p->value => $tickets->where('priority', $p)->count()]);

        // Every ticket lands in exactly one group, so the percentages add up to the whole period.
        $closureCounts = $tickets->countBy(fn (Ticket $t) => match (true) {
            $t->status === TicketStatus::MENUNGGU_KONFIRMASI => 'pending',
            $t->status !== TicketStatus::TUTUP => 'in_progress',
            (bool) $t->reached_technician => 'closed_teknisi',
            (bool) $t->reached_helpdesk => 'closed_helpdesk',
            (bool) $t->resolved_by_gamas => 'closed_gamas',
            default => 'closed_cs',
        });
        $closure = collect(self::CLOSURE_LABELS)
            ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label, 'count' => $closureCounts[$key] ?? 0, 'percentage' => $percent($closureCounts[$key] ?? 0)])
            ->values();

        $counts = [
            'closed' => $closed,
            'in_progress' => $total - $closed,
            'pending' => $closureCounts['pending'] ?? 0,
            'remote_resolved' => $tickets->filter(fn (Ticket $t) => filled($t->remote_resolution_notes))->count(),
            'technician_assigned' => $tickets->filter(fn (Ticket $t) => $t->technicians->isNotEmpty())->count(),
            'rechecked' => $tickets->where('was_rechecked', true)->count(),
        ];

        return $counts + [
            'year' => $year,
            'month' => $month,
            'total' => $total,
            // Each KPI as a share of the period's tickets, for the report cards and the Excel summary.
            'percentages' => array_map($percent, $counts),
            'resolution_rate' => $percent($closed),
            'categories' => $categories,
            'closure' => $closure,
            'priorities' => $priorities,
            'technicians' => $technicians,
            'tickets' => $tickets,
        ];
    }
}
