<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One SN-tracked unit: a modem, a roll of dropcore, a pole... It sits in a warehouse until a repair resume
 * installs it at a customer (moving it onto that ticket), it is sold, or (a roll set to convert on send) it is
 * opened into a technician's warehouse as meters of cable.
 */
class SerialUnit extends Model
{
    protected $guarded = ['id'];

    /**
     * Where a unit that left stock went, as the tracking page groups it: installed at a customer, handed to a
     * partner (mitra) or sold, returned / broken, or anything else (a roll opened into meters, no note).
     */
    public const DESTINATIONS = [
        'pelanggan' => 'Terpasang di pelanggan',
        'mitra' => 'Mitra / terjual',
        'retur' => 'Retur / eror',
        'lainnya' => 'Lainnya',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'date', 'taken_at' => 'date', 'imported_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function convertedTo(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'converted_to_warehouse_id');
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

    /**
     * Units no longer in any warehouse: installed, handed out, sold or written off ("kotor").
     */
    public function scopeUsed(Builder $query): Builder
    {
        return $query->whereNull('warehouse_id');
    }

    /**
     * Narrow used units to one of DESTINATIONS, in the same order location() reads a unit.
     */
    public function scopeDestination(Builder $query, string $destination): Builder
    {
        $toPartner = fn (Builder $q) => $q->where('condition', 'like', 'Mitra %')->orWhere('condition', 'like', 'Partner %');
        $notInApp = fn (Builder $q) => $q->whereNull('ticket_id')->whereNull('material_usage_id')->whereNull('sale_id')->whereNull('converted_to_warehouse_id');

        return match ($destination) {
            'pelanggan' => $query->where(fn (Builder $q) => $q->whereNotNull('ticket_id')->orWhereNotNull('material_usage_id')
                ->orWhere(fn (Builder $q) => $notInApp($q)->whereNull('condition')->whereNotNull('installed_to'))),
            'mitra' => $query->where(fn (Builder $q) => $q->whereNotNull('sale_id')
                ->orWhere(fn (Builder $q) => $notInApp($q)->where($toPartner))),
            'retur' => $query->where(fn (Builder $q) => $notInApp($q)->whereNotNull('condition')->whereNot($toPartner)),
            default => $query->where(fn (Builder $q) => $q->whereNotNull('converted_to_warehouse_id')
                ->orWhere(fn (Builder $q) => $notInApp($q)->whereNull('condition')->whereNull('installed_to'))),
        };
    }

    /**
     * Which of DESTINATIONS a used unit belongs to (null while it sits in a warehouse).
     */
    public function destination(): ?string
    {
        $partner = $this->condition !== null && (str_starts_with($this->condition, 'Mitra ') || str_starts_with($this->condition, 'Partner '));

        return match (true) {
            $this->warehouse_id !== null => null,
            $this->ticket_id !== null || $this->material_usage_id !== null => 'pelanggan',
            $this->sale_id !== null => 'mitra',
            $this->converted_to_warehouse_id !== null => 'lainnya',
            $this->condition !== null => $partner ? 'mitra' : 'retur',
            $this->installed_to !== null => 'pelanggan',
            default => 'lainnya',
        };
    }

    /**
     * Where it is now, as a short place name (customer, partner, buyer...) without the "Terpasang ·" prefix.
     */
    public function placeName(): string
    {
        return match (true) {
            $this->warehouse !== null => $this->warehouse->name,
            $this->ticket !== null => trim("{$this->ticket->customer_name} · {$this->ticket->ticket_number}", ' ·'),
            $this->usage !== null => $this->usage->location,
            $this->sale !== null => "{$this->sale->buyer_name} ({$this->sale->sale_number})",
            $this->convertedTo !== null => "Dibuka jadi meter · {$this->convertedTo->name}",
            $this->condition !== null && $this->destination() === 'mitra' => preg_replace('/^(Mitra|Partner jual beli) /', '', $this->condition),
            $this->installed_to !== null => $this->installed_to,
            default => $this->condition ?? '-',
        };
    }

    /**
     * A scanned SN as stored: upper case without spaces.
     */
    public static function normalizeSerial(?string $serial): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $serial));
    }

    /**
     * A scanned MAC as stored: twelve hex digits without separators, or null when it is not one.
     */
    public static function normalizeMac(?string $mac): ?string
    {
        $mac = preg_replace('/[^0-9A-F]/', '', strtoupper((string) $mac));

        return strlen($mac) === 12 ? $mac : null;
    }

    public function formattedMac(): ?string
    {
        return $this->mac ? implode(':', str_split($this->mac, 2)) : null;
    }

    public function location(): string
    {
        return $this->warehouse?->name
            ?? match (true) {
                $this->ticket !== null => "Terpasang · {$this->ticket->ticket_number}",
                $this->usage !== null => "Terpakai · {$this->usage->location}",
                $this->sale !== null => "Terjual · {$this->sale->buyer_name} ({$this->sale->sale_number})",
                $this->convertedTo !== null => "Dibuka jadi {$this->item->sendAs?->unit} · {$this->convertedTo->name}",
                $this->condition !== null => "Keluar stok · {$this->condition}",
                $this->installed_to !== null => "Terpasang · {$this->installed_to}",
                default => '-',
            };
    }
}
