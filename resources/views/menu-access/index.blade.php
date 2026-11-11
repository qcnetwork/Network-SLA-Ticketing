@use('App\Enums\Menu')
@use('App\Enums\Role')
<x-layouts.app title="Hak Akses">
    <a href="{{ route('users.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-700">
        <i data-lucide="arrow-left" class="h-4 w-4"></i>Pengguna
    </a>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-900">Hak Akses Menu</h1>
        <p class="text-sm text-slate-500">
            Centang menu yang boleh dibuka tiap pengguna. Bawaannya mengikuti peran. Kotak
            <span class="rounded border border-amber-400 bg-amber-50 px-1 text-amber-800">kuning</span>
            berarti berbeda dari bawaan perannya.
        </p>
        <p class="mt-1 text-xs text-slate-500">
            Dashboard, Gamas, dan Tiket mengikuti alur kerja tiap peran. Pengguna, Pengaturan, dan halaman ini hanya untuk Supervisor.
        </p>
    </div>

    <form method="POST" action="{{ route('menu-access.update') }}" class="space-y-6">
        @csrf
        @method('PUT')

        @foreach ($groups as $roleValue => $users)
            @php
                $role = Role::from($roleValue);
            @endphp
            <section class="card">
                <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-200 px-4 py-3">
                    <h2 class="font-semibold text-slate-800">{{ $role->label() }}</h2>
                    <p class="text-xs text-slate-500">
                        Bawaan: {{ collect(Menu::defaultsFor($role))->map->label()->implode(', ') ?: 'tidak ada menu tambahan' }}
                    </p>
                </div>

                <div class="divide-y divide-slate-100">
                    @foreach ($users as $user)
                        @php
                            $defaults = array_map(fn (Menu $m) => $m->value, Menu::defaultsFor($user->role));
                            $current = array_values(array_map(fn (Menu $m) => $m->value, array_filter($menus, fn (Menu $m) => $user->canAccess($m))));
                            $locked = $user->isPrimarySupervisor();
                        @endphp
                        <div class="flex flex-col gap-3 px-4 py-3 lg:flex-row lg:items-center"
                             x-data="{ checked: @js($current), defaults: @js($defaults) }"
                             x-effect="if (checked.includes('warehouse_manage') && ! checked.includes('warehouse')) checked.push('warehouse')">
                            <div class="min-w-0 lg:w-56 lg:shrink-0">
                                <div class="flex flex-wrap items-center gap-1.5 font-medium text-slate-800">
                                    {{ $user->name }}
                                    @unless ($user->active)
                                        <span class="badge border-slate-300 bg-slate-100 text-slate-500">Nonaktif</span>
                                    @endunless
                                </div>
                                <div class="font-mono text-xs text-slate-500">{{ $user->username }}</div>
                            </div>

                            @if ($locked)
                                <p class="flex items-center gap-2 text-sm text-slate-500">
                                    <i data-lucide="key-round" class="h-4 w-4"></i>Supervisor utama selalu punya akses penuh.
                                </p>
                            @else
                                <input type="hidden" name="users[]" value="{{ $user->id }}">
                                <div class="flex flex-1 flex-wrap gap-2">
                                    @foreach ($menus as $menu)
                                        <label title="{{ $menu->description() }}"
                                               class="flex cursor-pointer items-center gap-2 rounded-lg border px-2.5 py-1.5 text-sm transition select-none"
                                               :class="checked.includes(@js($menu->value)) !== defaults.includes(@js($menu->value))
                                                   ? 'border-amber-400 bg-amber-50 text-amber-900'
                                                   : (checked.includes(@js($menu->value)) ? 'border-indigo-200 bg-indigo-50 text-indigo-800' : 'border-slate-200 text-slate-500')">
                                            <input type="checkbox" name="access[{{ $user->id }}][]" value="{{ $menu->value }}"
                                                   x-model="checked" @checked(in_array($menu->value, $current, true))
                                                   class="rounded border-slate-300 text-indigo-600">
                                            <i data-lucide="{{ $menu->icon() }}" class="h-3.5 w-3.5"></i>
                                            {{ $menu->label() }}
                                        </label>
                                    @endforeach
                                </div>
                                <button type="button" class="btn-secondary shrink-0 px-3 py-1.5 text-xs"
                                        x-show="[...checked].sort().join() !== [...defaults].sort().join()" x-cloak
                                        @click="checked = [...defaults]">
                                    <i data-lucide="undo-2" class="h-3.5 w-3.5"></i>Kembalikan ke bawaan
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        <div class="sticky bottom-4 flex justify-end">
            <button class="btn-primary shadow-lg"><i data-lucide="save" class="h-4 w-4"></i>Simpan Hak Akses</button>
        </div>
    </form>
</x-layouts.app>
