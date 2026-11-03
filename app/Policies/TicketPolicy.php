<?php

namespace App\Policies;

use App\Enums\Role;
use App\Enums\TicketAction;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function view(User $user, Ticket $ticket): bool
    {
        if ($user->role === Role::TEKNISI) {
            return $this->worksOn($user, $ticket) || $ticket->isSharedWith($user) || $this->resubmitMaterial($user, $ticket);
        }
        if ($user->role === Role::GUDANG) {
            return $ticket->materialRequests()->exists();
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(...TicketAction::TIKET_DIBUAT->allowedRoles());
    }

    public function perform(User $user, Ticket $ticket, TicketAction $action): bool
    {
        // A ticket shared through the archive is read-only: only the assigned crew acts on it.
        $mayAct = $user->role === Role::TEKNISI ? $this->worksOn($user, $ticket) : true;

        return $mayAct && $user->hasRole(...$action->allowedRoles());
    }

    /**
     * Who may give a technician temporary access to a ticket.
     */
    public function share(User $user, Ticket $ticket): bool
    {
        return $user->hasRole(Role::HELPDESK, Role::SPV);
    }

    /**
     * Who may send material again after the warehouse rejected it: the crew, or Helpdesk on their behalf.
     */
    public function resubmitMaterial(User $user, Ticket $ticket): bool
    {
        if (! $user->hasRole(...TicketAction::MATERIAL_DIAJUKAN_ULANG->allowedRoles()) || ! $ticket->awaitsMaterialResubmission()) {
            return false;
        }

        return $user->role !== Role::TEKNISI || $ticket->isAssignedTo($user);
    }

    private function worksOn(User $user, Ticket $ticket): bool
    {
        return $ticket->status === TicketStatus::PERBAIKAN && $ticket->isAssignedTo($user);
    }

    public function contactCustomer(User $user, Ticket $ticket): bool
    {
        return $user->hasRole(Role::CS, Role::HELPDESK, Role::SPV);
    }

    /**
     * Deleting wipes the ticket's history and photos for good, so only supervisors may do it.
     */
    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->role === Role::SPV;
    }
}
