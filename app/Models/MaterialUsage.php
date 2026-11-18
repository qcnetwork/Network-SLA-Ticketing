<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\UsagePurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One job material was used on: when, what for, where and by whom. Its lines are the items.
 */
class MaterialUsage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'used_at' => 'date',
            'purpose' => UsagePurpose::class,
            'cancelled_at' => 'datetime',
            'imported_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MaterialUsageLine::class)->orderBy('id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function materialRequest(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    /**
     * Everyone with the warehouse menu sees every job; a technician sees the jobs they recorded or that drew on their own stock.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role !== Role::TEKNISI) {
            return $query;
        }
        $warehouse = Warehouse::query()->where('user_id', $user->id)->value('id');

        return $query->where(fn (Builder $q) => $q->where('user_id', $user->id)
            ->orWhereHas('lines', fn (Builder $line) => $line->where('warehouse_id', $warehouse ?? 0)));
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function isImported(): bool
    {
        return $this->imported_at !== null;
    }

    /**
     * Whose stock it came from, by warehouse, or as the old sheet named it.
     */
    public function holders(): string
    {
        $names = $this->lines->map(fn (MaterialUsageLine $line) => $line->warehouse?->name)->filter()->unique();

        return $names->isNotEmpty() ? $names->implode(', ') : ($this->holder_name ?? '-');
    }
}
