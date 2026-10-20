<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A complaint category Supervisors manage from the app. Tickets keep the category name as text,
 * so a deactivated category still shows on its old tickets and in reports.
 */
class TicketCategory extends Model
{
    protected $guarded = ['id'];

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
     * Names offered when creating or recategorizing a ticket.
     *
     * @return array<string>
     */
    public static function activeNames(): array
    {
        return static::query()->where('active', true)->ordered()->pluck('name')->all();
    }

    public function ticketCount(): int
    {
        return Ticket::query()->where('category', $this->name)->count();
    }
}
