<?php

namespace App\Models;

use App\Enums\TicketAction;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketHistory extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'action' => TicketAction::class,
            'from_status' => TicketStatus::class,
            'to_status' => TicketStatus::class,
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }
}
