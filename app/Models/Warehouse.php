<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The main warehouse (no user) holds every material the office has; each technician carries their own.
 */
class Warehouse extends Model
{
    protected $guarded = ['id'];

    public static function main(): self
    {
        return static::query()->whereNull('user_id')->firstOrFail();
    }

    /**
     * The technician's own warehouse, made the first time they get stock.
     */
    public static function of(User $user): self
    {
        return static::query()->firstOrCreate(['user_id' => $user->id], ['name' => "Gudang {$user->name}"]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function serialUnits(): HasMany
    {
        return $this->hasMany(SerialUnit::class);
    }

    public function scopeTechnicians(Builder $query): Builder
    {
        return $query->whereNotNull('user_id');
    }

    public function isMain(): bool
    {
        return $this->user_id === null;
    }
}
