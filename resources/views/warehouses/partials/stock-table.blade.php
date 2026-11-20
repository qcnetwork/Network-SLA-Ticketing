{{-- A warehouse's stock: category cards to browse from, or (while searching or looking at uncategorised items)
     category chips and one row per item (SN-tracked items list their units). --}}
@php
    $showLow = $showLow ?? false;
@endphp
<section class="card overflow-hidden">
    <div class="flex flex-col gap-3 border-b border-slate-100 p-5 sm:flex-row sm:items-center sm:justify-between">
        <h2 class="flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="package" class="h-4 w-4"></i>{{ $title }}</h2>
        <form method="GET" class="flex gap-2">
            @if ($categoryFilter !== '')
                <input type="hidden" name="kategori" value="{{ $categoryFilter }}">
            @endif
            <input type="search" name="q" value="{{ $search }}" class="input sm:w-60" placeholder="Cari barang, SN, atau MAC">
            <button class="btn-secondary" title="Cari"><i data-lucide="search" class="h-4 w-4"></i></button>
        </form>
    </div>

    @if ($search === '' && $categoryFilter === '')
        {{-- Start from the categories: each opens its own page, browsed jenis → merk → model. --}}
        <div class="grid gap-3 p-5 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($categories as $category)
                @php
                    $row = $byCategory[$category->id] ?? null;
                @endphp
                @continue(! $row)
                <a href="{{ route('warehouses.category', [$stockWarehouse, $category]) }}" class="group flex items-center gap-3 rounded-xl border border-slate-200 p-3.5 transition hover:border-indigo-300 hover:bg-indigo-50/40">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-indigo-50 text-indigo-600"><i data-lucide="{{ $category->icon }}" class="h-5 w-5"></i></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-semibold text-slate-800">{{ $category->name }}</span>
                        <span class="text-xs text-slate-500">{{ $row->kinds }} model{{ $category->requires_serial ? ' · per SN' : '' }}</span>
                    </span>
                    <span class="text-right text-lg font-bold text-slate-900">{{ number_format((int) $row->total, 0, ',', '.') }}</span>
                    <i data-lucide="chevron-right" class="h-4 w-4 shrink-0 text-slate-300 transition group-hover:text-indigo-500"></i>
                </a>
            @endforeach
            @if (isset($byCategory['none']))
                <a href="{{ request()->fullUrlWithQuery(['kategori' => 'none', 'stok' => null]) }}" class="group flex items-center gap-3 rounded-xl border border-dashed border-amber-300 p-3.5 transition hover:bg-amber-50/50">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-amber-50 text-amber-600"><i data-lucide="circle-alert" class="h-5 w-5"></i></span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-semibold text-amber-700">Tanpa kategori</span>
                        <span class="text-xs text-slate-500">{{ $byCategory['none']->kinds }} barang</span>
                    </span>
                    <span class="text-right text-lg font-bold text-slate-900">{{ number_format((int) $byCategory['none']->total, 0, ',', '.') }}</span>
                </a>
            @endif
            @if ($byCategory->isEmpty())
                <p class="col-span-full py-8 text-center text-slate-400">Belum ada stok</p>
            @endif
        </div>
    @else
    {{-- Category chips: only categories that hold something here, plus the one being filtered. --}}
    <div class="flex gap-2 overflow-x-auto border-b border-slate-100 px-5 py-3 text-xs">
        @php
            $chip = fn (bool $on) => $on ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-indigo-300 hover:text-indigo-700';
        @endphp
        <a href="{{ request()->fullUrlWithQuery(['kategori' => null, 'stok' => null]) }}" class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1 font-semibold {{ $chip($categoryFilter === '') }}">
            <i data-lucide="layers" class="h-3.5 w-3.5"></i>Semua <span class="opacity-70">{{ $summary['kinds'] }}</span>
        </a>
        @foreach ($categories as $category)
            @php
                $row = $byCategory[$category->id] ?? null;
            @endphp
            @continue(! $row && $categoryFilter !== (string) $category->id)
            <a href="{{ request()->fullUrlWithQuery(['kategori' => $category->id, 'stok' => null]) }}" class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1 font-semibold {{ $chip($categoryFilter === (string) $category->id) }}">
                <i data-lucide="{{ $category->icon }}" class="h-3.5 w-3.5"></i>{{ $category->name }} <span class="opacity-70">{{ $row->kinds ?? 0 }}</span>
            </a>
        @endforeach
        @if (isset($byCategory['none']) || $categoryFilter === 'none')
            <a href="{{ request()->fullUrlWithQuery(['kategori' => 'none', 'stok' => null]) }}" class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1 font-semibold {{ $chip($categoryFilter === 'none') }}">
                Tanpa kategori <span class="opacity-70">{{ $byCategory['none']->kinds ?? 0 }}</span>
            </a>
        @endif
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase">
                <tr>
                    <th class="px-5 py-3">Barang</th>
                    <th class="hidden px-4 py-3 md:table-cell">Kategori</th>
                    <th class="px-5 py-3 text-right">Stok</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($stocks as $stock)
                    @include('warehouses.partials.stock-row')
                @empty
                    <tr>
                        <td colspan="3" class="px-5 py-10 text-center text-slate-400">
                            <i data-lucide="package-open" class="mx-auto mb-2 h-8 w-8 text-slate-300"></i>
                            {{ $search !== '' || $categoryFilter !== '' ? 'Tidak ada barang yang cocok' : 'Belum ada stok' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($stocks->hasPages())
        <div class="border-t border-slate-100 p-4">{{ $stocks->links() }}</div>
    @endif
    @endif
</section>
