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
        {{-- One full-width band per category, stacked, each listing what it holds (largest stock first) so the
             contents show at a glance; the header opens the category page, browsed jenis → merk → model. --}}
        @php
            $preview = 12;
            $bands = $categories->filter(fn ($category) => isset($byCategory[$category->id]))
                ->map(fn ($category) => [
                    'key' => $category->id, 'name' => $category->name, 'icon' => $category->icon, 'serial' => $category->requires_serial,
                    'url' => route('warehouses.category', [$stockWarehouse, $category]), 'row' => $byCategory[$category->id], 'loose' => false,
                ])->values();
            if (isset($byCategory['none'])) {
                $bands->push([
                    'key' => 'none', 'name' => 'Tanpa kategori', 'icon' => 'circle-alert', 'serial' => false,
                    'url' => request()->fullUrlWithQuery(['kategori' => 'none', 'stok' => null]), 'row' => $byCategory['none'], 'loose' => true,
                ]);
            }
        @endphp
        <div class="space-y-4 p-5">
            @forelse ($bands as $band)
                @php
                    $held = $categoryItems[$band['key']] ?? collect();
                @endphp
                <div class="overflow-hidden rounded-xl border {{ $band['loose'] ? 'border-dashed border-amber-300' : 'border-slate-200' }}">
                    <a href="{{ $band['url'] }}" class="group flex items-center gap-3 px-4 py-3 transition {{ $band['loose'] ? 'bg-amber-50/60 hover:bg-amber-50' : 'bg-slate-50 hover:bg-indigo-50/60' }}">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg {{ $band['loose'] ? 'bg-amber-100 text-amber-600' : 'bg-indigo-100 text-indigo-600' }}"><i data-lucide="{{ $band['icon'] }}" class="h-5 w-5"></i></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold {{ $band['loose'] ? 'text-amber-700' : 'text-slate-800' }}">{{ $band['name'] }}</span>
                            <span class="text-xs text-slate-500">{{ $band['row']->kinds }} {{ $band['loose'] ? 'barang' : 'model' }}{{ $band['serial'] ? ' · per SN' : '' }}</span>
                        </span>
                        <span class="text-right">
                            <span class="block text-lg leading-tight font-bold text-slate-900">{{ number_format((int) $band['row']->total, 0, ',', '.') }}</span>
                            <span class="text-[11px] text-slate-400">total stok</span>
                        </span>
                        <i data-lucide="chevron-right" class="h-4 w-4 shrink-0 text-slate-300 transition group-hover:text-indigo-500"></i>
                    </a>
                    <ul class="grid gap-x-4 border-t border-slate-100 px-2 py-2 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($held->take($preview) as $stock)
                            @php
                                $low = $showLow && $stock->item->isLow((int) $stock->quantity);
                            @endphp
                            <li class="flex items-center gap-3 rounded-lg px-2 py-2 text-sm hover:bg-slate-50">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium text-slate-800" title="{{ $stock->item->name }}">{{ $stock->item->name }}</span>
                                    <span class="block truncate text-xs text-slate-500"><span class="font-mono">{{ $stock->item->code }}</span>{{ ($stock->item->variant || $stock->item->brand) ? ' · '.collect([$stock->item->variant, $stock->item->brand])->filter()->implode(' · ') : '' }}</span>
                                </span>
                                <span class="shrink-0 text-right {{ $low ? 'text-rose-600' : 'text-slate-900' }}">
                                    <span class="font-semibold">{{ number_format((int) $stock->quantity, 0, ',', '.') }}</span>
                                    <span class="text-xs text-slate-500">{{ $stock->item->unit }}</span>
                                    @if ($low)
                                        <span class="block text-[11px] font-medium">stok menipis</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($held->count() > $preview)
                        <a href="{{ $band['url'] }}" class="flex items-center justify-center gap-1 border-t border-slate-100 py-2 text-xs font-semibold text-indigo-700 hover:bg-indigo-50/60">
                            Lihat semua {{ $held->count() }} barang<i data-lucide="arrow-right" class="h-3.5 w-3.5"></i>
                        </a>
                    @endif
                </div>
            @empty
                <p class="py-8 text-center text-slate-400">Belum ada stok</p>
            @endforelse
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
