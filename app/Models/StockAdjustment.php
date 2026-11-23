<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One manual stock correction, with the figure before and after. Read by supervisors only.
 */
class StockAdjustment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity_before' => 'integer', 'quantity_after' => 'integer', 'difference' => 'integer'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }
}
