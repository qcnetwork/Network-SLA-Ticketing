<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Incident;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Each kanban column is paged on its own so a busy column does not stretch the page.
     */
    public const COLUMN_PER_PAGE = 10;

    public const TABLE_PER_PAGE = 25;

    /**
     * The columns the "Fokus" preset keeps for each role: the nodes that role acts on or follows up.
     * Supervisors oversee everything, so their focus is the whole board.
     */
    public const ROLE_FOCUS = [
        'CS' => ['PROSES_CS', 'ANTRIAN_TEKNISI', 'PERBAIKAN', 'MENUNGGU_KONFIRMASI'],
        'HELPDESK' => ['ANTRIAN_HELPDESK', 'HELPDESK_CEK', 'ANTRIAN_TEKNISI', 'PERBAIKAN', 'GAMAS', 'CEK_ULANG'],
    ];

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
        $summaryStatuses = $isTechnician ? [TicketStatus::PERBAIKAN] : TicketStatus::cases();
        // Hidden columns are skipped entirely, so their tickets are not even queried. A column picked
        // through the status filter (e.g. by clicking its summary card) still shows.
        $hidden = array_diff($user->hidden_board_statuses ?? [], [$filters['status'] ?? null]);
        $boardStatuses = array_values(array_filter($summaryStatuses, fn (TicketStatus $status) => ! in_array($status->value, $hidden, true)));

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
            'summaryStatuses' => $summaryStatuses,
            'boardStatuses' => $boardStatuses,
            'hiddenStatuses' => $user->hidden_board_statuses ?? [],
            'focusStatuses' => self::ROLE_FOCUS[$user->role->value] ?? null,
            'statuses' => TicketStatus::cases(),
            'priorities' => TicketPriority::cases(),
        ]);
    }

    /**
     * Save which kanban columns this user sees: a hand-picked set, their role's focus, or all of them.
     */
    public function columns(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->role === Role::TEKNISI, 403);
        $all = array_map(fn (TicketStatus $status) => $status->value, TicketStatus::cases());

        $data = $request->validate([
            'preset' => ['nullable', 'in:all,focus'],
            'visible' => ['nullable', 'array'],
            'visible.*' => ['string', Rule::in($all)],
        ]);

        $visible = match ($data['preset'] ?? null) {
            'all' => $all,
            'focus' => self::ROLE_FOCUS[$user->role->value] ?? $all,
            default => $data['visible'] ?? [],
        };
        if ($visible === []) {
            throw ValidationException::withMessages(['visible' => 'Pilih minimal satu kolom untuk ditampilkan']);
        }

        $hidden = array_values(array_diff($all, $visible));
        $user->forceFill(['hidden_board_statuses' => $hidden ?: null])->save();

        return redirect()->route('dashboard', $request->only(['priority', 'search']))
            ->with('success', $hidden ? count($hidden).' kolom disembunyikan dari papan kanban' : 'Semua kolom ditampilkan');
    }
}
