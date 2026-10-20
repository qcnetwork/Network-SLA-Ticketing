<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\Setting;
use App\Models\Ticket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The daily master list in the group's ListTicket topic: one message per day that is edited
 * whenever a ticket changes, so each day's final state stays readable as history.
 */
class TelegramTicketList
{
    /**
     * Telegram rejects messages over 4096 characters; the rest leaves room for the header and footer.
     */
    private const MAX_LENGTH = 3800;

    /**
     * Refresh the list once, after the response is sent, however many tickets the request changed.
     */
    public static function queueRefresh(): void
    {
        defer(fn () => app(self::class)->refresh(), 'telegram-ticket-list', always: true);
    }

    public function refresh(): void
    {
        $botToken = (string) Setting::read('bot_token');
        $chatId = (string) Setting::read('chat_id');
        $topicId = (string) Setting::read('list_topic_id');
        if ($botToken === '' || $chatId === '' || $topicId === '') {
            return;
        }

        // Two requests finishing at once must not both start a new day's message.
        Cache::lock('telegram-ticket-list', 30)->block(10, function () use ($botToken, $chatId, $topicId) {
            $today = now()->toDateString();
            $text = $this->message($this->tickets(), now());
            $messageId = Setting::read('ticket_list_message_id');

            if ($messageId && Setting::read('ticket_list_date') === $today
                && $this->edit($botToken, $chatId, (int) $messageId, $text)) {
                return;
            }

            $messageId = $this->post($botToken, $chatId, (int) $topicId, $text);
            if ($messageId) {
                Setting::write(['ticket_list_message_id' => $messageId, 'ticket_list_date' => $today]);
            }
        });
    }

    /**
     * Every open ticket plus the ones closed today, in workflow order.
     *
     * @return Collection<int, Ticket>
     */
    public function tickets(): Collection
    {
        $order = array_flip(array_map(fn (TicketStatus $status) => $status->value, TicketStatus::cases()));

        return Ticket::query()
            ->where(fn ($query) => $query->where('status', '!=', TicketStatus::TUTUP)->orWhere('closed_at', '>=', today()))
            ->orderBy('created_at')
            ->get(['ticket_number', 'customer_name', 'status', 'created_at'])
            ->sortBy(fn (Ticket $ticket) => $order[$ticket->status->value], SORT_NUMERIC)
            ->values();
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     */
    public function message(Collection $tickets, Carbon $now): string
    {
        $e = TelegramService::escape(...);
        $closed = $tickets->where('status', TicketStatus::TUTUP)->count();

        $header = "📋 <b>MASTER LIST TIKET</b>\n"
            .'📅 '.$now->translatedFormat('l, d F Y')."\n"
            .'Aktif: <b>'.($tickets->count() - $closed)."</b> · Tutup hari ini: <b>{$closed}</b>\n"
            ."━━━━━━━━━━━━━━━━━━━━\n";
        $footer = "━━━━━━━━━━━━━━━━━━━━\n".'🔄 <i>Diperbarui '.$now->format('H:i').'</i>';

        // One section per node, numbered from its oldest ticket.
        $body = '';
        $shown = 0;
        $length = mb_strlen($header.$footer);
        foreach ($tickets->groupBy(fn (Ticket $ticket) => $ticket->status->value) as $group) {
            $status = $group->first()->status;
            $section = [($body === '' ? '' : "\n").Str::before($status->telegramLabel(), ' ')
                .' <b>'.mb_strtoupper($status->label())." ({$group->count()})</b>\n"];
            foreach ($group->values() as $i => $ticket) {
                $section[] = ($i + 1).". <code>{$e($ticket->ticket_number)}</code> · {$e($ticket->customer_name)}\n";
            }

            foreach ($section as $index => $line) {
                if ($length + mb_strlen($line) > self::MAX_LENGTH) {
                    return $header.$body.'… dan '.($tickets->count() - $shown)." tiket lainnya\n".$footer;
                }
                $body .= $line;
                $length += mb_strlen($line);
                $shown += $index > 0 ? 1 : 0;
            }
        }

        return $header.($body !== '' ? $body : "<i>Belum ada tiket.</i>\n").$footer;
    }

    /**
     * Whether the existing message now shows the text. False when it is gone and a new one is needed.
     */
    private function edit(string $botToken, string $chatId, int $messageId, string $text): bool
    {
        $response = $this->call($botToken, 'editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);

        // Telegram refuses an edit that changes nothing; the list is still up to date.
        if (($response['ok'] ?? false) || str_contains((string) ($response['description'] ?? ''), 'message is not modified')) {
            return true;
        }
        $this->logFailure('editMessageText', $response['description'] ?? null);

        return false;
    }

    private function post(string $botToken, string $chatId, int $topicId, string $text): ?int
    {
        $response = $this->call($botToken, 'sendMessage', [
            'chat_id' => $chatId,
            'message_thread_id' => $topicId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);

        if (! ($response['ok'] ?? false)) {
            $this->logFailure('sendMessage', $response['description'] ?? null);

            return null;
        }

        return $response['result']['message_id'];
    }

    /**
     * The decoded Telegram response, or an error description when the request itself failed.
     */
    private function call(string $botToken, string $method, array $payload): array
    {
        try {
            return (array) Http::timeout(10)->asJson()->post("https://api.telegram.org/bot{$botToken}/{$method}", $payload)->json();
        } catch (Throwable $e) {
            // HTTP client errors quote the request URL, which carries the bot token.
            return ['ok' => false, 'description' => str_replace($botToken, '***', $e->getMessage())];
        }
    }

    private function logFailure(string $method, ?string $error): void
    {
        Log::warning("Telegram {$method} for ticket list failed", ['error' => $error]);
    }
}
