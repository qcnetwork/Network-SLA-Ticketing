{{-- One category in a warehouse, browsed jenis → merk → model: what is in stock, or (main warehouse) the units that left it. --}}
@php
    $holder = $warehouse->isMain() ? $warehouse->name : ($warehouse->user?->name ? "Stok {$warehouse->user->name}" : $warehouse->name);
    $home = $warehouse->isMain() ? route('warehouses.index') : route('warehouses.show', $warehouse);
    $here = fn (array $query = []) => route('warehouses.category', [$warehouse, $category, ...array_filter(['tampil' => $used ? 'terpakai' : null, ...$query], fn ($value) => $value !== null)]);
    $view = fn (bool $usedView) => route('warehouses.category', [$warehouse, $category, ...array_filter(['tampil' => $usedView ? 'terpakai' : null, 'jenis' => $variant, 'merk' => $brand], fn ($value) => $value !== null)]);
    $label = fn (?string $value) => $value === '-' ? 'Belum ditentukan' : $value;
    // Each step of the trail, the last one being where we are.
    $trail = collect([[$holder, $home], [$category->name, $here()]]);
    if ($variant !== null) {
        $trail->push([$label($variant), $here(['jenis' => $variant])]);
    }
    if ($brand !== null) {
        $trail->push([$label($brand), $here(['jenis' => $variant, 'merk' => $brand])]);
    }
@endphp
<x-layouts.app :title="$category->name">
    <nav class="mb-4 flex flex-wrap items-center gap-1 text-sm text-slate-500">
        @foreach ($trail as [$name, $url])
            @if (! $loop->first)
                <i data-lucide="chevron-right" class="h-3.5 w-3.5 text-slate-300"></i>
            @endif
            @if ($loop->last)
                <span class="font-semibold text-slate-800">{{ $name }}</span>
            @else
                <a href="{{ $url }}" class="hover:text-indigo-700">{{ $name }}</a>
            @endif
        @endforeach
    </nav>

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-indigo-600 text-white shadow-sm"><i data-lucide="{{ $category->icon }}" class="h-6 w-6"></i></span>
            <div>
                <h1 class="text-2xl font-bold text-slate-900">{{ $trail->last()[0] }}</h1>
                <p class="text-sm text-slate-500">
                    {{ $holder }} · {{ $models }} model · {{ number_format($total, 0, ',', '.') }} unit {{ $used ? 'terpakai' : 'tersedia' }}
                    @if ($category->requires_serial)
                        · <span class="text-violet-700">per SN</span>
                    @endif
                </p>
            </div>
        </div>
        @if ($level !== 'model' && $models > 0)
            <a href="{{ $here(['jenis' => $variant, 'merk' => $brand, 'semua' => 1]) }}" class="btn-secondary shrink-0"><i data-lucide="list" class="h-4 w-4"></i>Lihat semua model</a>
        @endif
    </div>

    @if ($warehouse->isMain() && $canManage)
        <div class="mb-5 inline-flex rounded-lg bg-slate-100 p-1 text-sm font-semibold">
            <a href="{{ $view(false) }}" class="flex items-center gap-1.5 rounded-md px-4 py-1.5 {{ ! $used ? 'bg-white text-emerald-700 shadow-sm' : 'text-slate-500 hover:text-slate-700' }}">
                <i data-lucide="package-check" class="h-4 w-4"></i>Tersedia
            </a>
            <a href="{{ $view(true) }}" class="flex items-center gap-1.5 rounded-md px-4 py-1.5 {{ $used ? 'bg-white text-amber-700 shadow-sm' : 'text-slate-500 hover:text-slate-700' }}">
                <i data-lucide="map-pin" class="h-4 w-4"></i>Terpakai
            </a>
        </div>
    @endif

    @if ($level !== 'model')
        <p class="mb-3 text-xs font-semibold tracking-wide text-slate-500 uppercase">Pilih {{ $level === 'jenis' ? 'jenis' : 'merk' }}</p>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($groups as $group)
                @php
                    $unset = $group['key'] === '-';
                    $url = $level === 'jenis' ? $here(['jenis' => $group['key']]) : $here(['jenis' => $variant, 'merk' => $group['key']]);
                @endphp
                <a href="{{ $url }}" class="card group flex items-center gap-4 p-4 transition hover:border-indigo-300 hover:shadow-md {{ $unset ? 'border-dashed' : '' }}">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl {{ $unset ? 'bg-amber-50 text-amber-600' : 'bg-indigo-50 text-indigo-600' }}">
                        <i data-lucide="{{ $unset ? 'circle-help' : ($level === 'jenis' ? 'layers' : 'tag') }}" class="h-5 w-5"></i>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-semibold {{ $unset ? 'text-amber-700' : 'text-slate-800' }}">{{ $label($group['key']) }}</span>
                        <span class="text-xs text-slate-500">
                            {{ $level === 'jenis' && $group['brands'] ? $group['brands'].' merk · ' : '' }}{{ $group['models'] }} model
                        </span>
                    </span>
                    <span class="text-right">
                        <span class="block text-lg font-bold text-slate-900">{{ number_format($group['units'], 0, ',', '.') }}</span>
                        <span class="text-xs text-slate-500">{{ $used ? 'terpakai' : $group['unit'] }}</span>
                    </span>
                    <i data-lucide="chevron-right" class="h-4 w-4 shrink-0 text-slate-300 transition group-hover:text-indigo-500"></i>
                </a>
            @endforeach
        </div>
        @if ($groups->contains('key', '-') && $canManage)
            <p class="mt-3 text-xs text-slate-500">Barang di "Belum ditentukan" bisa dirapikan dengan mengisi jenis / merk-nya di <a href="{{ route('items.index', ['kategori' => $category->id]) }}" class="font-medium text-indigo-700 hover:underline">Master Barang</a>.</p>
        @endif
    @elseif ($used)
        <section class="card overflow-hidden">
            @include('warehouses.partials.tracking-table', ['units' => $usedUnits])
        </section>
    @else
        <section class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase">
                        <tr>
                            <th class="px-5 py-3">Model</th>
                            <th class="hidden px-4 py-3 md:table-cell">Kategori</th>
                            <th class="px-5 py-3 text-right">Stok</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($stocks as $stock)
                            @include('warehouses.partials.stock-row', ['showLow' => $warehouse->isMain()])
                        @empty
                            <tr>
                                <td colspan="3" class="px-5 py-10 text-center text-slate-400">
                                    <i data-lucide="package-open" class="mx-auto mb-2 h-8 w-8 text-slate-300"></i>
                                    Tidak ada stok di sini
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($models === 0 && $level !== 'model')
        <div class="card p-10 text-center text-slate-400">
            <i data-lucide="package-open" class="mx-auto mb-2 h-8 w-8 text-slate-300"></i>
            {{ $used ? "Belum ada unit {$category->name} yang terpakai" : "Tidak ada stok {$category->name} di {$holder}" }}
        </div>
    @endif
</x-layouts.app>
