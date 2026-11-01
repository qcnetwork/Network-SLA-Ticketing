<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Ticket;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Bot commands sent to the webhook. Ticket details hold customers' contact details and the bot
 * can be messaged by anyone, so only members of the configured group get them, and only in a
 * private chat: asking in the group gets a short pointer to the DM, never the details.
 */
class TelegramCommands
{
    private const MEMBER_STATUSES = ['creator', 'administrator', 'member'];

    public const USAGE = 'Ketik <code>/detailtiket TCK-20261022-0001</code> untuk menerima detail tiket lewat DM.';

    public function __construct(private TelegramService $telegram) {}

    /**
     * @param  array<string, mixed>  $update  a Telegram Update object
     */
    public function handle(array $update): void
    {
        $message = $update['message'] ?? null;
        $sender = $message['from'] ?? null;
        if (! is_array($message) || ! is_array($sender) || ($sender['is_bot'] ?? false)) {
            return;
        }
        if (! preg_match('~^/(\w+)(?:@(\w+))?(?:\s+(.*))?$~s', trim((string) ($message['text'] ?? '')), $m)) {
            return;
        }
        $command = strtolower($m[1]);
        $mention = $m[2] ?? '';
        $argument = trim($m[3] ?? '');

        // In a group with several bots, /command@other_bot is not ours.
        if ($mention !== '' && strcasecmp($mention, $this->botUsername()) !== 0) {
            return;
        }

        match ($command) {
            'detailtiket' => $this->ticketDetail($message, (int) $sender['id'], $argument),
            'start', 'help' => ($message['chat']['type'] ?? '') === 'private' ? $this->reply($message, '👋 Halo! '.self::USAGE) : null,
            default => null,
        };
    }

    private function ticketDetail(array $message, int $senderId, string $argument): void
    {
        if ($argument === '') {
            $this->reply($message, self::USAGE);

            return;
        }
        if (! $this->isGroupMember($senderId)) {
            $this->reply($message, '⛔ Perintah ini hanya untuk anggota grup tim.');

            return;
        }

        $number = strtoupper(preg_split('/\s+/', $argument)[0]);
        $ticket = Ticket::query()->where('ticket_number', $number)->first();
        if (! $ticket) {
            $this->reply($message, '🔎 Tiket <code>'.TelegramService::escape($number).'</code> tidak ditemukan.');

            return;
        }

        Log::info('Telegram ticket detail requested', ['telegram_user' => $senderId, 'ticket' => $ticket->ticket_number]);
        $sent = $this->call('sendMessage', [
            'chat_id' => $senderId,
            'text' => $this->telegram->ticketDetailMessage($ticket),
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ]);

        if (($message['chat']['type'] ?? '') === 'private') {
            return;
        }

        $number = '<code>'.TelegramService::escape($ticket->ticket_number).'</code>';
        $this->reply($message, ($sent['ok'] ?? false)
            ? "📩 Detail {$number} sudah dikirim lewat DM."
            // Bots cannot open a private chat; the user has to press Start once.
            : '⚠️ Bot belum bisa mengirim DM ke Anda. Buka @'.TelegramService::escape($this->botUsername())
                ." lalu tekan <b>Start</b>, kemudian ulangi perintah untuk {$number}.");
    }

    private function isGroupMember(int $userId): bool
    {
        $chatId = (string) Setting::read('chat_id');
        if ($chatId === '') {
            return false;
        }
        $member = $this->call('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId])['result'] ?? [];
        $status = $member['status'] ?? '';

        return in_array($status, self::MEMBER_STATUSES, true) || ($status === 'restricted' && ($member['is_member'] ?? false));
    }

    private function reply(array $message, string $text): void
    {
        $this->call('sendMessage', array_filter([
            'chat_id' => $message['chat']['id'],
            'message_thread_id' => ($message['is_topic_message'] ?? false) ? $message['message_thread_id'] : null,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_parameters' => ['message_id' => $message['message_id'], 'allow_sending_without_reply' => true],
        ]));
    }

    private function botUsername(): string
    {
        $key = 'telegram-bot-username:'.hash('sha256', (string) Setting::read('bot_token'));
        if ($username = Cache::get($key)) {
            return $username;
        }
        // A failed lookup is not cached, or commands addressed @bot would be ignored until it expired.
        $username = (string) ($this->call('getMe', [])['result']['username'] ?? '');
        if ($username !== '') {
            Cache::put($key, $username, now()->addDay());
        }

        return $username;
    }

    /**
     * @return array<string, mixed>
     */
    private function call(string $method, array $payload): array
    {
        return $this->telegram->callMany((string) Setting::read('bot_token'), ['call' => [$method, $payload]])['call'];
    }
}
