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
            // New installation (PSB).
            TicketStatus::PSB_CEK_LOKASI => $this->toRole(Role::HELPDESK, $notice("Order pasang baru{$who}, mohon cek lokasi.", 'map-pin'), $actor),
            TicketStatus::PSB_ANTRIAN_SURVEY => $this->toRole(Role::CS, $notice('Lokasi perlu disurvey dulu. Kabari calon pelanggan.', 'compass'), $actor),
            TicketStatus::PSB_SURVEY, TicketStatus::PSB_PEMASANGAN => $this->fieldVisit($ticket, $actor, $notice),
            TicketStatus::PSB_ANTRIAN_PASANG => $this->toRoles([Role::HELPDESK, Role::CS], $notice('Lokasi bisa dipasang, menunggu jadwal teknisi pasang.', 'hourglass'), $actor),
            TicketStatus::PSB_KONFIRMASI => $this->toRole(Role::CS, $notice('Pemasangan selesai, konfirmasi ke pelanggan apakah internet sudah aktif.', 'message-square'), $actor),
            TicketStatus::PSB_SELESAI => $this->toRole(Role::HELPDESK, $notice('Pelanggan baru sudah aktif.', 'check-circle-2'), $actor),
            TicketStatus::PSB_BATAL => $this->toRoles([Role::HELPDESK, Role::CS], $notice("PSB dibatalkan{$who}.", 'x-circle'), $actor),
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
        $task = match ($ticket->status) {
            TicketStatus::PSB_SURVEY => 'survey lokasi pasang baru',
            TicketStatus::PSB_PEMASANGAN => 'memasang pelanggan baru',
            default => 'memperbaiki tiket ini',
        };
        $this->send($technicians, new InAppNotification(
            "{$ticket->ticket_number} · {$ticket->customer_name}",
            "Anda ditugaskan {$task}".($ticket->customer_address ? ": {$ticket->customer_address}" : '.'),
            self::path('tickets.show', $ticket),
            'wrench',
        ), $actor);
    }

    /**
     * A note left on a ticket (Helpdesk info or CS urgency) reaches both desks and the ticket's
     * technicians. A technician who can no longer open the ticket (it left repair) is sent to the
     * dashboard instead of a page that would refuse them.
     */
    public function ticketNote(Ticket $ticket, string $notes, string $urgency, ?User $actor, string $from): void
    {
        $notice = fn (string $url) => new InAppNotification(
            "{$ticket->ticket_number} · {$ticket->customer_name}",
            "[{$urgency}] Info dari {$from}".($actor ? " ({$actor->name})" : '').": {$notes}",
            $url,
            in_array($urgency, ['MENDESAK', 'KRITIS'], true) ? 'siren' : 'message-square',
        );

        $this->send(User::query()->whereIn('role', [Role::HELPDESK, Role::CS])->where('active', true)->get(), $notice(self::path('tickets.show', $ticket)), $actor);

        [$canOpen, $cannotOpen] = $ticket->technicians->where('active', true)->partition(fn (User $technician) => $technician->can('view', $ticket));
        $this->send($canOpen, $notice(self::path('tickets.show', $ticket)), $actor);
        $this->send($cannotOpen, $notice(self::path('dashboard', [])), $actor);
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

    /**
     * A survey or installation crew was sent out: CS tells the customer, the crew gets its task.
     */
    private function fieldVisit(Ticket $ticket, ?User $actor, callable $notice): void
    {
        $what = $ticket->status === TicketStatus::PSB_SURVEY ? 'survey' : 'pasang';
        $this->toRole(Role::CS, $notice("Teknisi {$what} [".($ticket->technicianNames() ?: '-').'] ditugaskan. Kabari calon pelanggan.', 'wrench'), $actor);
        $this->techniciansAssigned($ticket, $ticket->technicians, $actor);
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
        $this->toRoles([$role], $notification, $actor);
    }

    /**
     * @param  list<Role>  $roles
     */
    private function toRoles(array $roles, InAppNotification $notification, ?User $actor): void
    {
        $this->send(User::query()->whereIn('role', $roles)->where('active', true)->get(), $notification, $actor);
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
