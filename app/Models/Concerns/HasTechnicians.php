<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Work (a ticket or a gamas) that can be assigned to one or more field technicians.
 * The pivot table is named after the model, e.g. ticket_technician.
 */
trait HasTechnicians
{
    public function technicians(): BelongsToMany
    {
        return $this->belongsToMany(User::class, Str::singular($this->getTable()).'_technician', null, 'user_id')
            ->withTimestamps()
            ->orderBy('name');
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->technicians->contains('id', $user->id);
    }

    /**
     * Comma-separated names of the assigned technicians, or an empty string when there are none.
     */
    public function technicianNames(): string
    {
        return $this->technicians->pluck('name')->implode(', ');
    }

    public function scopeAssignedTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('technicians', fn (Builder $technicians) => $technicians->whereKey($user->id));
    }
}
