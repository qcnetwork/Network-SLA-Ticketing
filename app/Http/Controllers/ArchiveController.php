<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Every ticket ever made, closed ones included, for looking up past cases. Helpdesk and
 * supervisors search all of it; a technician only sees the tickets shared with them.
 */
class ArchiveController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['ALL', 'OPEN', 'TUTUP'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $isTechnician = $user->role === Role::TEKNISI;

        $tickets = Ticket::query()
            ->with('technicians')
            ->when($isTechnician, fn (Builder $query) => $query->sharedWith($user)
                ->with(['shares' => fn ($share) => $share->active()->where('user_id', $user->id)]))
            ->when(filled($filters['q'] ?? null), fn (Builder $query) => $this->search($query, trim($filters['q'])))
            ->when(($filters['status'] ?? 'ALL') === 'OPEN', fn (Builder $query) => $query->where('status', '!=', TicketStatus::TUTUP))
            ->when(($filters['status'] ?? 'ALL') === 'TUTUP', fn (Builder $query) => $query->where('status', TicketStatus::TUTUP))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->whereDate('created_at', '<=', $to))
            ->latest('created_at')->latest('id')
            ->paginate(25)->withQueryString();

        return view('archive.index', [
            'tickets' => $tickets,
            'filters' => $filters + ['q' => '', 'status' => 'ALL', 'from' => '', 'to' => ''],
            'isTechnician' => $isTechnician,
        ]);
    }

    /**
     * Match the ticket number, customer name, or customer ID. % and _ are matched literally.
     */
    private function search(Builder $query, string $term): void
    {
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        $query->where(fn (Builder $match) => $match
            ->whereRaw("ticket_number like ? escape '!'", [$like])
            ->orWhereRaw("customer_name like ? escape '!'", [$like])
            ->orWhereRaw("customer_id like ? escape '!'", [$like]));
    }
}
