<?php

namespace App\Jobs;

use App\Services\TelegramService;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Dispatched with dispatchAfterResponse() so the Telegram API call never
 * delays the user's request and no queue worker is required.
 */
class SendTelegramMessage
{
    use Dispatchable;

    public function __construct(
        public string $text,
        public array $metadata = [],
    ) {}

    public function handle(TelegramService $telegram): void
    {
        $telegram->send($this->text, $this->metadata);
    }
}
