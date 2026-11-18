<x-layouts.app title="Dashboard">
    @php
        $user = auth()->user();
        $hour = now()->hour;
        $greeting = match (true) { $hour < 11 => 'Selamat pagi', $hour < 15 => 'Selamat siang', $hour < 18 => 'Selamat sore', default => 'Selamat malam' };
        $cards = $isTechnician
            ? [
                ['label' => 'Tugas Perbaikan', 'value' => $attention->firstWhere('status', App\Enums\TicketStatus::PERBAIKAN)['count'] ?? 0, 'icon' => 'wrench', 'accent' => 'border-t-orange-500', 'url' => route('complaints.index')],
                ['label' => 'Tugas Survey PSB', 'value' => $attention->firstWhere('status', App\Enums\TicketStatus::PSB_SURVEY)['count'] ?? 0, 'icon' => 'compass', 'accent' => 'border-t-blue-500', 'url' => route('installations.index')],
                ['label' => 'Tugas Pasang PSB', 'value' => $attention->firstWhere('status', App\Enums\TicketStatus::PSB_PEMASANGAN)['count'] ?? 0, 'icon' => 'house-plus', 'accent' => 'border-t-indigo-500', 'url' => route('installations.index')],
            ]
            : array_values(array_filter([
                ['label' => 'Komplain Terbuka', 'value' => $stats['complaintsOpen'], 'icon' => 'ticket', 'accent' => 'border-t-amber-400', 'url' => route('complaints.index')],
                ['label' => 'Komplain Masuk Hari Ini', 'value' => $stats['complaintsToday'], 'icon' => 'inbox', 'accent' => 'border-t-cyan-500', 'url' => route('complaints.index')],
                ['label' => 'Komplain Selesai Hari Ini', 'value' => $stats['complaintsClosedToday'], 'icon' => 'check-circle-2', 'accent' => 'border-t-emerald-500', 'url' => route('complaints.index', ['status' => 'TUTUP'])],
                $seesInstallations ? ['label' => 'PSB Berjalan', 'value' => $stats['installationsOpen'], 'icon' => 'house-plus', 'accent' => 'border-t-indigo-500', 'url' => route('installations.index'),
                    'note' => $stats['installationsActiveThisMonth'].' pelanggan baru aktif bulan ini'] : null,
            ]));
        $waiting = $attention->sum('count') + $pendingMaterial + $unpaidSales;
    @endphp

    {{-- dashboardLive keeps the notification log and the [data-live] blocks current without a reload. --}}
    <div x-data="dashboardLive()">
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">{{ $greeting }}, {{ Str::before($user->name, ' ') ?: $user->name }}</h1>
                <p class="text-sm text-slate-500">{{ now()->translatedFormat('l, d F Y') }} · {{ $user->role->label() }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('create', App\Models\Ticket::class)
                    <a href="{{ route('tickets.create') }}" class="btn-primary"><i data-lucide="plus" class="h-4 w-4"></i>Tiket Komplain</a>
                @endcan
                @if ($seesInstallations && $user->can('createInstallation', App\Models\Ticket::class))
                    <a href="{{ route('installations.create') }}" class="btn-secondary"><i data-lucide="house-plus" class="h-4 w-4 text-indigo-600"></i>PSB Baru</a>
                @endif
            </div>
        </div>

        <div data-live="incidents">
            @if ($openIncidents->isNotEmpty())
                <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4">
                    <div class="mb-2 flex items-center gap-2 text-sm font-semibold text-red-800"><i data-lucide="radio-tower" class="h-4 w-4"></i>{{ $openIncidents->count() }} gangguan massal sedang berlangsung</div>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($openIncidents as $incident)
                            <a href="{{ route('incidents.show', $incident) }}" class="rounded-lg border border-red-200 bg-white px-3 py-2 text-sm transition hover:border-red-400">
                                <span class="font-mono text-xs font-semibold text-red-700">{{ $incident->incident_number }}</span>
                                <span class="font-medium text-slate-800">{{ $incident->area }}</span>
                                <span class="text-xs text-slate-500">· {{ $incident->tickets_count }} pelanggan</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="min-w-0 space-y-6 lg:col-span-2">
                {{-- Headline figures --}}
                <div data-live="cards" @class(['grid gap-3', 'grid-cols-1 sm:grid-cols-3' => $isTechnician, 'grid-cols-2 xl:grid-cols-4' => ! $isTechnician])>
                    @foreach ($cards as $card)
                        <a href="{{ $card['url'] }}" class="card border-t-4 p-4 transition hover:shadow-md {{ $card['accent'] }}">
                            <div class="flex items-center justify-between gap-2 text-xs font-medium text-slate-500">{{ $card['label'] }}<i data-lucide="{{ $card['icon'] }}" class="h-4 w-4 shrink-0 text-slate-400"></i></div>
                            <div class="mt-1 text-3xl font-bold text-slate-900">{{ $card['value'] }}</div>
                            @isset($card['note'])
                                <div class="mt-1 text-xs text-slate-500">{{ $card['note'] }}</div>
                            @endisset
                        </a>
                    @endforeach
                </div>

                {{-- What waits on this role --}}
                <div data-live="attention">
                    @if ($isTechnician)
                        <div class="card">
                            <h2 class="flex items-center gap-2 border-b border-slate-100 px-5 py-3 font-semibold text-slate-800"><i data-lucide="list-todo" class="h-4 w-4 text-indigo-600"></i>Tugas Saya</h2>
                            <ul class="divide-y divide-slate-100">
                                @forelse ($myVisits as $ticket)
                                    <li>
                                        <a href="{{ route('tickets.show', $ticket) }}" class="flex items-start gap-3 px-5 py-3 transition hover:bg-slate-50">
                                            <i data-lucide="{{ $ticket->status->icon() }}" class="mt-0.5 h-4 w-4 shrink-0 text-orange-500"></i>
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate text-sm font-semibold text-slate-800">{{ $ticket->customer_name }}</span>
                                                <span class="block text-xs text-slate-500"><span class="font-mono">{{ $ticket->ticket_number }}</span> · {{ $ticket->status->label() }}</span>
                                                @if ($ticket->customer_address)
                                                    <span class="block truncate text-xs text-slate-400">{{ $ticket->customer_address }}</span>
                                                @endif
                                            </span>
                                            <i data-lucide="chevron-right" class="mt-0.5 h-4 w-4 shrink-0 text-slate-300"></i>
                                        </a>
                                    </li>
                                @empty
                                    <li class="px-5 py-8 text-center text-sm text-slate-400">Belum ada tugas. Istirahat dulu.</li>
                                @endforelse
                            </ul>
                        </div>
                    @elseif ($attention->isNotEmpty() || $pendingMaterial || $unpaidSales)
                        <div class="card">
                            <div class="flex items-center justify-between gap-2 border-b border-slate-100 px-5 py-3">
                                <h2 class="flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="bell-ring" class="h-4 w-4 text-indigo-600"></i>Perlu Ditangani</h2>
                                <span @class(['rounded-full px-2 text-xs font-semibold', 'bg-rose-100 text-rose-700' => $waiting, 'bg-slate-100 text-slate-500' => ! $waiting])>{{ $waiting }}</span>
                            </div>
                            <ul class="grid sm:grid-cols-2">
                                @foreach ($attention as $item)
                                    <li class="border-b border-slate-100 sm:odd:border-r">
                                        <a href="{{ $item['url'] }}" @class(['flex items-center gap-3 px-5 py-3 transition hover:bg-slate-50', 'opacity-60' => ! $item['count']])>
                                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border {{ $item['status']->badgeClasses() }}"><i data-lucide="{{ $item['status']->icon() }}" class="h-4 w-4"></i></span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate text-sm font-medium text-slate-800">{{ $item['status']->label() }}</span>
                                                <span class="block text-xs text-slate-500">{{ $item['status']->kind() === App\Enums\TicketKind::PSB ? 'Pasang Baru' : 'Komplain' }}</span>
                                            </span>
                                            <span class="text-lg font-bold text-slate-900">{{ $item['count'] }}</span>
                                        </a>
                                    </li>
                                @endforeach
                                @if ($pendingMaterial)
                                    <li class="border-b border-slate-100 sm:odd:border-r">
                                        <a href="{{ route('warehouses.index') }}" class="flex items-center gap-3 px-5 py-3 transition hover:bg-slate-50">
                                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-amber-300 bg-amber-100 text-amber-800"><i data-lucide="package" class="h-4 w-4"></i></span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block text-sm font-medium text-slate-800">Material menunggu ACC</span>
                                                <span class="block text-xs text-slate-500">Gudang</span>
                                            </span>
                                            <span class="text-lg font-bold text-slate-900">{{ $pendingMaterial }}</span>
                                        </a>
                                    </li>
                                @endif
                                @if ($unpaidSales)
                                    <li class="border-b border-slate-100 sm:odd:border-r">
                                        <a href="{{ route('sales.index', ['status' => 'BELUM_LUNAS']) }}" class="flex items-center gap-3 px-5 py-3 transition hover:bg-slate-50">
                                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-sky-300 bg-sky-100 text-sky-800"><i data-lucide="receipt" class="h-4 w-4"></i></span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block text-sm font-medium text-slate-800">Penjualan belum lunas</span>
                                                <span class="block text-xs text-slate-500">Gudang</span>
                                            </span>
                                            <span class="text-lg font-bold text-slate-900">{{ $unpaidSales }}</span>
                                        </a>
                                    </li>
                                @endif
                            </ul>
                        </div>
                    @endif
                </div>

                {{-- The way into each menu --}}
                <section data-live="menus">
                    <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-600"><i data-lucide="layout-grid" class="h-4 w-4"></i>Menu</h2>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($menus as $menu)
                            <a href="{{ route($menu['route']) }}" class="card group flex items-center gap-3 p-3 transition hover:border-indigo-300 hover:shadow-md" title="{{ $menu['description'] }}">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600 transition group-hover:bg-indigo-600 group-hover:text-white">
                                    <i data-lucide="{{ $menu['icon'] }}" class="h-4 w-4"></i>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-slate-800">{{ $menu['label'] }}</span>
                                    <span class="block truncate text-xs text-slate-500">{{ $menu['figure'] ?? $menu['description'] }}</span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                </section>
            </div>

            {{-- Live notification log: follows the navbar bell's polling, newest on top. --}}
            <aside class="min-w-0">
                <div class="card flex flex-col lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)]">
                    <div class="flex items-center justify-between gap-2 border-b border-slate-100 px-5 py-3">
                        <h2 class="flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="bell" class="h-4 w-4 text-indigo-600"></i>Log Notifikasi</h2>
                        <span class="flex items-center gap-2 text-xs text-slate-500">
                            <span x-show="unread" x-cloak x-text="unread + ' baru'" class="rounded-full bg-rose-100 px-2 font-semibold text-rose-700"></span>
                            <span class="flex items-center gap-1.5" :title="updatedAt ? 'Diperbarui ' + updatedAt : 'Menghubungkan...'">
                                <span class="relative flex h-2 w-2">
                                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                                    <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                                </span>
                                Live
                            </span>
                        </span>
                    </div>
                    <ul class="max-h-[32rem] flex-1 divide-y divide-slate-100 overflow-y-auto lg:max-h-none">
                        <template x-for="item in items" :key="item.id">
                            <li>
                                <a :href="item.url" class="flex gap-3 px-5 py-3 transition-colors duration-700 hover:bg-slate-50"
                                    :class="flash.has(item.id) ? 'bg-amber-50' : (item.read ? '' : 'bg-indigo-50/40')">
                                    <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full"
                                        :class="item.read ? 'bg-slate-100 text-slate-400' : 'bg-indigo-100 text-indigo-600'">
                                        <i :data-lucide="item.icon" class="h-3.5 w-3.5"></i>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-start justify-between gap-2">
                                            <span class="text-sm text-slate-800" :class="item.read ? 'font-medium' : 'font-semibold'" x-text="item.title"></span>
                                            <span x-show="! item.read" class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-indigo-500"></span>
                                        </span>
                                        <span class="line-clamp-2 block text-xs text-slate-600" x-text="item.body"></span>
                                        <span class="mt-0.5 block text-[11px] text-slate-400" x-text="item.time"></span>
                                    </span>
                                </a>
                            </li>
                        </template>
                        <li x-show="! loaded" class="px-5 py-8 text-center text-sm text-slate-400">Memuat notifikasi...</li>
                        <li x-show="loaded && ! items.length" x-cloak class="px-5 py-8 text-center text-sm text-slate-400">Belum ada notifikasi</li>
                    </ul>
                    <div class="flex items-center justify-between gap-2 border-t border-slate-100 px-5 py-2.5 text-xs">
                        <span class="text-slate-400" x-text="updatedAt ? 'Diperbarui ' + updatedAt : ''"></span>
                        <a href="{{ route('notifications.history') }}" class="font-semibold text-indigo-600 hover:underline">Lihat semua</a>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</x-layouts.app>
