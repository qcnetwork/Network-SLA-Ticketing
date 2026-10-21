<?php

namespace App\Models\Concerns;

use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

/**
 * A pick-list Supervisors manage from the app (complaint categories, modem types). Tickets keep the
 * chosen name as text in TICKET_COLUMN, so a deactivated option still shows on its old tickets.
 */
trait ManagedOption
{
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Names offered in the ticket forms.
     *
     * @return array<string>
     */
    public static function activeNames(): array
    {
        return static::query()->where('active', true)->ordered()->pluck('name')->all();
    }

    public function ticketCount(): int
    {
        return Ticket::query()->where(static::TICKET_COLUMN, $this->name)->count();
    }
}
