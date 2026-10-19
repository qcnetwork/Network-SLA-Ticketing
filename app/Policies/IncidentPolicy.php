<?php

namespace App\Policies;

use App\Enums\IncidentAction;
use App\Enums\Role;
use App\Models\Incident;
use App\Models\User;

class IncidentPolicy
{
    public function view(User $user, Incident $incident): bool
    {
        if ($user->role === Role::TEKNISI) {
            return $incident->isAssignedTo($user);
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(...IncidentAction::GAMAS_DIBUAT->allowedRoles());
    }

    public function perform(User $user, Incident $incident, IncidentAction $action): bool
    {
        return $this->view($user, $incident) && $user->hasRole(...$action->allowedRoles());
    }
}
