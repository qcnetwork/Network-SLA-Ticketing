<?php

namespace App\Models;

use App\Models\Concerns\ManagedOption;
use Illuminate\Database\Eloquent\Model;

/**
 * A complaint category Supervisors manage from the app.
 */
class TicketCategory extends Model
{
    use ManagedOption;

    public const TICKET_COLUMN = 'category';

    protected $guarded = ['id'];
}
