<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\TelegramService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function edit(): View
    {
        return view('settings.edit', ['settings' => Setting::values()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'bot_token' => ['nullable', 'string', 'max:100', 'regex:/^\d+:[A-Za-z0-9_-]+$/'],
            'chat_id' => ['nullable', 'string', 'max:50', 'regex:/^-?\d+$|^@\w+$/'],
            'cs_phone' => ['nullable', 'string', 'max:30'],
            'notify_on_new_ticket' => ['nullable', 'boolean'],
            'notify_on_status_change' => ['nullable', 'boolean'],
        ], [
            'bot_token.regex' => 'Format Bot Token tidak valid (contoh: 123456:ABC-xyz)',
            'chat_id.regex' => 'Chat ID harus berupa angka (contoh: -1001234567890) atau @namachannel',
        ]);

        Setting::write([
            'bot_token' => $data['bot_token'] ?? '',
            'chat_id' => $data['chat_id'] ?? '',
            'cs_phone' => preg_replace('/\D/', '', (string) ($data['cs_phone'] ?? '')),
            'notify_on_new_ticket' => $request->boolean('notify_on_new_ticket') ? '1' : '0',
            'notify_on_status_change' => $request->boolean('notify_on_status_change') ? '1' : '0',
        ]);

        return redirect()->route('settings.edit')->with('success', 'Pengaturan berhasil disimpan');
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
