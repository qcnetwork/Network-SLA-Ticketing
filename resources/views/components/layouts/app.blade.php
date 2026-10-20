@use('App\Enums\Role')
@props(['title' => null])
@php
    $user = auth()->user();
    $nav = array_filter([
        ['route' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'active' => 'dashboard'],
        ['route' => 'incidents.index', 'label' => 'Gamas', 'icon' => 'radio-tower', 'active' => 'incidents.*'],
        $user->can('create', App\Models\Ticket::class)
            ? ['route' => 'tickets.create', 'label' => 'Tiket Baru', 'icon' => 'plus-circle', 'active' => 'tickets.create'] : null,
        $user->hasRole(Role::HELPDESK, Role::SPV, Role::CS)
            ? ['route' => 'reports.index', 'label' => 'Laporan', 'icon' => 'bar-chart-3', 'active' => 'reports.*'] : null,
        $user->hasRole(Role::HELPDESK, Role::SPV)
            ? ['route' => 'telegram.logs', 'label' => 'Feed Telegram', 'icon' => 'send', 'active' => 'telegram.*'] : null,
        $user->hasRole(Role::SPV)
            ? ['route' => 'users.index', 'label' => 'Pengguna', 'icon' => 'users', 'active' => 'users.*'] : null,
        $user->hasRole(Role::SPV)
            ? ['route' => 'settings.edit', 'label' => 'Pengaturan', 'icon' => 'settings', 'active' => 'settings.*'] : null,
    ]);
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}NetTicket</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
    <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur" x-data="{ open: false }">
        <div class="mx-auto flex h-16 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2 font-bold text-indigo-700">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-600 text-white"><i data-lucide="ticket" class="h-5 w-5"></i></span>
                <span class="text-lg">NetTicket</span>
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
            </nav>

            <div class="hidden items-center gap-3 lg:flex">
                <a href="{{ route('profile.edit') }}" class="text-right leading-tight hover:text-indigo-700">
                    <div class="text-sm font-semibold">{{ $user->name }}</div>
                    <div class="text-xs text-slate-500">{{ $user->role->label() }}</div>
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="btn-secondary px-3" title="Keluar"><i data-lucide="log-out" class="h-4 w-4"></i></button>
                </form>
            </div>

            <button class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" @click="open = !open" aria-label="Menu">
                <i data-lucide="menu" class="h-6 w-6"></i>
            </button>
        </div>

        <div class="border-t border-slate-200 bg-white px-4 py-3 lg:hidden" x-show="open" x-cloak>
            <div class="mb-3 border-b border-slate-100 pb-3">
                <div class="text-sm font-semibold">{{ $user->name }}</div>
                <div class="text-xs text-slate-500">{{ $user->role->label() }}</div>
            </div>
            @foreach ($nav as $item)
                <a href="{{ route($item['route']) }}" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                    <i data-lucide="{{ $item['icon'] }}" class="h-4 w-4"></i>{{ $item['label'] }}
                </a>
            @endforeach
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
</body>
</html>
