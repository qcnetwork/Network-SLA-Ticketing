<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Incident;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Each kanban column is paged on its own so a busy column does not stretch the page.
     */
    public const COLUMN_PER_PAGE = 10;

    public const TABLE_PER_PAGE = 25;

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $filters = $request->validate([
            'status' => ['nullable', 'string'],
            'priority' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:100'],
            'view' => ['nullable', 'in:kanban,table'],
        ]);
        // Technicians only have the repair column, so a status filter makes no sense for them.
        $isTechnician = $user->role === Role::TEKNISI;
        if ($isTechnician) {
            unset($filters['status']);
        }
        $boardStatuses = $isTechnician ? [TicketStatus::PERBAIKAN] : TicketStatus::cases();

        $counts = Ticket::query()->visibleTo($user)->withoutStaleClosed()
            ->selectRaw('status, count(*) as total')->groupBy('status')
            ->pluck('total', 'status');

        $viewMode = $filters['view'] ?? 'kanban';
        // Long-closed tickets stay out of the lists, but a search still finds them.
        $query = fn () => Ticket::query()->visibleTo($user)
            ->filter($filters)
            ->when(empty($filters['search']), fn ($query) => $query->withoutStaleClosed())
            ->with(['technicians', 'helpdesk'])
            ->latest('updated_at');

        $tickets = null;
        $columns = collect();
        if ($viewMode === 'table') {
            $tickets = $query()->paginate(self::TABLE_PER_PAGE)->withQueryString();
            $shown = $tickets->total();
        } else {
            $columns = collect($boardStatuses)->mapWithKeys(fn (TicketStatus $status) => [
                $status->value => $query()->where('status', $status)
                    ->paginate(self::COLUMN_PER_PAGE, pageName: 'page_'.strtolower($status->value))
                    ->withQueryString(),
            ]);
            $shown = $columns->sum->total();
        }

        return view('dashboard', [
            'openIncidents' => Incident::query()->open()->visibleTo($user)->withCount('tickets')->latest()->get(),
            'tickets' => $tickets,
            'columns' => $columns,
            'shown' => $shown,
            'counts' => $counts,
            'total' => $counts->sum(),
            'filters' => $filters,
            'viewMode' => $viewMode,
            'isTechnician' => $isTechnician,
            'boardStatuses' => $boardStatuses,
            'statuses' => TicketStatus::cases(),
            'priorities' => TicketPriority::cases(),
        ]);
    }
}
