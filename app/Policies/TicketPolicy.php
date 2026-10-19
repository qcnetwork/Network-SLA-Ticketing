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
            return $ticket->status === TicketStatus::PERBAIKAN && $ticket->isAssignedTo($user);
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(...TicketAction::TIKET_DIBUAT->allowedRoles());
    }

    public function perform(User $user, Ticket $ticket, TicketAction $action): bool
    {
        return $this->view($user, $ticket) && $user->hasRole(...$action->allowedRoles());
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
