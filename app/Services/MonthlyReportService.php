<?php

namespace App\Services;

use App\Enums\TicketAction;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketHistory;
use Illuminate\Support\Carbon;

class MonthlyReportService
{
    public const MONTH_NAMES = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    /**
     * Who closed a ticket is whoever did its last resolving step; the customer's confirmation
     * (or the automatic close) only seals it.
     */
    private const RESOLVED_BY = [
        'SELESAI_EDUKASI_CS' => 'closed_cs',
        'SELESAI_REMOTE' => 'closed_helpdesk',
        'LAPOR_PERBAIKAN_SELESAI' => 'closed_teknisi',
        'GAMAS_SELESAI' => 'closed_gamas',
    ];

    public const CLOSURE_LABELS = [
        'closed_cs' => 'Ditutup oleh CS (edukasi)',
        'closed_helpdesk' => 'Ditutup oleh Helpdesk (remote)',
        'closed_teknisi' => 'Ditutup oleh Teknisi (perbaikan lapangan)',
        'closed_gamas' => 'Ditutup lewat Gamas',
        'closed_other' => 'Ditutup (lainnya)',
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
            ->select('tickets.*')
            ->addSelect(['resolution_action' => TicketHistory::query()->select('action')
                ->whereColumn('ticket_id', 'tickets.id')
                ->whereIn('action', array_keys(self::RESOLVED_BY))
                ->orderByDesc('id')->limit(1)])
            ->with(['technicians', 'helpdesk'])
            ->withExists(['histories as was_rechecked' => fn ($query) => $query->where('action', TicketAction::KONFIRMASI_TERKENDALA)])
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
            $t->status === TicketStatus::TUTUP => self::RESOLVED_BY[$t->resolution_action] ?? 'closed_other',
            $t->status === TicketStatus::MENUNGGU_KONFIRMASI => 'pending',
            default => 'in_progress',
        });
        $closure = collect(self::CLOSURE_LABELS)
            ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label, 'count' => $closureCounts[$key] ?? 0, 'percentage' => $percent($closureCounts[$key] ?? 0)])
            // "Lainnya" only appears when some closed ticket has no resolving step on record.
            ->reject(fn (array $row) => $row['key'] === 'closed_other' && $row['count'] === 0)
            ->values();

        return [
            'year' => $year,
            'month' => $month,
            'total' => $total,
            'closed' => $closed,
            'in_progress' => $total - $closed,
            'remote_resolved' => $tickets->filter(fn (Ticket $t) => filled($t->remote_resolution_notes))->count(),
            'technician_assigned' => $tickets->filter(fn (Ticket $t) => $t->technicians->isNotEmpty())->count(),
            'rechecked' => $tickets->where('was_rechecked', true)->count(),
            'resolution_rate' => $percent($closed),
            'categories' => $categories,
            'closure' => $closure,
            'pending' => $closureCounts['pending'] ?? 0,
            'priorities' => $priorities,
            'technicians' => $technicians,
            'tickets' => $tickets,
        ];
    }
}
