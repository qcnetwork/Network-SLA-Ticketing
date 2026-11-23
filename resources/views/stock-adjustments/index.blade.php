<x-layouts.app title="Adjust Stok">
    @include('warehouses.partials.nav')
    <div class="mb-6 flex items-center gap-3">
        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-violet-600 text-white shadow-sm"><i data-lucide="sliders-horizontal" class="h-6 w-6"></i></span>
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Adjust Stok</h1>
            <p class="text-sm text-slate-500">Samakan stok di sistem dengan hasil hitung fisik, mis. stok teknisi yang minus karena input pemakaian melebihi yang dipegang.</p>
        </div>
    </div>

    @if ($negatives->isNotEmpty())
        <section class="card mb-6 overflow-hidden border-rose-200">
            <div class="flex items-center gap-2 border-b border-rose-100 bg-rose-50 px-5 py-3">
                <i data-lucide="alert-triangle" class="h-4 w-4 text-rose-600"></i>
                <h2 class="font-semibold text-rose-700">Stok minus</h2>
                <span class="badge border-rose-300 bg-white text-rose-700">{{ $negatives->count() }}</span>
            </div>
            <ul class="divide-y divide-slate-100">
                @foreach ($negatives as $stock)
                    <li class="flex flex-wrap items-center gap-3 px-5 py-2.5 text-sm">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium text-slate-800">{{ $stock->item->name }} <span class="font-mono text-xs font-normal text-slate-500">{{ $stock->item->code }}</span></span>
                            <span class="text-xs text-slate-500">{{ $stock->warehouse->name }}</span>
                        </span>
                        <span class="font-bold text-rose-600">{{ number_format($stock->quantity, 0, ',', '.') }} {{ $stock->item->unit }}</span>
                        <a href="{{ route('stock-adjustments.index', ['gudang' => $stock->warehouse_id, 'barang' => $stock->item->code]) }}" class="btn-secondary px-3 py-1.5 text-xs">
                            <i data-lucide="sliders-horizontal" class="h-3.5 w-3.5"></i>Sesuaikan
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div @class(['grid gap-6', 'lg:grid-cols-3' => $isSupervisor, 'max-w-2xl' => ! $isSupervisor])>
        <section class="card h-fit p-5">
            <h2 class="mb-4 flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="clipboard-list" class="h-4 w-4 text-violet-600"></i>Adjust Stok Manual</h2>

            <form method="GET" class="mb-4">
                <label class="label" for="gudang">Gudang</label>
                <select id="gudang" name="gudang" class="input" x-data @change="$el.form.submit()">
                    @foreach ($warehouses as $option)
                        <option value="{{ $option->id }}" @selected($warehouse?->id === $option->id)>{{ $option->name }}{{ $option->isMain() ? '' : ' (teknisi)' }}</option>
                    @endforeach
                </select>
            </form>

            @if ($warehouse)
                <form method="POST" action="{{ route('stock-adjustments.store') }}" class="space-y-4"
                    x-data="stockForm({ catalog: @js($catalog), serialAction: @js(route('stock-adjustments.store')), countAction: @js(route('stock-adjustments.store')), selected: @js((string) old('item', $selected)) })"
                    x-init="quantity = @js((string) old('quantity', ''))">
                    @csrf
                    <input type="hidden" name="warehouse_id" value="{{ $warehouse->id }}">
                    <div>
                        <label class="label">Barang</label>
                        @include('warehouses.partials.item-picker', ['placeholder' => 'Cari barang di '.$warehouse->name])
                        <x-field-error name="item" />
                    </div>

                    <template x-if="item">
                        <div class="space-y-4">
                            <div class="grid grid-cols-3 gap-2 text-center">
                                <div class="rounded-lg bg-slate-50 p-2.5">
                                    <p class="text-[11px] font-medium text-slate-500">Di sistem</p>
                                    <p class="text-lg font-bold" :class="item.held < 0 ? 'text-rose-600' : 'text-slate-900'" x-text="item.held"></p>
                                </div>
                                <div class="rounded-lg bg-slate-50 p-2.5">
                                    <p class="text-[11px] font-medium text-slate-500">Hasil hitung</p>
                                    <p class="text-lg font-bold text-slate-900" x-text="quantity === '' ? '–' : quantity"></p>
                                </div>
                                <div class="rounded-lg p-2.5" :class="quantity === '' || quantity - item.held === 0 ? 'bg-slate-50' : (quantity - item.held > 0 ? 'bg-emerald-50' : 'bg-rose-50')">
                                    <p class="text-[11px] font-medium text-slate-500">Selisih</p>
                                    <p class="text-lg font-bold" :class="quantity === '' ? 'text-slate-400' : (quantity - item.held > 0 ? 'text-emerald-700' : (quantity - item.held < 0 ? 'text-rose-600' : 'text-slate-500'))"
                                        x-text="quantity === '' ? '–' : (quantity - item.held > 0 ? '+' : (quantity - item.held < 0 ? '−' : '')) + Math.abs(quantity - item.held)"></p>
                                </div>
                            </div>
                            <p x-show="item.serial && item.units > 0" class="rounded-lg bg-violet-50 p-2.5 text-xs text-violet-800">
                                Gudang ini memegang <b x-text="item.units"></b> unit ber-SN, jadi stok minimal <b x-text="item.units"></b>. Unit ber-SN dipindah lewat retur / pemakaian SN.
                            </p>
                            <div>
                                <label class="label" for="quantity">Stok sebenarnya (hasil hitung fisik) <span class="font-normal text-slate-400" x-text="item.unit"></span></label>
                                <input id="quantity" name="quantity" type="number" min="0" step="1" inputmode="numeric" x-model="quantity" class="input" required>
                            </div>
                        </div>
                    </template>
                    <x-field-error name="quantity" />

                    <div>
                        <label class="label" for="reason">Alasan</label>
                        <textarea id="reason" name="reason" rows="2" maxlength="255" class="input" required placeholder="mis. hitung fisik 24/11, pemakaian terinput dobel">{{ old('reason') }}</textarea>
                        <x-field-error name="reason" />
                    </div>
                    <button class="btn-primary w-full" :disabled="! item || quantity === ''"><i data-lucide="save" class="h-4 w-4"></i>Simpan Adjust</button>
                    @unless ($isSupervisor)
                        <p class="text-center text-xs text-slate-400">Setiap adjust tercatat dan dilaporkan ke Supervisor.</p>
                    @endunless
                </form>
            @endif
        </section>

        @if ($isSupervisor)
            <section class="card overflow-hidden lg:col-span-2">
                <div class="flex flex-col gap-3 border-b border-slate-100 p-5 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="flex items-center gap-2 font-semibold text-slate-800">
                        <i data-lucide="key-round" class="h-4 w-4 text-violet-600"></i>Log Adjust Stok
                        <span class="badge border-violet-300 bg-violet-50 text-violet-700">khusus Supervisor</span>
                    </h2>
                    <form method="GET" class="flex gap-2">
                        @if (request('gudang'))
                            <input type="hidden" name="gudang" value="{{ request('gudang') }}">
                        @endif
                        <select name="log_gudang" class="input sm:w-56" x-data @change="$el.form.submit()">
                            <option value="">Semua gudang</option>
                            @foreach ($warehouses as $option)
                                <option value="{{ $option->id }}" @selected((string) request('log_gudang') === (string) $option->id)>{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase">
                            <tr>
                                <th class="px-5 py-3">Waktu</th>
                                <th class="px-4 py-3">Barang</th>
                                <th class="px-4 py-3 text-right">Stok</th>
                                <th class="px-4 py-3">Oleh &amp; alasan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($log as $entry)
                                <tr class="align-top">
                                    <td class="px-5 py-3 whitespace-nowrap text-slate-600">
                                        {{ $entry->created_at->format('d/m/Y') }}
                                        <span class="block text-xs text-slate-400">{{ $entry->created_at->format('H:i') }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-slate-800">{{ $entry->item?->name ?? 'Barang terhapus' }}</p>
                                        <p class="text-xs text-slate-500"><span class="font-mono">{{ $entry->item?->code }}</span> · {{ $entry->warehouse?->name }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        <span class="text-slate-500 {{ $entry->quantity_before < 0 ? 'text-rose-600' : '' }}">{{ $entry->quantity_before }}</span>
                                        <span class="text-slate-400">→</span>
                                        <span class="font-semibold text-slate-900">{{ $entry->quantity_after }}</span>
                                        <span class="block text-xs font-semibold {{ $entry->difference > 0 ? 'text-emerald-700' : 'text-rose-600' }}">{{ $entry->difference > 0 ? '+' : '−' }}{{ abs($entry->difference) }} {{ $entry->item?->unit }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-slate-700">{{ $entry->user?->name ?? 'Sistem' }} <span class="text-xs font-normal text-slate-400">{{ $entry->user?->role?->label() }}</span></p>
                                        <p class="text-xs text-slate-500">{{ $entry->reason }}</p>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-5 py-10 text-center text-slate-400">
                                        <i data-lucide="clipboard-list" class="mx-auto mb-2 h-8 w-8 text-slate-300"></i>
                                        Belum ada adjust stok
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($log->hasPages())
                    <div class="border-t border-slate-100 p-4">{{ $log->links() }}</div>
                @endif
            </section>
        @endif
    </div>
</x-layouts.app>
