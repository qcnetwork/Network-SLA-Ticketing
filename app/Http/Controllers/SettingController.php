<?php

namespace App\Http\Controllers;

use App\Models\ModemType;
use App\Models\Setting;
use App\Models\TicketCategory;
use App\Services\DatabaseBackup;
use App\Services\TelegramService;
use App\Services\TelegramTicketList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SettingController extends Controller
{
    public function edit(): View
    {
        return view('settings.edit', [
            'settings' => Setting::values(),
            'categoryCount' => TicketCategory::query()->where('active', true)->count(),
            'modemCount' => ModemType::query()->where('active', true)->count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'bot_token' => ['nullable', 'string', 'max:100', 'regex:/^\d+:[A-Za-z0-9_-]+$/'],
            'chat_id' => ['nullable', 'string', 'max:50', 'regex:/^-?\d+$|^@\w+$/'],
            'log_topic_id' => ['nullable', 'integer', 'min:1'],
            'list_topic_id' => ['nullable', 'integer', 'min:1'],
            'cs_phone' => ['nullable', 'string', 'max:30'],
            'notify_on_new_ticket' => ['nullable', 'boolean'],
            'notify_on_status_change' => ['nullable', 'boolean'],
            'clear_bot_token' => ['nullable', 'boolean'],
        ], [
            'bot_token.regex' => 'Format Bot Token tidak valid (contoh: 123456:ABC-xyz)',
            'chat_id.regex' => 'Chat ID harus berupa angka (contoh: -1001234567890) atau @namachannel',
            'log_topic_id.*' => 'Topic ID harus berupa angka (lihat angka terakhir di link topic)',
            'list_topic_id.*' => 'Topic ID harus berupa angka (lihat angka terakhir di link topic)',
        ]);

        $listTarget = [$data['chat_id'] ?? '', (string) ($data['list_topic_id'] ?? '')];
        // A list message in the old chat or topic cannot be edited from the new one.
        $listMoved = $listTarget !== [(string) Setting::read('chat_id'), (string) Setting::read('list_topic_id')];

        // The form never shows the stored token, so a blank field means "keep it".
        $botToken = match (true) {
            $request->boolean('clear_bot_token') => '',
            filled($data['bot_token'] ?? null) => $data['bot_token'],
            default => (string) Setting::read('bot_token'),
        };

        Setting::write([
            'bot_token' => $botToken,
            'chat_id' => $listTarget[0],
            'log_topic_id' => (string) ($data['log_topic_id'] ?? ''),
            'list_topic_id' => $listTarget[1],
            'cs_phone' => preg_replace('/\D/', '', (string) ($data['cs_phone'] ?? '')),
            'notify_on_new_ticket' => $request->boolean('notify_on_new_ticket') ? '1' : '0',
            'notify_on_status_change' => $request->boolean('notify_on_status_change') ? '1' : '0',
            ...($listMoved ? ['ticket_list_message_id' => '', 'ticket_list_date' => ''] : []),
        ]);
        TelegramTicketList::queueRefresh();

        return redirect()->route('settings.edit')->with('success', 'Pengaturan berhasil disimpan');
    }

    /**
     * Download a snapshot of the database. It holds password hashes, the bot token and every
     * customer's details, so the supervisor confirms their password first and the download is logged.
     */
    public function backup(Request $request, DatabaseBackup $backup): BinaryFileResponse
    {
        $request->validate(
            ['password' => ['required', 'current_password']],
            ['password.current_password' => 'Password salah'],
            ['password' => 'password'],
        );

        $path = $backup->create();
        Log::info('Database backup downloaded', ['user' => $request->user()->username, 'ip' => $request->ip()]);

        return response()->download($path, basename($path), ['Content-Type' => 'application/vnd.sqlite3'])->deleteFileAfterSend();
    }

    /**
     * Point the bot's webhook at this server and publish its command menu. Telegram only calls
     * HTTPS URLs, so this must be done while the app is open on its HTTPS domain.
     */
    public function enableCommands(TelegramService $telegram): RedirectResponse
    {
        $token = (string) Setting::read('bot_token');
        if ($token === '') {
            return back()->withErrors(['telegram' => 'Isi dan simpan Bot Token terlebih dahulu']);
        }
        $url = route('telegram.webhook');
        if (! str_starts_with($url, 'https://')) {
            return back()->withErrors(['telegram' => "Telegram hanya bisa memanggil alamat HTTPS. Buka aplikasi lewat domain HTTPS-nya lalu coba lagi (sekarang: {$url})."]);
        }

        $secret = Str::random(48);
        $responses = $telegram->callMany($token, [
            'webhook' => ['setWebhook', ['url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['message'], 'drop_pending_updates' => true]],
            'commands' => ['setMyCommands', ['commands' => [
                ['command' => 'detailtiket', 'description' => 'Kirim detail tiket ke DM, contoh: /detailtiket TCK-20261022-0001'],
            ]]],
        ]);
        if (! ($responses['webhook']['ok'] ?? false)) {
            return back()->withErrors(['telegram' => 'Gagal mengaktifkan perintah bot: '.($responses['webhook']['description'] ?? 'tidak ada respon')]);
        }

        Setting::write(['webhook_secret' => $secret, 'webhook_url' => $url]);

        return back()->with('success', 'Perintah bot aktif. Coba ketik /detailtiket di grup atau DM bot.');
    }

    public function test(TelegramService $telegram): RedirectResponse
    {
        $result = $telegram->send($telegram->testMessage(), ['action' => 'TEST_CONNECTION']);

        if (! $result['success']) {
            return back()->withErrors(['telegram' => 'Gagal mengirim ke Telegram: '.$result['error']]);
        }

        $simulated = blank(Setting::read('bot_token')) || blank(Setting::read('chat_id'));

        return back()->with('success', $simulated
            ? 'Bot belum dikonfigurasi. Pesan uji coba masuk ke feed simulator.'
            : 'Pesan uji coba berhasil dikirim ke grup Telegram');
    }
}
