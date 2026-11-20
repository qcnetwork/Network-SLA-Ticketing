<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of material the warehouse keeps, identified by its warehouse code (e.g. ADP001) and grouped in a
 * category. An item that tracks serials (always so in a category that requires SN) moves one scanned SN or barcode
 * at a time; `scans_mac` asks for the unit's MAC label after each SN (modems yes, dropcore rolls and poles no).
 */
class Item extends Model
{
    protected $guarded = ['id'];

    /**
     * Marks a pick of an SN item's loose stock (counted from before it switched to SN), e.g. "KBL-1 (TANPA SN)".
     */
    public const LOOSE_MARK = '(TANPA SN)';

    public static function looseCode(string $code): string
    {
        return $code.' '.self::LOOSE_MARK;
    }

    public static function stripLooseMark(string $code): string
    {
        return trim(str_replace(self::LOOSE_MARK, '', $code));
    }

    protected function casts(): array
    {
        return ['active' => 'boolean', 'tracks_serial' => 'boolean', 'scans_mac' => 'boolean', 'min_stock' => 'integer', 'send_ratio' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }

    /**
     * The item a technician receives instead of this one (a roll of dropcore arrives as meters of it).
     */
    public function sendAs(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'send_as_item_id');
    }

    public function convertsOnSend(): bool
    {
        return $this->send_as_item_id !== null && $this->send_ratio > 0;
    }

    /**
     * e.g. "1 roll = 1000 meter DRC-M".
     */
    public function conversionLabel(): ?string
    {
        return $this->convertsOnSend() && $this->sendAs
            ? "1 {$this->unit} = {$this->send_ratio} {$this->sendAs->unit} {$this->sendAs->code}"
            : null;
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
