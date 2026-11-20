{{-- A warehouse's stock: category chips, a search box, and one row per item (SN-tracked items list their units). --}}
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
                    @php
                        $item = $stock->item;
                    @endphp
                    <tr class="align-top hover:bg-slate-50/60">
                        <td class="px-5 py-3">
                            <p class="font-medium text-slate-800">{{ $item->name }}</p>
                            <p class="flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
                                <span class="font-mono">{{ $item->code }}</span>
                                <span class="md:hidden">· {{ $item->category?->name ?? 'Tanpa kategori' }}</span>
                            </p>
                            @php
                                // Stock of an SN item that has no SN: kept from before the item switched to SN.
                                $looseHere = $item->tracks_serial ? $stock->quantity - ($units[$item->id] ?? collect())->count() : 0;
                            @endphp
                            @if ($looseHere > 0)
                                <p class="mt-1 text-xs font-medium text-amber-700" title="Stok dari sebelum barang ini pakai SN; dipakai / dikirim per jumlah sebagai {{ \App\Models\Item::looseCode($item->code) }}">
                                    {{ number_format($looseHere, 0, ',', '.') }} {{ $item->unit }} stok lama tanpa SN
                                </p>
                            @endif
                            @if ($item->tracks_serial && $units->has($item->id))
                                @php
                                    // An item that scans its MAC flags units still missing one, so they are easy to fix.
                                    $noMac = $item->scans_mac ? $units[$item->id]->whereNull('mac')->count() : 0;
                                    $editingHere = $units[$item->id]->contains('id', (int) old('serial_unit_id'));
                                @endphp
                                <details class="mt-1.5 text-xs text-slate-500" @if ($editingHere) open @endif>
                                    <summary class="cursor-pointer font-medium text-indigo-700">
                                        Lihat {{ $units[$item->id]->count() }} SN
                                        @if ($noMac)
                                            <span class="ml-1 font-semibold text-amber-600">· {{ $noMac }} belum ada MAC</span>
                                        @endif
                                    </summary>
                                    <ul class="mt-1.5 grid gap-x-4 gap-y-0.5 rounded-lg bg-slate-50 p-2 sm:grid-cols-2">
                                        @foreach ($units[$item->id] as $unit)
                                            @php($reopen = (int) old('serial_unit_id') === $unit->id)
                                            <li x-data="{ editing: @js($reopen) }" :class="editing && 'sm:col-span-2'">
                                                <div x-show="! editing" class="group flex items-center gap-1.5 font-mono">
                                                    <span>{{ $unit->serial_number }}</span>
                                                    @if ($unit->mac)
                                                        <span class="text-slate-400">{{ $unit->formattedMac() }}</span>
                                                    @elseif ($item->scans_mac)
                                                        <span class="font-sans text-[11px] font-semibold text-amber-600">MAC kosong</span>
                                                    @endif
                                                    @if ($canManage ?? false)
                                                        <button type="button" @click="editing = true" class="rounded p-0.5 text-slate-400 hover:bg-white hover:text-indigo-700" title="Ubah SN / MAC">
                                                            <i data-lucide="pencil" class="h-3 w-3"></i>
                                                        </button>
                                                    @endif
                                                </div>
                                                @if ($canManage ?? false)
                                                    <form x-show="editing" x-cloak method="POST" action="{{ route('warehouses.update-serial', $unit) }}" class="my-1 space-y-1.5 rounded-lg border border-indigo-200 bg-white p-2">
                                                        @csrf
                                                        @method('PUT')
                                                        <input type="hidden" name="serial_unit_id" value="{{ $unit->id }}">
                                                        <div class="grid gap-1.5 sm:grid-cols-2">
                                                            <input name="serial_number" value="{{ $reopen ? old('serial_number') : $unit->serial_number }}" class="input py-1 font-mono text-xs uppercase" required maxlength="100" placeholder="SN">
                                                            <input name="mac" value="{{ $reopen ? old('mac') : $unit->formattedMac() }}" class="input py-1 font-mono text-xs" maxlength="30" placeholder="MAC (kosongkan bila tidak ada)">
                                                        </div>
                                                        @if ($reopen)
                                                            <x-field-error name="serial_edit" />
                                                        @endif
                                                        <div class="flex justify-end gap-1.5">
                                                            <button type="button" @click="editing = false" class="btn-secondary px-2 py-1 text-xs">Batal</button>
                                                            <button class="btn-primary px-2 py-1 text-xs"><i data-lucide="save" class="h-3 w-3"></i>Simpan</button>
                                                        </div>
                                                    </form>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                        </td>
                        <td class="hidden px-4 py-3 md:table-cell">
                            @if ($item->category)
                                <span class="inline-flex items-center gap-1.5 text-xs text-slate-600"><i data-lucide="{{ $item->category->icon }}" class="h-3.5 w-3.5 text-slate-400"></i>{{ $item->category->name }}</span>
                            @else
                                <span class="text-xs text-slate-400">Tanpa kategori</span>
                            @endif
                            @if ($item->tracks_serial)
                                <span class="badge ml-1 border-violet-300 bg-violet-50 text-violet-700">SN</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right whitespace-nowrap">
                            <span class="text-base font-bold text-slate-900">{{ number_format($stock->quantity, 0, ',', '.') }}</span>
                            <span class="text-xs text-slate-500">{{ $item->unit }}</span>
                            @if ($showLow && $item->isLow($stock->quantity))
                                <span class="mt-0.5 block text-[11px] font-semibold text-rose-600">Menipis (min {{ $item->min_stock }})</span>
                            @endif
                        </td>
                    </tr>
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
</section>
