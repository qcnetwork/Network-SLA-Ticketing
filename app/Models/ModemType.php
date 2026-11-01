<?php

namespace App\Models;

use App\Models\Concerns\ManagedOption;
use Illuminate\Database\Eloquent\Model;

/**
 * A modem/ONT type suggested when Helpdesk records the customer's device. Helpdesk can still type
 * a model that is not on the list.
 */
class ModemType extends Model
{
    use ManagedOption;

    public const TICKET_COLUMN = 'modem';

    protected $guarded = ['id'];
}
