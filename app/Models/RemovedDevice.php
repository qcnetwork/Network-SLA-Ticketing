<?php

namespace App\Models;

use App\Enums\RemovedDeviceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A device taken off a customer (alat cabutan), from the technician's report to the warehouse's check.
 */
class RemovedDevice extends Model
{
    /** Days a technician has to hand a removed device to the warehouse. */
    public const DAYS_TO_RETURN = 3;

    /** What the technician says about the device when taking it off. */
    public const FIELD_CONDITIONS = ['RUSAK' => 'Rusak', 'NORMAL' => 'Masih nyala', 'TIDAK_TAHU' => 'Belum tahu'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => RemovedDeviceStatus::class,
            'due_at' => 'datetime',
            'received_at' => 'datetime',
            'checked_at' => 'datetime',
            'reminded_at' => 'datetime',
            'quantity' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function serialUnit(): BelongsTo
    {
        return $this->belongsTo(SerialUnit::class);
    }

    /** Still with the technician past the deadline. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', RemovedDeviceStatus::DIBAWA)->where('due_at', '<', now());
    }

    public function isOverdue(): bool
    {
        return $this->status === RemovedDeviceStatus::DIBAWA && $this->due_at->isPast();
    }

    public function fieldConditionLabel(): string
    {
        return self::FIELD_CONDITIONS[$this->field_condition] ?? $this->field_condition;
    }

    public function formattedMac(): ?string
    {
        return $this->mac ? implode(':', str_split($this->mac, 2)) : null;
    }

    /** "ONT ZTE F609 · SN ZTEG…", for history lines and notices. */
    public function describe(): string
    {
        return collect([
            $this->quantity > 1 ? "{$this->name} × {$this->quantity}" : $this->name,
            $this->serial_number ? "SN {$this->serial_number}" : null,
            $this->formattedMac() ? "MAC {$this->formattedMac()}" : null,
            $this->fieldConditionLabel(),
        ])->filter()->implode(' · ');
    }
}
