<?php

namespace App\Models;

use App\Models\Concerns\ManagedOption;
use Illuminate\Database\Eloquent\Model;

/**
 * An internet package offered on a new installation (PSB) order, managed by supervisors.
 */
class ServicePackage extends Model
{
    use ManagedOption;

    public const TICKET_COLUMN = 'package';

    protected $guarded = ['id'];
}
