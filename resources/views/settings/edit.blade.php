<x-layouts.app title="Pengaturan">
    <div class="mx-auto max-w-2xl space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Pengaturan</h1>
            <p class="text-sm text-slate-500">Pilihan tiket, integrasi bot Telegram, dan nomor CS.</p>
        </div>

        <div class="card divide-y divide-slate-100">
            <h2 class="flex items-center gap-2 p-6 pb-4 font-semibold"><i data-lucide="clipboard-list" class="h-4 w-4 text-indigo-600"></i>Pilihan Tiket</h2>
            @foreach ([
                ['route' => 'categories.index', 'icon' => 'tag', 'title' => 'Kategori Kendala', 'description' => 'Pilihan kategori saat membuat tiket dan mengubah kategori.', 'count' => $categoryCount],
                ['route' => 'modems.index', 'icon' => 'router', 'title' => 'Jenis Modem / ONT', 'description' => 'Saran tipe modem saat Helpdesk mencatat perangkat pelanggan.', 'count' => $modemCount],
            ] as $link)
                <a href="{{ route($link['route']) }}" class="flex items-center gap-3 px-6 py-4 transition hover:bg-slate-50">
                    <i data-lucide="{{ $link['icon'] }}" class="h-5 w-5 shrink-0 text-slate-400"></i>
                    <div class="min-w-0 flex-1">
                        <div class="font-medium text-slate-800">{{ $link['title'] }}</div>
                        <div class="text-sm text-slate-500">{{ $link['description'] }}</div>
                    </div>
                    <span class="shrink-0 text-xs text-slate-500">{{ $link['count'] }} aktif</span>
                    <i data-lucide="chevron-right" class="h-4 w-4 shrink-0 text-slate-300"></i>
                </a>
            @endforeach
        </div>

        <form method="POST" action="{{ route('settings.update') }}" class="card space-y-4 p-6">
            @csrf
            @method('PUT')
            <h2 class="flex items-center gap-2 font-semibold"><i data-lucide="send" class="h-4 w-4 text-sky-600"></i>Bot Telegram</h2>
            <p class="text-sm text-slate-500">Kosongkan token atau Chat ID untuk memakai mode simulator. Pesan hanya tampil di Feed Telegram dan tidak dikirim ke Telegram.</p>

            <div>
                <label class="label" for="bot_token">Bot Token</label>
                <input id="bot_token" name="bot_token" type="password" value="{{ old('bot_token', $settings['bot_token']) }}" class="input font-mono" autocomplete="off" placeholder="123456789:ABCdef...">
                <x-field-error name="bot_token" />
            </div>
            <div>
                <label class="label" for="chat_id">Group Chat ID</label>
                <input id="chat_id" name="chat_id" value="{{ old('chat_id', $settings['chat_id']) }}" class="input font-mono" placeholder="-1001234567890">
                <x-field-error name="chat_id" />
            </div>
            <div class="rounded-lg bg-slate-50 p-4">
                <p class="mb-3 text-sm text-slate-600">Untuk grup dengan topic: isi ID topic, yaitu angka terakhir di link topic (klik kanan topic, pilih Copy Link: <span class="font-mono text-xs">t.me/c/123456/<b>7</b></span>).</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="log_topic_id">Topic Progress / Log</label>
                        <input id="log_topic_id" name="log_topic_id" value="{{ old('log_topic_id', $settings['log_topic_id']) }}" class="input font-mono" inputmode="numeric" placeholder="Kosong = chat umum">
                        <p class="mt-1 text-xs text-slate-500">Notifikasi tiket baru, perpindahan node, dan update lain.</p>
                        <x-field-error name="log_topic_id" />
                    </div>
                    <div>
                        <label class="label" for="list_topic_id">Topic ListTicket</label>
                        <input id="list_topic_id" name="list_topic_id" value="{{ old('list_topic_id', $settings['list_topic_id']) }}" class="input font-mono" inputmode="numeric" placeholder="Kosong = list nonaktif">
                        <p class="mt-1 text-xs text-slate-500">Satu master list per hari yang otomatis diperbarui.</p>
                        <x-field-error name="list_topic_id" />
                    </div>
                </div>
            </div>
            <div class="space-y-2">
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="notify_on_new_ticket" value="1" @checked($settings['notify_on_new_ticket'] === '1') class="rounded border-slate-300 text-indigo-600">
                    Kirim notifikasi saat tiket baru dibuat
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="notify_on_status_change" value="1" @checked($settings['notify_on_status_change'] === '1') class="rounded border-slate-300 text-indigo-600">
                    Kirim notifikasi saat status tiket berubah
                </label>
            </div>

            <h2 class="flex items-center gap-2 pt-2 font-semibold"><i data-lucide="phone" class="h-4 w-4 text-emerald-600"></i>Customer Service</h2>
            <div>
                <label class="label" for="cs_phone">No. WhatsApp CS</label>
                <input id="cs_phone" name="cs_phone" value="{{ old('cs_phone', $settings['cs_phone']) }}" class="input" inputmode="tel">
                <x-field-error name="cs_phone" />
            </div>

            <div class="flex justify-end pt-2">
                <button class="btn-primary"><i data-lucide="save" class="h-4 w-4"></i>Simpan Pengaturan</button>
            </div>
        </form>

        <form method="POST" action="{{ route('settings.test') }}" class="card flex flex-col gap-3 p-6 sm:flex-row sm:items-center sm:justify-between">
            @csrf
            <div>
                <h2 class="font-semibold">Uji Koneksi Telegram</h2>
                <p class="text-sm text-slate-500">Kirim pesan uji coba memakai pengaturan yang tersimpan.</p>
            </div>
            <button class="btn-secondary"><i data-lucide="send" class="h-4 w-4"></i>Kirim Pesan Uji</button>
        </form>
    </div>
</x-layouts.app>
