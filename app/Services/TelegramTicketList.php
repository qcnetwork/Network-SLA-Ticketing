<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\Setting;
use App\Models\Ticket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

    public function __construct(private TelegramService $telegram) {}

    /**
     * Refresh the list once, after the response is sent, however many tickets the request changed.
     */
    public static function queueRefresh(): void
    {
        app(TelegramOutbox::class)->refreshList();
    }

    public function refresh(): void
    {
        $this->locked(function () {
            if ($plan = $this->plan()) {
                $this->complete($plan, $this->telegram->callMany($plan['token'], ['list' => $plan['call']])['list']);
            }
        });
    }

    /**
     * Two requests finishing at once must not both start a new day's message.
     */
    public function locked(callable $callback): mixed
    {
        return Cache::lock('telegram-ticket-list', 30)->block(10, $callback);
    }

    /**
     * The Bot API call that brings the list up to date: an edit of today's message, or a new one.
     * Null when the list is turned off. Call inside locked().
     *
     * @return array{token: string, chat: string, topic: int, date: string, text: string, call: array{0: string, 1: array}}|null
     */
    public function plan(): ?array
    {
        $botToken = (string) Setting::read('bot_token');
        $chatId = (string) Setting::read('chat_id');
        $topicId = (string) Setting::read('list_topic_id');
        if ($botToken === '' || $chatId === '' || $topicId === '') {
            return null;
        }

        $plan = ['token' => $botToken, 'chat' => $chatId, 'topic' => (int) $topicId, 'date' => now()->toDateString(), 'text' => $this->message($this->tickets(), now())];
        $messageId = Setting::read('ticket_list_message_id');

        return $plan + ['call' => $messageId && Setting::read('ticket_list_date') === $plan['date']
            ? ['editMessageText', ['chat_id' => $chatId, 'message_id' => (int) $messageId, 'text' => $plan['text'], 'parse_mode' => 'HTML']]
            : $this->postCall($plan)];
    }

    /**
     * Handle Telegram's answer to the planned call. Call inside locked().
     */
    public function complete(array $plan, array $response): void
    {
        if ($plan['call'][0] === 'editMessageText') {
            // Telegram refuses an edit that changes nothing; the list is still up to date.
            if (($response['ok'] ?? false) || str_contains((string) ($response['description'] ?? ''), 'message is not modified')) {
                return;
            }
            $this->logFailure('editMessageText', $response['description'] ?? null);

            // The message is gone (or no longer editable), so start a new one.
            $response = $this->telegram->callMany($plan['token'], ['list' => $this->postCall($plan)])['list'];
        }

        if (! ($response['ok'] ?? false)) {
            $this->logFailure('sendMessage', $response['description'] ?? null);

            return;
        }
        Setting::write(['ticket_list_message_id' => $response['result']['message_id'], 'ticket_list_date' => $plan['date']]);
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
     * @return array{0: string, 1: array}
     */
    private function postCall(array $plan): array
    {
        return ['sendMessage', [
            'chat_id' => $plan['chat'],
            'message_thread_id' => $plan['topic'],
            'text' => $plan['text'],
            'parse_mode' => 'HTML',
        ]];
    }

    private function logFailure(string $method, ?string $error): void
    {
        Log::warning("Telegram {$method} for ticket list failed", ['error' => $error]);
    }
}
