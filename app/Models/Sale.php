<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Equipment sold out of the main warehouse to a partner or another buyer, with its price and whether it is paid.
 */
class Sale extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sold_at' => 'date',
            'paid_at' => 'datetime',
            'total' => 'integer',
            'payment_status' => PaymentStatus::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'sale_number';
    }

    /**
     * The next number for a sale on this day: PJ-YYYYMMDD-001.
     */
    public static function nextNumber(Carbon $date): string
    {
        $prefix = 'PJ-'.$date->format('Ymd').'-';
        $last = static::query()->where('sale_number', 'like', $prefix.'%')->orderByDesc('sale_number')->value('sale_number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 3, '0', STR_PAD_LEFT);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class)->orderBy('id');
    }

    public function serialUnits(): HasMany
    {
        return $this->hasMany(SerialUnit::class);
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->where('payment_status', PaymentStatus::BELUM_LUNAS);
    }

    public function isPaid(): bool
    {
        return $this->payment_status === PaymentStatus::LUNAS;
    }

    /**
     * Rupiah as the office writes it: Rp 1.250.000.
     */
    public static function rupiah(int|float|null $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }
}
