<div x-data="notificationBell(@js(route('notifications.index')), @js(route('notifications.read-all')))" class="relative" @keydown.escape.window="open = false">
    <button type="button" @click="open = ! open" class="relative rounded-lg p-2 text-slate-600 hover:bg-slate-100" aria-label="Notifikasi" :aria-expanded="open">
        <i data-lucide="bell" class="h-5 w-5"></i>
        <span x-show="unread" x-cloak x-text="unread > 99 ? '99+' : unread"
            class="absolute -top-0.5 -right-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-600 px-1 text-[10px] font-bold text-white"></span>
    </button>

    <div x-show="open" x-cloak @click.outside="open = false"
        class="absolute right-0 z-40 mt-2 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
        <div class="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
            <span class="text-sm font-semibold text-slate-800">Notifikasi</span>
            <div class="flex items-center gap-1">
                <button type="button" @click="toggleMute()" class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100" :title="muted ? 'Bunyi mati, klik untuk menyalakan' : 'Bunyi nyala, klik untuk mematikan'">
                    <i data-lucide="volume-2" class="h-4 w-4" x-show="! muted"></i>
                    <i data-lucide="volume-x" class="h-4 w-4" x-show="muted" x-cloak></i>
                </button>
                <button type="button" @click="readAll()" x-show="unread" x-cloak class="rounded-md px-2 py-1 text-xs font-semibold text-indigo-600 hover:bg-indigo-50">Tandai semua dibaca</button>
            </div>
        </div>
        {{-- Desktop notifications reach you on any tab or app, but browsers only offer them over https. --}}
        <div x-show="desktop !== 'granted' && desktop !== 'unsupported'" x-cloak class="border-b border-slate-100 bg-slate-50 px-4 py-2 text-xs text-slate-600">
            <button type="button" x-show="desktop === 'default'" @click="enableDesktop()" class="font-semibold text-indigo-600 hover:underline">Nyalakan notifikasi desktop</button>
            <span x-show="desktop === 'denied'">Notifikasi desktop diblokir. Izinkan lewat ikon gembok di address bar.</span>
            <span x-show="desktop === 'insecure'">Notifikasi desktop butuh alamat https. Saat di tab lain, cek angka di ikon dan judul tab.</span>
        </div>
        <ul class="max-h-96 divide-y divide-slate-100 overflow-y-auto">
            <template x-for="item in items" :key="item.id">
                <li>
                    <a :href="item.url" class="flex gap-3 px-4 py-3 hover:bg-slate-50" :class="item.read ? '' : 'bg-indigo-50/40'">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="item.read ? 'bg-transparent' : 'bg-indigo-500'"></span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold text-slate-800" x-text="item.title"></span>
                            <span class="block text-xs text-slate-600" x-text="item.body"></span>
                            <span class="mt-0.5 block text-[11px] text-slate-400" x-text="item.time"></span>
                        </span>
                    </a>
                </li>
            </template>
            <li x-show="! items.length" class="px-4 py-8 text-center text-sm text-slate-400">Belum ada notifikasi</li>
        </ul>
        <a href="{{ route('notifications.history') }}" class="block border-t border-slate-100 px-4 py-2.5 text-center text-xs font-semibold text-indigo-600 hover:bg-indigo-50">Lihat semua notifikasi</a>
    </div>

    {{-- Pop-ups for notifications that arrive while the page is open. Teleported to <body>: the header's
         backdrop blur makes it the containing block for fixed children, which pinned them to the header. --}}
    <template x-teleport="body">
        <div class="pointer-events-none fixed right-4 bottom-4 z-50 flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2">
            <template x-for="toast in toasts" :key="toast.id">
                <div class="pointer-events-auto flex gap-3 rounded-xl border border-indigo-200 bg-white p-4 shadow-lg">
                    <a :href="toast.url" class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-slate-800" x-text="toast.title"></span>
                        <span class="block text-xs text-slate-600" x-text="toast.body"></span>
                    </a>
                    <button type="button" @click="dismiss(toast.id)" class="shrink-0 text-slate-400 hover:text-slate-700" aria-label="Tutup">&times;</button>
                </div>
            </template>
        </div>
    </template>
</div>
