@use('App\Enums\Role')
@props(['title' => null])
@php
    $user = auth()->user();
    // Day-to-day pages stay in the bar; the rest sits in the "Lainnya" menu so the bar does not overflow.
    $nav = array_values(array_filter([
        ['route' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'active' => 'dashboard'],
        ['route' => 'incidents.index', 'label' => 'Gamas', 'icon' => 'radio-tower', 'active' => 'incidents.*'],
        $user->can('create', App\Models\Ticket::class)
            ? ['route' => 'tickets.create', 'label' => 'Tiket Baru', 'icon' => 'plus-circle', 'active' => 'tickets.create'] : null,
        $user->hasRole(Role::HELPDESK, Role::SPV, Role::TEKNISI)
            ? ['route' => 'archive.index', 'label' => 'Arsip', 'icon' => 'archive', 'active' => 'archive.*'] : null,
    ]));
    $more = array_values(array_filter([
        $user->hasRole(Role::HELPDESK, Role::SPV, Role::CS)
            ? ['route' => 'reports.index', 'label' => 'Laporan', 'icon' => 'bar-chart-3', 'active' => 'reports.*', 'group' => 'Pantau'] : null,
        $user->hasRole(Role::HELPDESK, Role::SPV)
            ? ['route' => 'telegram.logs', 'label' => 'Feed Telegram', 'icon' => 'send', 'active' => 'telegram.*', 'group' => 'Pantau'] : null,
        $user->hasRole(Role::SPV)
            ? ['route' => 'users.index', 'label' => 'Pengguna', 'icon' => 'users', 'active' => 'users.*', 'group' => 'Kelola'] : null,
        $user->hasRole(Role::SPV)
            ? ['route' => 'settings.edit', 'label' => 'Pengaturan', 'icon' => 'settings', 'active' => ['settings.*', 'categories.*', 'modems.*'], 'group' => 'Kelola'] : null,
    ]));
    // A menu of one is just an extra click.
    if (count($more) === 1) {
        $nav[] = $more[0];
        $more = [];
    }
    $moreActive = collect($more)->contains(fn ($item) => request()->routeIs($item['active']));
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}QCNet Ticketing</title>
    <link rel="icon" href="/icons/logo-mark.png" type="image/png">
    @include('partials.pwa-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
    <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur" x-data="{ open: false }">
        <div class="mx-auto flex h-16 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2 font-bold text-indigo-700">
                <img src="/icons/logo-mark.png" alt="" class="h-9 w-9 rounded-lg">
                <span class="text-lg">QCNet Ticketing</span>
            </a>

            <nav class="hidden items-center gap-1 lg:flex">
                @foreach ($nav as $item)
                    <a href="{{ route($item['route']) }}"
                       @class([
                           'flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition',
                           'bg-indigo-50 text-indigo-700' => request()->routeIs($item['active']),
                           'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs($item['active']),
                       ])>
                        <i data-lucide="{{ $item['icon'] }}" class="h-4 w-4"></i>{{ $item['label'] }}
                    </a>
                @endforeach

                @if ($more)
                    <div class="relative" x-data="{ menu: false }" @click.outside="menu = false" @keydown.escape.window="menu = false">
                        <button type="button" @click="menu = ! menu" :aria-expanded="menu" aria-haspopup="true"
                                @class([
                                    'flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium transition',
                                    'bg-indigo-50 text-indigo-700' => $moreActive,
                                    'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! $moreActive,
                                ])>
                            Lainnya<i data-lucide="chevron-down" class="h-4 w-4 transition" :class="menu && 'rotate-180'"></i>
                        </button>
                        <div x-show="menu" x-cloak x-transition.origin.top.right
                             class="absolute right-0 mt-2 w-56 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg">
                            @foreach (collect($more)->groupBy('group') as $group => $items)
                                <div @class(['px-3 pt-2 pb-1 text-[11px] font-semibold tracking-wide text-slate-400 uppercase', 'mt-1 border-t border-slate-100' => ! $loop->first])>{{ $group }}</div>
                                @foreach ($items as $item)
                                    <a href="{{ route($item['route']) }}"
                                       @class([
                                           'flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition',
                                           'bg-indigo-50 text-indigo-700' => request()->routeIs($item['active']),
                                           'text-slate-700 hover:bg-slate-100' => ! request()->routeIs($item['active']),
                                       ])>
                                        <i data-lucide="{{ $item['icon'] }}" class="h-4 w-4"></i>{{ $item['label'] }}
                                    </a>
                                @endforeach
                            @endforeach
                        </div>
                    </div>
                @endif
            </nav>

            <div class="flex items-center gap-2 lg:gap-3">
                <x-notification-bell />

                <div class="relative hidden lg:block" x-data="{ menu: false }" @click.outside="menu = false" @keydown.escape.window="menu = false">
                    <button type="button" @click="menu = ! menu" :aria-expanded="menu" aria-haspopup="true"
                            class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-right leading-tight hover:bg-slate-100">
                        <span>
                            <span class="block text-sm font-semibold">{{ $user->name }}</span>
                            <span class="block text-xs text-slate-500">{{ $user->role->label() }}</span>
                        </span>
                        <i data-lucide="chevron-down" class="h-4 w-4 text-slate-400 transition" :class="menu && 'rotate-180'"></i>
                    </button>
                    <div x-show="menu" x-cloak x-transition.origin.top.right
                         class="absolute right-0 mt-2 w-52 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg">
                        <a href="{{ route('profile.edit') }}" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                            <i data-lucide="user" class="h-4 w-4"></i>Profil Saya
                        </a>
                        <a href="{{ route('notifications.history') }}" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                            <i data-lucide="bell" class="h-4 w-4"></i>Notifikasi Saya
                        </a>
                        <form method="POST" action="{{ route('logout') }}" class="mt-1 border-t border-slate-100 pt-1">
                            @csrf
                            <button class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-rose-600 hover:bg-rose-50">
                                <i data-lucide="log-out" class="h-4 w-4"></i>Keluar
                            </button>
                        </form>
                    </div>
                </div>

                <button class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" @click="open = !open" aria-label="Menu">
                    <i data-lucide="menu" class="h-6 w-6"></i>
                </button>
            </div>
        </div>

        <div class="border-t border-slate-200 bg-white px-4 py-3 lg:hidden" x-show="open" x-cloak>
            <div class="mb-3 border-b border-slate-100 pb-3">
                <div class="text-sm font-semibold">{{ $user->name }}</div>
                <div class="text-xs text-slate-500">{{ $user->role->label() }}</div>
            </div>
            @php($sectionHeading = 'mt-2 border-t border-slate-100 px-3 pt-3 pb-1 text-[11px] font-semibold tracking-wide text-slate-400 uppercase')
            @foreach (['' => $nav, ...collect($more)->groupBy('group')->all()] as $group => $items)
                @if ($group !== '')
                    <div class="{{ $sectionHeading }}">{{ $group }}</div>
                @endif
                @foreach ($items as $item)
                    <a href="{{ route($item['route']) }}"
                       @class([
                           'flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium',
                           'bg-indigo-50 text-indigo-700' => request()->routeIs($item['active']),
                           'text-slate-700 hover:bg-slate-100' => ! request()->routeIs($item['active']),
                       ])>
                        <i data-lucide="{{ $item['icon'] }}" class="h-4 w-4"></i>{{ $item['label'] }}
                    </a>
                @endforeach
            @endforeach
            <div class="{{ $sectionHeading }}">Akun</div>
            <a href="{{ route('notifications.history') }}" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                <i data-lucide="bell" class="h-4 w-4"></i>Notifikasi Saya
            </a>
            <a href="{{ route('profile.edit') }}" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                <i data-lucide="user" class="h-4 w-4"></i>Profil Saya
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-rose-600 hover:bg-rose-50">
                    <i data-lucide="log-out" class="h-4 w-4"></i>Keluar
                </button>
            </form>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
        <x-flash />
        {{ $slot }}
    </main>

    <x-media-viewer />
</body>
</html>
