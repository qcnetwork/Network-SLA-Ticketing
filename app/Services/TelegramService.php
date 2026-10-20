<?php

namespace App\Services;

use App\Enums\IncidentAction;
use App\Enums\TicketStatus;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\TelegramLog;
use App\Models\Ticket;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramService
{
    /**
     * Longest ticket list in one message; keeps it well under Telegram's 4096-character limit.
     */
    private const LIST_LIMIT = 25;

    /**
     * Send a message to the configured group, inside the log topic when one is set. Without a bot
     * token/chat ID the message is only logged (simulator mode). Every message lands in telegram_logs.
     *
     * @return array{success: bool, error: ?string}
     */
    public function send(string $text, array $metadata = []): array
    {
        return $this->sendMany([[$text, $metadata]])[0][0];
    }

    /**
     * Send several log messages, plus any other Bot API calls such as the master list edit, all at once:
     * Telegram takes ~0.7 s per call and the user waits for it, so a request waits for one round trip
     * instead of one per call.
     *
     * @param  array<int, array{0: string, 1: array}>  $messages  [text, metadata] pairs for the log topic
     * @param  array<string, array{0: string, 1: array}>  $calls  extra [method, payload] calls, keyed by name
     * @return array{0: array<int, array{success: bool, error: ?string}>, 1: array<string, array>} results per message, decoded responses per extra call
     */
    public function sendMany(array $messages, array $calls = []): array
    {
        $botToken = (string) Setting::read('bot_token');
        $chatId = (string) Setting::read('chat_id');
        $topicId = (string) Setting::read('log_topic_id');
        $configured = $botToken !== '' && $chatId !== '';

        $requests = [];
        if ($configured) {
            foreach ($messages as $i => [$text]) {
                $requests["message:{$i}"] = ['sendMessage', array_filter([
                    'chat_id' => $chatId,
                    'message_thread_id' => $topicId !== '' ? (int) $topicId : null,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                ])];
            }
            foreach ($calls as $key => $call) {
                $requests["call:{$key}"] = $call;
            }
        }
        $responses = $this->callMany($botToken, $requests);

        $results = [];
        foreach ($messages as $i => [$text, $metadata]) {
            $response = $responses["message:{$i}"] ?? null;
            $success = ! $configured || ($response['ok'] ?? false);
            $error = $success ? null : ($response['description'] ?? 'Gagal mengirim ke Telegram API');
            if ($error) {
                Log::warning('Telegram sendMessage failed', ['error' => $error]);
            }

            TelegramLog::create([
                'message' => $text,
                'is_real_sent' => $configured && $success,
                'simulated' => ! $configured,
                'error' => $error,
                'metadata' => $metadata,
            ]);
            $results[] = ['success' => $success, 'error' => $error];
        }

        if ($messages) {
            $oldestKeptId = TelegramLog::query()->orderByDesc('id')->skip(TelegramLog::KEEP - 1)->value('id');
            if ($oldestKeptId) {
                TelegramLog::query()->where('id', '<', $oldestKeptId)->delete();
            }
        }

        $callResponses = [];
        foreach (array_keys($calls) as $key) {
            $callResponses[$key] = $responses["call:{$key}"] ?? ['ok' => false, 'description' => 'Bot Telegram belum dikonfigurasi'];
        }

        return [$results, $callResponses];
    }

    /**
     * Run Bot API calls concurrently. Each result is the decoded response, or an error description
     * when the request itself failed.
     *
     * @param  array<string, array{0: string, 1: array}>  $requests
     * @return array<string, array>
     */
    public function callMany(string $botToken, array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        // HTTP client errors quote the request URL, which carries the bot token.
        $failure = fn (Throwable $e) => ['ok' => false, 'description' => str_replace($botToken, '***', $e->getMessage())];

        try {
            $responses = Http::pool(fn (Pool $pool) => collect($requests)
                ->map(fn (array $request, string $key) => $pool->as($key)->timeout(10)->asJson()
                    ->post("https://api.telegram.org/bot{$botToken}/{$request[0]}", $request[1]))
                ->all());
        } catch (Throwable $e) {
            return array_map(fn () => $failure($e), $requests);
        }

        return collect($requests)->map(function (array $request, string $key) use ($responses, $failure) {
            $response = $responses[$key] ?? null;
            if ($response instanceof Throwable) {
                return $failure($response);
            }

            return (array) $response?->json() ?: ['ok' => false, 'description' => 'Respon Telegram tidak valid'];
        })->all();
    }

    public static function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_NOQUOTES, 'UTF-8');
    }

    /**
     * Logged as soon as the complaint is recorded, by whoever took it (CS, Helpdesk or a supervisor).
     */
    public function ticketCreatedMessage(Ticket $ticket): string
    {
        $e = self::escape(...);
        $creator = $ticket->creator;

        return "📥 <b>[TIKET DIBUAT · PROSES CS]</b>\n"
            ."━━━━━━━━━━━━━━━━━━━━\n"
            ."🎫 <b>No. Tiket:</b> <code>{$e($ticket->ticket_number)}</code>\n"
            ."👤 <b>Pelanggan:</b> {$e($ticket->customer_name)}\n"
            ."⚠️ <b>Kategori:</b> {$e($ticket->category)}\n"
            ."📝 <b>Kendala:</b> {$e($ticket->complaint)}\n"
            .'✍️ <b>Dibuat oleh:</b> '.($creator ? "{$e($creator->name)} ({$creator->role->label()})" : '-')."\n"
            ."━━━━━━━━━━━━━━━━━━━━\n"
            ."{$ticket->status->telegramLabel()}\n"
            ."⏰ <i>Waktu: {$ticket->created_at->format('d/m/Y H:i')}</i>\n"
            .'<i>Tiket masih dalam tahap koordinasi dengan pelanggan.</i>';
    }

    /**
     * @param  array<string>  $changes
     */
    public function customerUpdatedMessage(Ticket $ticket, array $changes, string $actor): string
    {
        $e = self::escape(...);

        return "✏️ <b>[DATA PELANGGAN DIPERBARUI]</b>\n"
            ."━━━━━━━━━━━━━━━━━━━━\n"
            ."🎫 <b>No. Tiket:</b> <code>{$e($ticket->ticket_number)}</code>\n"
            ."👤 <b>Pelanggan:</b> {$e($ticket->customer_name)}\n"
            ."⚡ <b>Status Saat Ini:</b> {$ticket->status->label()}\n"
            ."👤 <b>Petugas:</b> {$e($actor)}\n"
            ."📝 <b>Perubahan:</b>\n".collect($changes)->map(fn (string $change) => '• '.$e($change))->implode("\n")."\n"
            .($ticket->hasLocation() ? "🗺️ <b>Peta:</b> {$e($ticket->mapsUrl())}\n" : '')
            ."━━━━━━━━━━━━━━━━━━━━\n"
            .'⏰ <i>'.now()->format('d/m/Y H:i').'</i>';
    }

    public function newTicketMessage(Ticket $ticket, ?string $csNotes = null): string
    {
        $e = self::escape(...);

        return "🔔 <b>[TIKET BARU MASUK]</b>\n"
            ."━━━━━━━━━━━━━━━━━━━━\n"
            ."🎫 <b>No. Tiket:</b> <code>{$e($ticket->ticket_number)}</code>\n"
            ."👤 <b>Pelanggan:</b> {$e($ticket->customer_name)} ({$e($ticket->customer_id ?: '-')})\n"
            ."📱 <b>WhatsApp:</b> {$e($ticket->customer_phone)}\n"
            ."📍 <b>Alamat:</b> {$e($ticket->customer_address ?: '-')}\n"
            ."⚠️ <b>Kategori:</b> {$e($ticket->category)}\n"
            ."⚡ <b>Prioritas:</b> {$ticket->priority->telegramLabel()}\n"
            ."📝 <b>Kendala:</b> {$e($ticket->complaint)}\n"
            .($csNotes ? "💬 <b>Catatan CS:</b> {$e($csNotes)}\n" : '')
            ."━━━━━━━━━━━━━━━━━━━━\n"
            ."🟡 <b>Status:</b> ANTRIAN HELPDESK\n"
            ."⏰ <i>Waktu: {$ticket->created_at->format('d/m/Y H:i')}</i>\n"
            .'<i>Silakan Helpdesk melakukan pengecekan di Web Dashboard.</i>';
    }

    public function statusUpdateMessage(Ticket $ticket, TicketStatus $previous, ?string $notes, string $actor): string
    {
        $e = self::escape(...);

        return "📢 <b>[UPDATE STATUS TIKET]</b>\n"
            ."━━━━━━━━━━━━━━━━━━━━\n"
            ."🎫 <b>No. Tiket:</b> <code>{$e($ticket->ticket_number)}</code>\n"
            ."👤 <b>Pelanggan:</b> {$e($ticket->customer_name)}\n"
            ."🔄 <b>Status:</b> {$previous->telegramLabel()} ➔ <b>{$ticket->status->telegramLabel()}</b>\n"
            ."👤 <b>Petugas:</b> {$e($actor)}\n"
            .($ticket->technicians->isNotEmpty() ? "👷 <b>Teknisi:</b> {$e($ticket->technicianNames())}\n" : '')
            .($ticket->modem ? "📟 <b>Modem:</b> {$e($ticket->modem)}\n" : '')
            .($notes ? "💬 <b>Catatan:</b> {$e($notes)}\n" : '')
            ."━━━━━━━━━━━━━━━━━━━━\n"
            .'⏰ <i>'.now()->format('d/m/Y H:i').'</i>';
    }

    public function urgencyMessage(Ticket $ticket, string $notes, string $urgencyLevel, string $actor): string
    {
        $e = self::escape(...);
        $header = match ($urgencyLevel) {
            'KRITIS' => '🚨🚨 <b>[URGENSI KRITIS / EMERGENCY]</b>',
            'MENDESAK' => '🚨 <b>[INFO URGENSI / FOLLOW UP CS]</b>',
            default => '📝 <b>[CATATAN TAMBAHAN CS]</b>',
        };

        return "{$header}\n"
            ."━━━━━━━━━━━━━━━━━━━━\n"
            ."🎫 <b>No. Tiket:</b> <code>{$e($ticket->ticket_number)}</code>\n"
            ."👤 <b>Pelanggan:</b> {$e($ticket->customer_name)} ({$e($ticket->customer_phone)})\n"
            ."📍 <b>Alamat:</b> {$e($ticket->customer_address ?: '-')}\n"
            ."⚡ <b>Status Saat Ini:</b> {$ticket->status->label()}\n"
            ."💬 <b>Catatan CS ({$e($actor)}):</b>\n<i>\"{$e($notes)}\"</i>\n"
            ."━━━━━━━━━━━━━━━━━━━━\n"
            .'⏰ <i>'.now()->format('d/m/Y H:i')."</i>\n"
            .'<i>Mohon Tim Terkait segera memprioritaskan tiket ini.</i>';
    }

    /**
     * @param  ?string  $recordedBy  Who pressed the button when it was not the technician (e.g. Helpdesk relaying a Telegram update).
     */
    public function technicianStartMessage(Ticket $ticket, string $technicians, ?string $recordedBy = null): string
    {
        $e = self::escape(...);

        return "🛠️ <b>[TEKNISI MULAI PENGERJAAN]</b>\n"
            ."━━━━━━━━━━━━━━━━━━━━\n"
            ."🎫 <b>No. Tiket:</b> <code>{$e($ticket->ticket_number)}</code>\n"
            ."👤 <b>Pelanggan:</b> {$e($ticket->customer_name)}\n"
            ."👷 <b>Teknisi Lapangan:</b> {$e($technicians)}\n"
            .($recordedBy ? "📋 <b>Dicatat oleh:</b> {$e($recordedBy)}\n" : '')
            ."📍 <b>Lokasi:</b> {$e($ticket->customer_address ?: '-')}\n"
            ."📝 <b>Tindakan:</b> Teknisi telah tiba di lokasi pelanggan dan mulai melakukan perbaikan fisik.\n"
            ."━━━━━━━━━━━━━━━━━━━━\n"
            .'⏰ <i>'.now()->format('d/m/Y H:i').'</i>';
    }

    public function incidentMessage(Incident $incident, IncidentAction $action, ?string $notes, string $actor): string
    {
        $e = self::escape(...);
        $header = match ($action) {
            IncidentAction::GAMAS_DIBUAT => '🚨📡 <b>[GANGGUAN MASSAL / GAMAS]</b>',
            IncidentAction::GAMAS_SELESAI => '✅📡 <b>[GAMAS SELESAI]</b>',
            default => '📡 <b>[UPDATE GAMAS]</b>',
        };

        return "{$header}\n"
            ."━━━━━━━━━━━━━━━━━━━━\n"
            ."🆔 <b>No. Gamas:</b> <code>{$e($incident->incident_number)}</code>\n"
            ."⚠️ <b>Gangguan:</b> {$e($incident->title)}\n"
            ."📍 <b>Area:</b> {$e($incident->area)}\n"
            ."👥 <b>Pelanggan Terdampak:</b> {$incident->tickets_count} tiket\n"
            ."🔄 <b>Status:</b> {$incident->status->telegramLabel()}\n"
            .($incident->technicians->isNotEmpty() ? "👷 <b>Teknisi:</b> {$e($incident->technicianNames())}\n" : '')
            .($incident->estimated_resolved_at && $incident->isOpen() ? "⏳ <b>Estimasi Selesai:</b> {$incident->estimated_resolved_at->format('d/m/Y H:i')}\n" : '')
            ."👤 <b>Petugas:</b> {$e($actor)}\n"
            .($notes ? "💬 <b>Catatan:</b> {$e($notes)}\n" : '')
            ."━━━━━━━━━━━━━━━━━━━━\n"
            .'⏰ <i>'.now()->format('d/m/Y H:i').'</i>';
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     */
    public function confirmationReminderMessage(Collection $tickets): string
    {
        $e = self::escape(...);
        $lines = $tickets->take(self::LIST_LIMIT)->map(function (Ticket $ticket) use ($e) {
            $closesAt = $ticket->autoCloseAt()?->format('d/m H:i') ?? '-';

            return "• <code>{$e($ticket->ticket_number)}</code> {$e($ticket->customer_name)} ({$e($ticket->customer_phone)})\n"
                ."   Selesai {$ticket->resolved_at?->format('d/m H:i')} · tutup otomatis {$closesAt}";
        })->implode("\n");
        $more = $tickets->count() > self::LIST_LIMIT ? "\n… dan ".($tickets->count() - self::LIST_LIMIT).' tiket lainnya' : '';

        return "⏰ <b>[REMINDER FOLLOW UP KONFIRMASI]</b>\n"
            ."━━━━━━━━━━━━━━━━━━━━\n"
            ."Ada <b>{$tickets->count()} tiket</b> menunggu konfirmasi pelanggan:\n\n"
            ."{$lines}{$more}\n"
            ."━━━━━━━━━━━━━━━━━━━━\n"
            .'<i>CS mohon follow up kembali pelanggan. Tiket tanpa respon '.(Ticket::CONFIRMATION_TIMEOUT_HOURS / 24).' hari akan ditutup otomatis.</i>';
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     */
    public function autoClosedMessage(Collection $tickets): string
    {
        $e = self::escape(...);
        $lines = $tickets->take(self::LIST_LIMIT)
            ->map(fn (Ticket $ticket) => "• <code>{$e($ticket->ticket_number)}</code> {$e($ticket->customer_name)}")
            ->implode("\n");
        $more = $tickets->count() > self::LIST_LIMIT ? "\n… dan ".($tickets->count() - self::LIST_LIMIT).' tiket lainnya' : '';

        return "🔒 <b>[TIKET DITUTUP OTOMATIS]</b>\n"
            ."━━━━━━━━━━━━━━━━━━━━\n"
            .'<b>'.$tickets->count().' tiket</b> ditutup karena tidak ada respon pelanggan '.(Ticket::CONFIRMATION_TIMEOUT_HOURS / 24)." hari:\n\n"
            ."{$lines}{$more}\n"
            ."━━━━━━━━━━━━━━━━━━━━\n"
            .'⏰ <i>'.now()->format('d/m/Y H:i').'</i>';
    }

    public function testMessage(): string
    {
        return "🔔 <b>[UJI COBA SISTEM TICKETING]</b>\nIntegrasi Telegram Ticketing berhasil terhubung!\n⏰ <i>"
            .now()->format('d/m/Y H:i').'</i>';
    }
}
