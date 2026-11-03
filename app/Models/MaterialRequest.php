<?php

namespace App\Models;

use App\Enums\MaterialRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The material a repair resume says was used. It holds no stock until the warehouse approves it,
 * at which point each line leaves its technician's warehouse.
 */
class MaterialRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => MaterialRequestStatus::class, 'reviewed_at' => 'datetime'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MaterialRequestLine::class)->orderBy('id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', MaterialRequestStatus::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === MaterialRequestStatus::PENDING;
    }
}
