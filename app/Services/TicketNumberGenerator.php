<?php

namespace App\Services;

use App\Models\Ticket;
use Illuminate\Support\Carbon;

class TicketNumberGenerator
{
    /**
     * Next number in the TCK-YYYYMMDD-XXXX format. Call inside a DB transaction.
     */
    public function next(?Carbon $date = null): string
    {
        $prefix = 'TCK-'.($date ?? now())->format('Ymd').'-';

        $last = Ticket::query()
            ->where('ticket_number', 'like', $prefix.'%')
            ->orderByDesc('ticket_number')
            ->value('ticket_number');

        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
