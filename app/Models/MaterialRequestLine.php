<?php

namespace App\Models;

use App\Enums\MaterialRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialRequestLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class, 'material_request_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function serialUnit(): BelongsTo
    {
        return $this->belongsTo(SerialUnit::class);
    }

    /**
     * Lines still waiting for the warehouse: their stock is spoken for but has not left yet.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereHas('request', fn (Builder $q) => $q->where('status', MaterialRequestStatus::PENDING));
    }

    public function describe(): string
    {
        $what = $this->serialUnit
            ? "{$this->item->code} {$this->item->name} SN {$this->serialUnit->serial_number}"
            : "{$this->item->code} {$this->item->name} × {$this->quantity} {$this->item->unit}";

        return "{$what} dari {$this->warehouse->name}".($this->notes ? " ({$this->notes})" : '');
    }
}
