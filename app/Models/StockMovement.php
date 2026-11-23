<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Services\SheetSync;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // Every in/out also lands in the Google Sheet, once the response has gone out.
        static::created(fn () => app(SheetSync::class)->queue());
    }

    protected function casts(): array
    {
        return ['type' => StockMovementType::class, 'quantity' => 'integer', 'sheet_synced_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function serialUnit(): BelongsTo
    {
        return $this->belongsTo(SerialUnit::class);
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function usage(): BelongsTo
    {
        return $this->belongsTo(MaterialUsage::class, 'material_usage_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Who collected stock sent to a technician, when it was not the technician themselves. */
    public function takenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by');
    }
}
