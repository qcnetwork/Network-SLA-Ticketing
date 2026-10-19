<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramLog extends Model
{
    /** Number of log entries kept; older ones are pruned. */
    public const KEEP = 100;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_real_sent' => 'boolean',
            'simulated' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
