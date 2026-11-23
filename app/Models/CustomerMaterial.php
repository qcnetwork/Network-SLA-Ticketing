<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One piece of material at a customer (Gudang Pelanggan): a modem by its SN, or a quantity of cable, connectors...
 * Linked to the customer by ID (customer_code = customers.code). Removed material keeps its row, with when and why.
 */
class CustomerMaterial extends Model
{
    protected $table = 'gudang_pelanggan';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['installed_at' => 'date', 'removed_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_code', 'code');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function serialUnit(): BelongsTo
    {
        return $this->belongsTo(SerialUnit::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function usage(): BelongsTo
    {
        return $this->belongsTo(MaterialUsage::class, 'material_usage_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CustomerMaterialLog::class, 'gudang_pelanggan_id');
    }

    /**
     * Still at the customer.
     */
    public function scopeInstalled(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    /**
     * IDs are matched ignoring letter case, as the customer directory does.
     */
    public function scopeOfCustomer(Builder $query, string $code): Builder
    {
        return $query->whereRaw('lower(customer_code) = ?', [mb_strtolower(trim($code))]);
    }

    public function isInstalled(): bool
    {
        return $this->removed_at === null;
    }

    public function formattedMac(): ?string
    {
        return $this->mac ? implode(':', str_split($this->mac, 2)) : null;
    }
}
