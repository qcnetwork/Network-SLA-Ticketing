<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public const DEFAULTS = [
        'bot_token' => '',
        'chat_id' => '',
        'notify_on_new_ticket' => '1',
        'notify_on_status_change' => '1',
        'cs_phone' => '6281234567890',
    ];

    /**
     * @return array<string, string>
     */
    public static function values(): array
    {
        return array_merge(self::DEFAULTS, static::query()->pluck('value', 'key')->all());
    }

    public static function read(string $key): ?string
    {
        return static::query()->find($key)?->value ?? self::DEFAULTS[$key] ?? null;
    }

    public static function write(array $values): void
    {
        foreach ($values as $key => $value) {
            static::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
        }
    }
}
