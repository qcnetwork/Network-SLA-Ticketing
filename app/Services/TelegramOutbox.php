<?php

namespace App\Services;

/**
 * Collects a request's Telegram work (log messages and the master list refresh) and sends it all
 * in one concurrent round after the response. On Laragon the browser still waits for that work,
 * and each Bot API call takes ~0.7 s, so sending them one by one made every action feel slow.
 */
class TelegramOutbox
{
    /**
     * @var array<int, array{0: string, 1: array}>
     */
    private array $messages = [];

    private bool $refreshList = false;

    public function __construct(
        private TelegramService $telegram,
        private TelegramTicketList $list,
    ) {}

    public function push(string $text, array $metadata = []): void
    {
        $this->messages[] = [$text, $metadata];
        $this->schedule();
    }

    public function refreshList(): void
    {
        $this->refreshList = true;
        $this->schedule();
    }

    public function flush(): void
    {
        [$messages, $refreshList] = [$this->messages, $this->refreshList];
        $this->messages = [];
        $this->refreshList = false;

        $send = function () use ($messages, $refreshList) {
            $plan = $refreshList ? $this->list->plan() : null;
            [, $responses] = $this->telegram->sendMany($messages, $plan ? ['list' => $plan['call']] : []);
            if ($plan) {
                $this->list->complete($plan, $responses['list']);
            }
        };

        $refreshList ? $this->list->locked($send) : $send();
    }

    private function schedule(): void
    {
        defer(fn () => $this->flush(), 'telegram-outbox', always: true);
    }
}
