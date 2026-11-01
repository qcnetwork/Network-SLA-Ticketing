<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketShare;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TicketShareController extends Controller
{
    /**
     * Give one technician read-only access to the ticket for a few days. Sharing again with the
     * same technician moves the expiry instead of adding a second share.
     */
    public function store(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('share', $ticket);
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'days' => ['required', 'integer', Rule::in(TicketShare::DURATIONS)],
        ]);

        $technician = User::technicians()->find($data['user_id']);
        if (! $technician) {
            throw ValidationException::withMessages(['user_id' => 'Pilih teknisi yang aktif']);
        }

        $share = $ticket->shares()->updateOrCreate(['user_id' => $technician->id], [
            'shared_by_id' => $request->user()->id,
            'expires_at' => now()->addDays((int) $data['days']),
        ]);
        Log::info('Ticket shared', ['ticket' => $ticket->ticket_number, 'technician' => $technician->username, 'by' => $request->user()->username, 'until' => $share->expires_at->toDateTimeString()]);

        $technician->notify(new InAppNotification(
            "{$ticket->ticket_number} · {$ticket->customer_name}",
            "{$request->user()->name} membagikan arsip tiket ini kepada Anda sampai {$share->expires_at->format('d/m/Y H:i')}.",
            route('tickets.show', $ticket, absolute: false),
            'archive',
        ));

        return back()->with('success', "Tiket dibagikan ke {$technician->name} sampai {$share->expires_at->format('d/m/Y H:i')}");
    }

    public function destroy(Ticket $ticket, TicketShare $share): RedirectResponse
    {
        $this->authorize('share', $ticket);
        $share->delete();

        return back()->with('success', "Akses {$share->user?->name} ke tiket ini dicabut");
    }
}
