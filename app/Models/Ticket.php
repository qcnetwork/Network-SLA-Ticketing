<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Concerns\HasTechnicians;
use App\Services\TelegramTicketList;
use App\Services\TicketNotifier;
use App\Support\GeoLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Ticket extends Model
{
    use HasFactory, HasTechnicians;

    /**
     * A ticket waiting for customer confirmation closes itself after this long without a response.
     */
    public const CONFIRMATION_TIMEOUT_HOURS = 48;

    /**
     * Closed tickets drop off the dashboard this long after closing. They stay in the database,
     * in reports and in search results.
     */
    public const CLOSED_VISIBLE_DAYS = 4;

    protected $guarded = ['id'];

    /**
     * Every change that moves a ticket between nodes, from any workflow, refreshes the Telegram master list
     * and notifies the roles that work on the node it reached.
     */
    protected static function booted(): void
    {
        static::saved(function (Ticket $ticket) {
            if ($ticket->wasRecentlyCreated || $ticket->wasChanged(['status', 'customer_name'])) {
                TelegramTicketList::queueRefresh();
            }
        });
        static::deleted(fn () => TelegramTicketList::queueRefresh());

        // created/updated rather than saved: wasRecentlyCreated stays true for later saves in the same request.
        static::created(fn (Ticket $ticket) => app(TicketNotifier::class)->statusChanged($ticket, auth()->user()));
        static::updated(function (Ticket $ticket) {
            if ($ticket->wasChanged('status')) {
                app(TicketNotifier::class)->statusChanged($ticket, auth()->user());
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'repair_started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function mapsUrl(): ?string
    {
        return $this->hasLocation() ? GeoLocation::mapsUrl($this->latitude, $this->longitude) : null;
    }

    public function getRouteKeyName(): string
    {
        return 'ticket_number';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function helpdesk(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_helpdesk_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(TicketHistory::class)->orderBy('created_at')->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class)->orderBy('id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(TicketShare::class);
    }

    public function isSharedWith(User $user): bool
    {
        return $this->shares()->active()->where('user_id', $user->id)->exists();
    }

    /**
     * Tickets currently shared with the user through the archive.
     */
    public function scopeSharedWith(Builder $query, User $user): Builder
    {
        return $query->whereHas('shares', fn (Builder $share) => $share->active()->where('user_id', $user->id));
    }

    /**
     * Limit the query to tickets the given user may see.
     * Technicians only see repair tickets assigned to them.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === Role::TEKNISI) {
            $query->where('status', TicketStatus::PERBAIKAN)->assignedTo($user);
        }

        return $query;
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        if (! empty($filters['status']) && $filters['status'] !== 'ALL') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['priority']) && $filters['priority'] !== 'ALL') {
            $query->where('priority', $filters['priority']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('ticket_number', 'like', $term)
                    ->orWhere('customer_name', 'like', $term)
                    ->orWhere('customer_phone', 'like', $term)
                    ->orWhere('customer_id', 'like', $term)
                    ->orWhere('complaint', 'like', $term)
                    ->orWhereHas('technicians', fn (Builder $t) => $t->where('name', 'like', $term));
            });
        }

        return $query;
    }

    /**
     * When the ticket will be closed automatically, or null if it is not waiting for confirmation.
     */
    public function autoCloseAt(): ?Carbon
    {
        if ($this->status !== TicketStatus::MENUNGGU_KONFIRMASI || ! $this->resolved_at) {
            return null;
        }

        return $this->resolved_at->copy()->addHours(self::CONFIRMATION_TIMEOUT_HOURS);
    }

    public function scopeWithoutStaleClosed(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('status', '!=', TicketStatus::TUTUP)
            ->orWhereNull('closed_at')
            ->orWhere('closed_at', '>=', now()->subDays(self::CLOSED_VISIBLE_DAYS)));
    }

    public function scopeAwaitingConfirmation(Builder $query): Builder
    {
        return $query->where('status', TicketStatus::MENUNGGU_KONFIRMASI);
    }
}
