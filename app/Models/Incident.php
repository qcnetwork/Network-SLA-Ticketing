<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Models\Concerns\HasTechnicians;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A mass outage (gangguan massal / gamas) that groups the tickets of every affected customer.
 */
class Incident extends Model
{
    use HasFactory, HasTechnicians;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => IncidentStatus::class,
            'estimated_resolved_at' => 'datetime',
            'repair_started_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'incident_number';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class)->orderBy('id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(IncidentUpdate::class)->orderBy('created_at')->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status !== IncidentStatus::SELESAI;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', IncidentStatus::open());
    }

    /**
     * Technicians only see the gamas assigned to them.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === Role::TEKNISI) {
            $query->assignedTo($user);
        }

        return $query;
    }
}
