<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Incident;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Tells each role about the tickets that just reached the nodes it works on. Whoever made the
 * change is never notified about it.
 */
class TicketNotifier
{
    public function statusChanged(Ticket $ticket, ?User $actor): void
    {
        $who = $actor ? " oleh {$actor->name}" : '';
        $notice = fn (string $body, string $icon) => new InAppNotification(
            "{$ticket->ticket_number} · {$ticket->customer_name}", $body, self::path('tickets.show', $ticket), $icon,
        );

        match ($ticket->status) {
            // Tickets CS make themselves are already theirs; only ones made by Helpdesk or a supervisor need a heads-up.
            TicketStatus::PROSES_CS => $actor?->role === Role::CS ? null
                : $this->toRole(Role::CS, $notice("Tiket baru dibuat{$who}, menunggu diproses CS.", 'headset'), $actor),
            TicketStatus::ANTRIAN_HELPDESK => $this->toRole(Role::HELPDESK, $notice("Masuk Antrian Helpdesk{$who}.", 'clock'), $actor),
            TicketStatus::CEK_ULANG => $this->toRole(Role::HELPDESK, $notice('Pelanggan masih terkendala, perlu cek ulang.', 'refresh-cw'), $actor),
            TicketStatus::ANTRIAN_TEKNISI => $this->toRole(Role::CS, $notice('Perlu kunjungan teknisi, sedang menunggu jadwal. Kabari pelanggan.', 'hourglass'), $actor),
            TicketStatus::PERBAIKAN => $this->repairStarted($ticket, $actor, $notice),
            TicketStatus::MENUNGGU_KONFIRMASI => $this->toRole(Role::CS, $notice('Sudah diperbaiki, siap dikonfirmasi ke pelanggan.', 'message-square'), $actor),
            default => null,
        };
    }

    /**
     * The technicians just put on a ticket (a new assignment, or added during Ubah Teknisi).
     *
     * @param  Collection<int, User>  $technicians
     */
    public function techniciansAssigned(Ticket $ticket, Collection $technicians, ?User $actor): void
    {
        $this->send($technicians, new InAppNotification(
            "{$ticket->ticket_number} · {$ticket->customer_name}",
            'Anda ditugaskan memperbaiki tiket ini'.($ticket->customer_address ? ": {$ticket->customer_address}" : '.'),
            self::path('tickets.show', $ticket),
            'wrench',
        ), $actor);
    }

    public function gamasOpened(Incident $incident, ?User $actor): void
    {
        $this->toRole(Role::SPV, new InAppNotification(
            "Gamas {$incident->incident_number}",
            "Gangguan massal dibuka: {$incident->title} ({$incident->area}).",
            self::path('incidents.show', $incident),
            'radio-tower',
        ), $actor);
    }

    private function repairStarted(Ticket $ticket, ?User $actor, callable $notice): void
    {
        $technicians = $ticket->technicians;
        $this->toRole(Role::CS, $notice('Teknisi ['.($ticket->technicianNames() ?: '-').'] ditugaskan. Kabari pelanggan.', 'wrench'), $actor);
        $this->techniciansAssigned($ticket, $technicians, $actor);
    }

    /**
     * A relative link: notifications made by console commands would otherwise carry APP_URL,
     * which need not match the address people open the app on (e.g. the server's LAN IP).
     */
    private static function path(string $route, mixed $parameters): string
    {
        return route($route, $parameters, absolute: false);
    }

    private function toRole(Role $role, InAppNotification $notification, ?User $actor): void
    {
        $this->send(User::query()->where('role', $role)->where('active', true)->get(), $notification, $actor);
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function send(Collection $users, InAppNotification $notification, ?User $actor): void
    {
        $recipients = $users->reject(fn (User $user) => $actor && $user->is($actor));
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $notification);
        }
    }
}
