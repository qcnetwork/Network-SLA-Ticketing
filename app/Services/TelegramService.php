<?php

namespace App\Services;

use App\Enums\IncidentAction;
use App\Enums\TicketStatus;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\TelegramLog;
use App\Models\Ticket;
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
     * Send a message to the configured group. Without a bot token/chat ID the
     * message is only logged (simulator mode). Every message lands in telegram_logs.
     *
     * @return array{success: bool, error: ?string}
     */
    public function send(string $text, array $metadata = []): array
    {
        $botToken = (string) Setting::read('bot_token');
        $chatId = (string) Setting::read('chat_id');
        $configured = $botToken !== '' && $chatId !== '';
        $success = ! $configured;
        $error = null;

        if ($configured) {
            try {
                $response = Http::timeout(10)->asJson()->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                ]);
                $success = (bool) $response->json('ok');
                if (! $success) {
                    $error = $response->json('description') ?? 'Gagal mengirim ke Telegram API';
                }
            } catch (Throwable $e) {
                // HTTP client errors quote the request URL, which carries the bot token.
                $error = str_replace($botToken, '***', $e->getMessage());
                Log::warning('Telegram sendMessage failed', ['error' => $error, 'exception' => $e::class]);
            }
        }

        TelegramLog::create([
            'message' => $text,
            'is_real_sent' => $configured && $success,
            'simulated' => ! $configured,
            'error' => $error,
            'metadata' => $metadata,
        ]);

        $oldestKeptId = TelegramLog::query()->orderByDesc('id')->skip(TelegramLog::KEEP - 1)->value('id');
        if ($oldestKeptId) {
            TelegramLog::query()->where('id', '<', $oldestKeptId)->delete();
        }

        return ['success' => $success, 'error' => $error];
    }

    public static function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_NOQUOTES, 'UTF-8');
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
