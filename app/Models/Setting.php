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
        // Forum topic (message_thread_id) for ticket notifications; blank posts to the group's general chat.
        'log_topic_id' => '',
        // Forum topic for the daily master list; blank turns the list off.
        'list_topic_id' => '',
        // Today's master list message, kept by TelegramTicketList.
        'ticket_list_message_id' => '',
        'ticket_list_date' => '',
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
