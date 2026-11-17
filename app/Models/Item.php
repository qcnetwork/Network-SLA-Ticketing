<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of material the warehouse keeps, identified by its warehouse code (e.g. ADP001) and grouped in a
 * category. An item that tracks serials (always so in a device category) moves one scanned SN at a time.
 */
class Item extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'tracks_serial' => 'boolean', 'min_stock' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function serialUnits(): HasMany
    {
        return $this->hasMany(SerialUnit::class);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('code', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%"));
    }

    /**
     * Whether this many units in the main warehouse is at or under the item's minimum.
     */
    public function isLow(int $quantity): bool
    {
        return $this->min_stock > 0 && $quantity <= $this->min_stock;
    }

    public function label(): string
    {
        return "{$this->code} · {$this->name}";
    }
}
