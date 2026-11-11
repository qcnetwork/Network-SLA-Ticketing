<?php

namespace App\Http\Controllers;

use App\Enums\Menu;
use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        return view('customers.index', [
            'search' => $search,
            'customers' => Customer::query()
                ->when($search !== '', fn ($query) => $query->search($search))
                ->withCount('tickets')
                ->withMax('tickets', 'created_at')
                ->orderByDesc('updated_at')
                ->paginate(25)
                ->withQueryString(),
        ]);
    }

    /**
     * Matches for the ticket form's customer picker, with what CS should know before opening another
     * ticket: how often the customer complained and whether a ticket of theirs is still open.
     */
    public function search(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canAccess(Menu::CUSTOMERS) || $user->can('create', Ticket::class), 403);

        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        $customers = Customer::query()->search($term)
            ->withCount('tickets')
            // An exact ID first, then whoever was in touch most recently.
            ->orderByRaw('case when lower(code) = ? then 0 else 1 end', [mb_strtolower($term)])
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get();

        $openTickets = Ticket::query()
            ->whereIn('customer_id', $customers->pluck('code'))
            ->where('status', '!=', TicketStatus::TUTUP)
            ->latest('id')
            ->get(['ticket_number', 'customer_id', 'status'])
            ->keyBy('customer_id');

        return response()->json($customers->map(fn (Customer $customer) => [
            'code' => $customer->code,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'address' => $customer->address,
            'tickets' => $customer->tickets_count,
            'open_ticket' => ($open = $openTickets->get($customer->code)) ? [
                'number' => $open->ticket_number,
                'status' => $open->status->label(),
                'url' => route('tickets.show', $open, absolute: false),
            ] : null,
        ]));
    }
}
