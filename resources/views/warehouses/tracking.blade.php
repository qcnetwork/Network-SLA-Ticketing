{{-- Tracking unit terpakai: every SN that left stock, filtered by where it went, model, technician, reason and date. --}}
@php
    $link = fn (array $change) => route('tracking.index', array_filter([...$filters, ...$change], fn ($value) => $value !== null && $value !== ''));
    $tab = fn (bool $on) => $on ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-indigo-300 hover:text-indigo-700';
    $filtered = collect($filters)->except('tujuan')->filter()->isNotEmpty();
@endphp
<x-layouts.app title="Tracking Unit">
    <a href="{{ route('warehouses.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-700">
        <i data-lucide="arrow-left" class="h-4 w-4"></i>Kembali ke Gudang
    </a>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-indigo-600 text-white shadow-sm"><i data-lucide="map-pin" class="h-6 w-6"></i></span>
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Tracking Unit Terpakai</h1>
                <p class="text-sm text-slate-500">Semua unit ber-SN yang sudah keluar stok: dipasang di mana, oleh siapa, kapan, dan menggantikan alat apa.</p>
            </div>
        </div>
        <a href="{{ route('tracking.export', request()->query()) }}" class="btn-secondary shrink-0"><i data-lucide="file-spreadsheet" class="h-4 w-4 text-emerald-600"></i>Export Excel</a>
    </div>

    {{-- Where they went. --}}
    <div class="mb-4 flex gap-2 overflow-x-auto text-sm">
        <a href="{{ $link(['tujuan' => null]) }}" class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3.5 py-1.5 font-semibold {{ $tab($filters['tujuan'] === '') }}">
            Semua <span class="opacity-70">{{ number_format($total, 0, ',', '.') }}</span>
        </a>
        @foreach (App\Models\SerialUnit::DESTINATIONS as $key => $label)
            @continue($counts[$key] === 0 && $filters['tujuan'] !== $key)
            <a href="{{ $link(['tujuan' => $key]) }}" class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3.5 py-1.5 font-semibold {{ $tab($filters['tujuan'] === $key) }}">
                {{ $label }} <span class="opacity-70">{{ number_format($counts[$key], 0, ',', '.') }}</span>
            </a>
        @endforeach
    </div>

    <form method="GET" class="card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
        @if ($filters['tujuan'] !== '')
            <input type="hidden" name="tujuan" value="{{ $filters['tujuan'] }}">
        @endif
        <div class="sm:col-span-2">
            <label class="label text-xs" for="q">Cari</label>
            <input id="q" type="search" name="q" value="{{ $filters['q'] }}" class="input" placeholder="SN, MAC, nama pelanggan, lokasi, atau no. tiket">
        </div>
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
        <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4 lg:justify-end">
            @if ($filtered)
                <a href="{{ route('tracking.index', array_filter(['tujuan' => $filters['tujuan']])) }}" class="btn-secondary">Reset</a>
            @endif
            <button class="btn-primary"><i data-lucide="search" class="h-4 w-4"></i>Terapkan</button>
        </div>
    </form>

    <section class="card overflow-hidden">
        <div class="border-b border-slate-100 px-4 py-3 text-sm text-slate-500">
            {{ number_format($units->total(), 0, ',', '.') }} unit
            @if ($filters['tujuan'] !== '')
                · {{ App\Models\SerialUnit::DESTINATIONS[$filters['tujuan']] }}
            @endif
        </div>
        @include('warehouses.partials.tracking-table')
    </section>
</x-layouts.app>
