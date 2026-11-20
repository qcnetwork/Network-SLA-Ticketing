<?php

namespace App\Models;

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
