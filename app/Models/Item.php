<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of material the warehouse keeps, identified by its warehouse code (e.g. ADP001).
 */
class Item extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'tracks_serial' => 'boolean'];
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

    public function label(): string
    {
        return "{$this->code} · {$this->name}";
    }
}
