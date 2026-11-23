{{-- Cari SN: every SN unit, idle (in a warehouse) or used (left stock), filtered by where it is or went, model,
     technician, reason and date. The search box comes first, so a scanner can be used straight away. --}}
@php
    $link = fn (array $change) => route('tracking.index', array_filter([...$filters, 'urut' => null, ...$change], fn ($value) => $value !== null && $value !== ''));
    $tab = fn (bool $on) => $on ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-indigo-300 hover:text-indigo-700';
    $number = fn (int $value) => number_format($value, 0, ',', '.');
    // Filters past the search box and the tabs; when one is on, the panel starts open.
    $advanced = collect($filters)->only(['kategori', 'barang', 'teknisi', 'status', 'dari', 'sampai'])->filter();
    $positionTabs = [
        '' => ['Semua', 'layers'],
        'idle' => ['Idle', 'package-check'],
        'terpakai' => ['Terpakai', 'map-pin'],
    ];
    $positionTone = ['' => 'text-indigo-700', 'idle' => 'text-emerald-700', 'terpakai' => 'text-amber-700'];
@endphp
<x-layouts.app title="Cari SN">
    @include('warehouses.partials.nav')

    <div class="mb-5 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Cari &amp; Tracking SN</h1>
            <p class="text-sm text-slate-500">Semua unit ber-SN: yang masih <b class="text-emerald-700">idle</b> di gudang / dipegang teknisi, dan yang sudah <b class="text-amber-700">terpakai</b> (dipasang, dijual, retur).</p>
        </div>
        <a href="{{ route('tracking.export', request()->query()) }}" class="btn-secondary shrink-0"><i data-lucide="file-spreadsheet" class="h-4 w-4 text-emerald-600"></i>Export Excel</a>
    </div>

    <form method="GET" class="card mb-4 p-4" x-data="{ more: @js($advanced->isNotEmpty()) }">
        @foreach (['posisi', 'tujuan', 'gudang'] as $kept)
            @if ($filters[$kept] !== '')
                <input type="hidden" name="{{ $kept }}" value="{{ $filters[$kept] }}">
            @endif
        @endforeach
        <div class="flex flex-col gap-2 sm:flex-row">
            <div class="relative flex-1">
                <i data-lucide="scan-barcode" class="pointer-events-none absolute top-1/2 left-3 h-5 w-5 -translate-y-1/2 text-slate-400"></i>
                <input id="q" type="search" name="q" value="{{ $filters['q'] }}" class="input py-2.5 pl-10 font-mono" autocomplete="off" spellcheck="false"
                    @if ($filters['q'] === '') autofocus @endif
                    placeholder="Scan / ketik SN atau MAC, nama pelanggan, lokasi, teknisi, no. tiket">
            </div>
            <div class="flex gap-2">
                <button type="button" @click="more = ! more" class="btn-secondary flex-1 sm:flex-none" :aria-expanded="more">
                    <i data-lucide="sliders-horizontal" class="h-4 w-4"></i>Filter
                    @if ($advanced->isNotEmpty())
                        <span class="rounded-full bg-indigo-600 px-1.5 text-[11px] text-white">{{ $advanced->count() }}</span>
                    @endif
                </button>
                <button class="btn-primary flex-1 sm:flex-none"><i data-lucide="search" class="h-4 w-4"></i>Cari</button>
            </div>
        </div>

        <div x-show="more" x-cloak class="mt-4 grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label class="label text-xs" for="kategori">Kategori</label>
                <select id="kategori" name="kategori" class="input">
                    <option value="">Semua kategori</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected($filters['kategori'] === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label text-xs" for="barang">Model / barang</label>
                <input id="barang" name="barang" value="{{ $filters['barang'] }}" list="model_options" class="input font-mono uppercase" placeholder="Kode, mis. MDM049">
                <datalist id="model_options">
                    @foreach ($models as $model)
                        <option value="{{ $model->code }}">{{ $model->name }}</option>
                    @endforeach
                </datalist>
            </div>
            <div>
                <label class="label text-xs" for="teknisi">Teknisi / pemegang</label>
                <input id="teknisi" name="teknisi" value="{{ $filters['teknisi'] }}" list="holder_options" class="input" placeholder="Semua">
                <datalist id="holder_options">
                    @foreach ($holders as $holder)
                        <option value="{{ $holder }}">
                    @endforeach
                </datalist>
            </div>
            @if ($filters['posisi'] !== 'idle')
                <div>
                    <label class="label text-xs" for="status">Status pemasangan</label>
                    <select id="status" name="status" class="input">
                        <option value="">Semua status</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label text-xs" for="dari">Diambil dari</label>
                    <input id="dari" type="date" name="dari" value="{{ $filters['dari'] }}" class="input">
                </div>
                <div>
                    <label class="label text-xs" for="sampai">Sampai</label>
                    <input id="sampai" type="date" name="sampai" value="{{ $filters['sampai'] }}" class="input">
                </div>
            @endif
            <div class="flex items-end justify-end gap-2 sm:col-span-2 lg:col-span-3">
                @if ($advanced->isNotEmpty() || $filters['q'] !== '')
                    <a href="{{ route('tracking.index', array_filter(['posisi' => $filters['posisi'], 'tujuan' => $filters['tujuan'], 'gudang' => $filters['gudang']])) }}" class="btn-secondary">
                        <i data-lucide="x" class="h-4 w-4"></i>Hapus filter
                    </a>
                @endif
                <button class="btn-primary">Terapkan</button>
            </div>
        </div>
    </form>

    {{-- Idle or used, then where. --}}
    <div class="mb-3 flex flex-wrap items-center gap-2">
        <div class="inline-flex rounded-lg bg-slate-100 p-1 text-sm font-semibold">
            @foreach ($positionTabs as $key => [$label, $icon])
                @php($on = $filters['posisi'] === $key)
                <a href="{{ $link(['posisi' => $key, 'tujuan' => null, 'gudang' => null]) }}" @if ($on) aria-current="page" @endif
                   class="flex items-center gap-1.5 rounded-md px-3 py-1.5 sm:px-4 {{ $on ? 'bg-white shadow-sm '.$positionTone[$key] : 'text-slate-500 hover:text-slate-700' }}">
                    <i data-lucide="{{ $icon }}" class="h-4 w-4"></i>{{ $label }}
                    <span class="text-xs font-normal opacity-70">{{ $number($positionCounts[$key]) }}</span>
                </a>
            @endforeach
        </div>
    </div>

    @if ($filters['posisi'] === 'terpakai')
        <div class="mb-4 flex gap-2 overflow-x-auto text-xs">
            <a href="{{ $link(['tujuan' => null]) }}" class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1 font-semibold {{ $tab($filters['tujuan'] === '') }}">Semua tujuan</a>
            @foreach (App\Models\SerialUnit::DESTINATIONS as $key => $label)
                @continue($counts[$key] === 0 && $filters['tujuan'] !== $key)
                <a href="{{ $link(['tujuan' => $key]) }}" class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1 font-semibold {{ $tab($filters['tujuan'] === $key) }}">
                    {{ $label }} <span class="opacity-70">{{ $number($counts[$key]) }}</span>
                </a>
            @endforeach
        </div>
    @elseif ($filters['posisi'] === 'idle')
        <div class="mb-4 flex gap-2 overflow-x-auto text-xs">
            <a href="{{ $link(['gudang' => null, 'posisi' => 'idle']) }}" class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1 font-semibold {{ $tab($filters['gudang'] === '') }}">Semua gudang</a>
            @foreach (App\Http\Controllers\TrackingController::HOLDS as $key => $label)
                <a href="{{ $link(['gudang' => $key]) }}" class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1 font-semibold {{ $tab($filters['gudang'] === $key) }}">
                    {{ $label }} <span class="opacity-70">{{ $number($holdCounts[$key]) }}</span>
                </a>
            @endforeach
        </div>
    @endif

    <section class="card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3 text-sm">
            <span class="text-slate-500">
                <b class="text-slate-800">{{ $number($units->total()) }}</b> unit
                @if ($filters['q'] !== '')
                    cocok dengan “<span class="font-mono text-slate-800">{{ $filters['q'] }}</span>”
                @endif
            </span>
            <div class="flex items-center gap-1 text-xs">
                <span class="text-slate-400">Urutkan:</span>
                @foreach (['terbaru' => 'Terbaru', 'terlama' => 'Terlama'] as $key => $label)
                    <a href="{{ $link(['urut' => $key]) }}" class="rounded-md px-2 py-1 font-semibold {{ $filters['urut'] === $key ? 'bg-slate-100 text-slate-800' : 'text-slate-500 hover:text-slate-800' }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>
        @include('warehouses.partials.tracking-table')
    </section>
</x-layouts.app>
