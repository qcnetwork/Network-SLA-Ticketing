<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One modem (or other SN-tracked unit). It sits in a warehouse until a repair resume installs it
 * at a customer, which moves it onto that ticket.
 */
class SerialUnit extends Model
{
    protected $guarded = ['id'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
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
        return $this->warehouse?->name ?? ($this->ticket ? "Terpasang · {$this->ticket->ticket_number}" : '-');
    }
}
