<x-layouts.app title="Gudang">
    @include('warehouses.partials.nav')
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-indigo-600 text-white shadow-sm"><i data-lucide="warehouse" class="h-6 w-6"></i></span>
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Gudang</h1>
                <p class="text-sm text-slate-500">Stok kantor di Gudang Utama, dikirim ke gudang teknisi (berkurang saat material Resume Perbaikan di-ACC) atau dijual ke mitra.</p>
            </div>
        </div>
        @if ($canManage)
            <a href="{{ route('sales.create') }}" class="btn-primary shrink-0"><i data-lucide="shopping-cart" class="h-4 w-4"></i>Jual Barang</a>
        @endif
    </div>

    @if ($canManage)
        {{-- Scan or type any SN / MAC to see where it is: idle in a warehouse or already used. --}}
        <form method="GET" action="{{ route('tracking.index') }}" class="card mb-6 flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
            <label for="sn_search" class="flex shrink-0 items-center gap-2 font-semibold text-slate-800">
                <span class="grid h-9 w-9 place-items-center rounded-lg bg-violet-100 text-violet-700"><i data-lucide="scan-barcode" class="h-5 w-5"></i></span>
                Cari SN
            </label>
            <div class="flex flex-1 gap-2">
                <input id="sn_search" type="search" name="q" class="input flex-1 font-mono" autocomplete="off" spellcheck="false"
                    placeholder="Scan / ketik SN atau MAC, nama pelanggan, teknisi…">
                <button class="btn-primary shrink-0"><i data-lucide="search" class="h-4 w-4"></i><span class="hidden sm:inline">Cari</span></button>
            </div>
            <div class="flex shrink-0 gap-2 text-xs font-semibold">
                <a href="{{ route('tracking.index', ['posisi' => 'idle']) }}" class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-emerald-700 hover:border-emerald-400">{{ number_format($serialTotals['idle'], 0, ',', '.') }} idle</a>
                <a href="{{ route('tracking.index', ['posisi' => 'terpakai']) }}" class="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-amber-700 hover:border-amber-400">{{ number_format($serialTotals['used'], 0, ',', '.') }} terpakai</a>
            </div>
        </form>
    @endif

    @include('warehouses.partials.summary', ['tiles' => [
        ['Jenis Barang', $summary['kinds'], 'layers', 'indigo', 'di Gudang Utama'],
        ['Total Unit', $summary['units'], 'package', 'emerald', 'semua satuan'],
        ['Unit SN Idle', $summary['serials'], 'scan-barcode', 'violet', 'di Gudang Utama', $canManage ? route('tracking.index', ['gudang' => 'utama']) : null],
        ['Stok Menipis', $lowStock->count(), 'circle-alert', $lowStock->isEmpty() ? 'slate' : 'rose', 'di bawah minimum'],
        ['Menunggu ACC', $materialRequests->count(), 'hourglass', $materialRequests->isEmpty() ? 'slate' : 'amber', 'pengajuan material'],
    ]])

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Outside the warehouse team, searching the stock for an SN shows where that unit is. --}}
            @if (! $canManage && $serialMatches->isNotEmpty())
                <section class="card p-5">
                    <h2 class="mb-3 flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="scan-barcode" class="h-4 w-4"></i>Posisi SN</h2>
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach ($serialMatches as $unit)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span>
                                    @if ($canManage)
                                        <a href="{{ route('tracking.unit', $unit) }}" class="font-mono font-semibold text-indigo-700 hover:underline">{{ $unit->serial_number }}</a>
                                    @else
                                        <span class="font-mono font-semibold">{{ $unit->serial_number }}</span>
                                    @endif
                                    @if ($unit->mac)
                                        <span class="font-mono text-xs text-slate-500">· {{ $unit->formattedMac() }}</span>
                                    @endif
                                    <span class="block text-xs text-slate-500">{{ $unit->item->label() }}</span>
                                    @if ($unit->imported_at)
                                        {{-- History carried over from the TRACKING ALAT sheet. --}}
                                        <span class="block text-xs text-slate-400">
                                            {{ collect([$unit->install_status, $unit->holder_name ? 'Teknisi: '.$unit->holder_name : null, $unit->taken_at ? 'Diambil '.$unit->taken_at->format('d/m/Y') : null, $unit->replaced_device ? 'Ganti: '.$unit->replaced_device : null])->filter()->implode(' · ') }}
                                        </span>
                                    @endif
                                </span>
                                @if ($unit->ticket)
                                    <a href="{{ route('tickets.show', $unit->ticket) }}" class="text-xs font-medium text-indigo-700 hover:underline">{{ $unit->location() }} · {{ $unit->ticket->customer_name }}</a>
                                @else
                                    <span class="text-xs font-medium text-slate-700">{{ $unit->location() }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
            @include('warehouses.partials.material-requests')
            @include('warehouses.partials.stock-tabs', ['title' => $main->name, 'showLow' => true, 'stockWarehouse' => $main])
        </div>

        <aside class="order-first space-y-6 lg:order-none">
            @if ($canManage)
                {{-- One panel for everything that moves stock out of or into the main warehouse. --}}
                <section x-data="{ tab: @js(str_starts_with((string) old('_tab'), 'kirim') ? 'kirim' : 'masuk') }" class="card">
                    <div class="grid grid-cols-2 border-b border-slate-200 text-sm font-semibold">
                        <button type="button" @click="tab = 'masuk'" :class="tab === 'masuk' ? 'border-emerald-600 text-emerald-700 bg-emerald-50/50' : 'border-transparent text-slate-500 hover:text-slate-700'" class="flex items-center justify-center gap-2 rounded-tl-xl border-b-2 px-4 py-3">
                            <i data-lucide="download" class="h-4 w-4"></i>Barang Masuk
                        </button>
                        <button type="button" @click="tab = 'kirim'" :class="tab === 'kirim' ? 'border-indigo-600 text-indigo-700 bg-indigo-50/50' : 'border-transparent text-slate-500 hover:text-slate-700'" class="flex items-center justify-center gap-2 rounded-tr-xl border-b-2 px-4 py-3">
                            <i data-lucide="truck" class="h-4 w-4"></i>Kirim ke Teknisi / HD
                        </button>
                    </div>

                    <div class="p-5">
                        @if ($errors->any())
                            <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                                @foreach ($errors->all() as $error)
                                    <p>{{ $error }}</p>
                                @endforeach
                            </div>
                        @endif

                        {{-- Receive: the item decides whether units are scanned (SN / barcode) or counted. --}}
                        <form x-show="tab === 'masuk'" method="POST" :action="action" class="space-y-4"
                            x-data="stockForm({ catalog: @js($catalog), serialAction: @js(route('warehouses.receive-serials')), countAction: @js(route('warehouses.receive')), selected: @js(old('_tab') === 'masuk' ? (string) old('item') : ''), withLoose: false })">
                            @csrf
                            <input type="hidden" name="_tab" value="masuk">
                            <div>
                                <label class="label">1. Pilih barang</label>
                                @include('warehouses.partials.item-picker')
                            </div>

                            <div x-show="item && isSerial" x-cloak class="space-y-2">
                                <label class="label">2. Scan SN tiap unit</label>
                                <div class="flex gap-2 rounded-lg border border-violet-200 bg-violet-50 p-2.5 text-xs text-violet-800">
                                    <i data-lucide="scan-barcode" class="mt-0.5 h-4 w-4 shrink-0"></i>
                                    <span>Kategori <b x-text="item?.category"></b> dicatat per unit lewat SN, bukan jumlah. Scan banyak unit sekaligus di bawah; SN yang sudah ada di sistem ditolak supaya tidak tercatat dobel.</span>
                                </div>
                                <x-serial-scan :query="['new' => 1]" follow-item />
                            </div>

                            <div x-show="item && ! isSerial" x-cloak>
                                <label class="label" for="receive_quantity">2. Jumlah masuk</label>
                                <div class="flex">
                                    <input id="receive_quantity" x-ref="quantity" name="quantity" type="number" min="1" class="input rounded-r-none" placeholder="0"
                                        :required="! isSerial" :disabled="isSerial" value="{{ old('_tab') === 'masuk' ? old('quantity') : '' }}">
                                    <span class="inline-flex items-center rounded-r-lg border border-l-0 border-slate-300 bg-slate-50 px-3 text-sm text-slate-500" x-text="item?.unit"></span>
                                </div>
                            </div>

                            <div x-show="item" x-cloak>
                                <label class="label" for="receive_notes">Keterangan <span class="font-normal text-slate-400">(opsional)</span></label>
                                <input id="receive_notes" name="notes" class="input" maxlength="255" placeholder="mis. PAKET 19 AGUSTUS dari PT X">
                            </div>
                            <button class="btn-success w-full" :disabled="! item"><i data-lucide="download" class="h-4 w-4"></i>Simpan Barang Masuk</button>
                        </form>

                        {{-- Send: pick the item first. An SN item lists its units in the main warehouse to tick (oldest first;
                             a scanner still works in the search box), anything else asks for a quantity. --}}
                        <form x-show="tab === 'kirim'" x-cloak method="POST" :action="action" class="space-y-4"
                            x-data="stockForm({ catalog: @js($catalog), serialAction: @js(route('warehouses.send-serials')), countAction: @js(route('warehouses.send')), selected: @js(old('_tab') === 'kirim' ? (string) old('item') : ''), inStockOnly: true })">
                            @csrf
                            <input type="hidden" name="_tab" value="kirim">
                            @include('warehouses.partials.technician-select', ['selected' => old('_tab') === 'kirim' ? old('technician_id') : null])

                            {{-- Quick SN search: a few digits from the label find the unit in any item, no scanner needed. --}}
                            <div class="relative" x-data="snFinder({ url: @js(route('warehouses.find-serials')) })" @click.outside="open = false">
                                <label class="label" for="sn-finder">Cari SN langsung</label>
                                <div class="relative">
                                    <i data-lucide="search" class="pointer-events-none absolute top-2.5 left-3 h-4 w-4 text-slate-400"></i>
                                    <input id="sn-finder" x-ref="input" x-model="q" @input="onInput()" @focus="open = results.length > 0"
                                        @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="pickActive()" @keydown.escape="open = false"
                                        class="input pl-9 font-mono" autocomplete="off" autocapitalize="characters" spellcheck="false"
                                        placeholder="Ketik 4 digit terakhir SN / MAC">
                                    <span x-show="searching" x-cloak class="absolute top-2.5 right-3 text-xs text-slate-400">mencari...</span>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">Tanpa scan: ketik minimal 3 karakter dari SN di label, lalu klik unitnya. Barangnya terpilih otomatis dan SN langsung dicentang.</p>
                                <div x-show="open" x-cloak class="absolute z-30 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
                                    <template x-for="(unit, index) in results" :key="unit.sn">
                                        <button type="button" @click="pick(unit)" @mouseenter="active = index" :class="index === active ? 'bg-indigo-50' : ''"
                                            class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm">
                                            <span class="min-w-0">
                                                <span class="block font-mono text-slate-800"><span x-text="parts(unit.sn)[0]"></span><b class="text-indigo-700" x-text="parts(unit.sn)[1]"></b><span x-text="parts(unit.sn)[2]"></span></span>
                                                <span class="block truncate text-xs text-slate-500" x-text="unit.name"></span>
                                            </span>
                                            <span x-show="unit.mac" class="shrink-0 font-mono text-xs text-slate-400" x-text="unit.mac"></span>
                                        </button>
                                    </template>
                                    <p x-show="! results.length" class="px-3 py-3 text-center text-sm text-slate-400">Tidak ada SN itu di Gudang Utama</p>
                                    <p x-show="results.length >= 30" class="border-t border-slate-100 px-3 py-1.5 text-center text-xs text-slate-400">Ketik lebih banyak digit untuk mempersempit</p>
                                </div>
                            </div>

                            <div>
                                <label class="label">Barang</label>
                                @include('warehouses.partials.item-picker', ['placeholder' => 'Cari barang yang ada di Gudang Utama'])
                            </div>

                            {{-- SN item: tick the units to send. --}}
                            <div x-show="item && isSerial" x-cloak class="space-y-2"
                                x-data="serialPicker({ url: @js(route('warehouses.available-serials')), warehouse: @js($main->id) })" x-effect="load(item); claim()">
                                <div class="flex items-center justify-between gap-2">
                                    <label class="label mb-0">Pilih SN yang dikirim</label>
                                    <span class="text-xs font-semibold" :class="selected.length ? 'text-indigo-700' : 'text-slate-400'" x-text="`${selected.length} dari ${units.length} dipilih`"></span>
                                </div>
                                <div class="relative">
                                    <i data-lucide="scan-barcode" class="pointer-events-none absolute top-2.5 left-3 h-4 w-4 text-slate-400"></i>
                                    <input x-ref="sn" x-model="filter" @keydown.enter.prevent="scan()" @input="error = ''" class="input pl-9 font-mono text-sm" autocomplete="off" spellcheck="false"
                                        placeholder="Ketik 4 digit terakhir SN / MAC, atau scan">
                                </div>
                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    <span class="text-slate-500">Ambil</span>
                                    <input x-model="take" @keydown.enter.prevent="takeOldest()" type="number" min="1" :max="units.length" class="input w-16 py-1 text-xs" placeholder="0">
                                    <button type="button" @click="takeOldest()" class="btn-secondary px-2 py-1 text-xs">unit terlama</button>
                                    <span class="flex-1"></span>
                                    <button type="button" x-show="filter" @click="selectShown()" class="font-semibold text-indigo-700 hover:underline">Pilih semua hasil cari</button>
                                    <button type="button" x-show="selected.length" @click="clearAll()" class="font-semibold text-rose-600 hover:underline">Kosongkan</button>
                                </div>
                                <p x-show="error" x-cloak x-text="error" class="rounded-lg bg-rose-50 p-2 text-xs font-medium text-rose-700"></p>
                                <div class="max-h-72 overflow-y-auto rounded-lg border border-slate-200">
                                    <p x-show="loading" class="px-3 py-4 text-center text-xs text-slate-500">Memuat SN...</p>
                                    <p x-show="! loading && ! units.length" class="px-3 py-4 text-center text-xs text-slate-400">Tidak ada unit barang ini di Gudang Utama</p>
                                    <p x-show="! loading && units.length && ! shown.length" class="px-3 py-4 text-center text-xs text-slate-400">Tidak ada SN yang cocok</p>
                                    <ul class="divide-y divide-slate-100 text-xs">
                                        <template x-for="unit in shown" :key="unit.sn">
                                            <li>
                                                <label class="flex cursor-pointer items-center gap-2.5 px-3 py-2 hover:bg-slate-50" :class="isChecked(unit) && 'bg-indigo-50/70'">
                                                    <input type="checkbox" :checked="isChecked(unit)" @change="toggle(unit)" class="rounded border-slate-300">
                                                    <span class="font-mono font-semibold text-slate-800" x-text="unit.sn"></span>
                                                    <span x-show="unit.mac" class="font-mono text-slate-500" x-text="unit.mac"></span>
                                                </label>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                                <template x-for="(sn, i) in selected" :key="sn">
                                    <input type="hidden" :name="`serials[${i}][sn]`" :value="sn">
                                </template>
                                <p x-show="item?.converts && selected.length" x-cloak class="flex items-center gap-1.5 text-xs text-indigo-700">
                                    <i data-lucide="repeat" class="h-3.5 w-3.5"></i>
                                    <span x-text="item?.converts ? `Teknisi menerima ${selected.length * item.converts.ratio} ${item.converts.unit} ${item.converts.code}` : ''"></span>
                                </p>
                                <x-field-error name="serials" />
                                <input name="notes" class="input" maxlength="255" placeholder="Keterangan (opsional)" :disabled="! isSerial">
                                <button class="btn-primary w-full" :disabled="! selected.length"><i data-lucide="truck" class="h-4 w-4"></i><span x-text="selected.length ? `Kirim ${selected.length} unit` : 'Pilih SN dulu'"></span></button>
                            </div>

                            {{-- Counted item: a quantity. --}}
                            <div x-show="item && ! isSerial" x-cloak class="space-y-4">
                                <div>
                                    <label class="label" for="send_quantity">Jumlah <span class="font-normal text-slate-400" x-text="`(tersedia ${item?.held ?? 0})`"></span></label>
                                    <div class="flex">
                                        <input id="send_quantity" x-ref="quantity" x-model="quantity" name="quantity" type="number" min="1" :max="item?.held" :required="! isSerial" :disabled="isSerial" class="input rounded-r-none" placeholder="0">
                                        <span class="inline-flex items-center rounded-r-lg border border-l-0 border-slate-300 bg-slate-50 px-3 text-sm text-slate-500" x-text="item?.unit"></span>
                                    </div>
                                    <p x-show="item?.converts" x-cloak class="mt-1.5 flex items-center gap-1.5 text-xs text-indigo-700">
                                        <i data-lucide="repeat" class="h-3.5 w-3.5"></i>
                                        <span x-text="item?.converts ? (Number(quantity) > 0
                                            ? `Teknisi menerima ${Number(quantity) * item.converts.ratio} ${item.converts.unit} ${item.converts.code}`
                                            : `1 ${item.unit} = ${item.converts.ratio} ${item.converts.unit} ${item.converts.code} di gudang teknisi`) : ''"></span>
                                    </p>
                                </div>
                                <input name="notes" class="input" maxlength="255" placeholder="Keterangan (opsional)" :disabled="isSerial">
                                <button class="btn-primary w-full"><i data-lucide="truck" class="h-4 w-4"></i>Kirim Material</button>
                            </div>
                        </form>
                    </div>
                </section>
            @endif

            @if ($lowStock->isNotEmpty())
                <section class="card border-rose-200 p-5">
                    <h3 class="mb-3 flex items-center gap-2 font-semibold text-rose-700"><i data-lucide="circle-alert" class="h-4 w-4"></i>Stok Menipis ({{ $lowStock->count() }})</h3>
                    <ul class="-mx-1 max-h-64 divide-y divide-slate-100 overflow-y-auto px-1 text-sm">
                        @foreach ($lowStock as $item)
                            <li class="flex items-center justify-between gap-2 py-2">
                                <span class="min-w-0">
                                    <span class="block truncate font-medium text-slate-800">{{ $item->name }}</span>
                                    <span class="text-xs text-slate-500"><span class="font-mono">{{ $item->code }}</span> · {{ $item->category?->name ?? 'Tanpa kategori' }}</span>
                                </span>
                                <span class="shrink-0 text-right text-xs">
                                    <span class="block text-sm font-bold {{ (int) $item->held === 0 ? 'text-rose-600' : 'text-amber-600' }}">{{ (int) $item->held }} {{ $item->unit }}</span>
                                    <span class="text-slate-400">min {{ $item->min_stock }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Technicians holding the most come first; empty warehouses hide unless asked for. --}}
            @php($emptyWarehouses = $technicianWarehouses->filter(fn ($warehouse) => (int) $warehouse->stocks_sum_quantity === 0)->count())
            <section class="card p-5" x-data="{ find: '', showEmpty: false }">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h3 class="flex items-center gap-2 font-semibold"><i data-lucide="users" class="h-4 w-4"></i>Gudang Teknisi &amp; Helpdesk</h3>
                    <span class="text-xs text-slate-500">{{ $technicianWarehouses->count() - $emptyWarehouses }} memegang stok</span>
                </div>
                @if ($technicianWarehouses->count() > 6)
                    <div class="relative mb-2">
                        <i data-lucide="search" class="pointer-events-none absolute top-2.5 left-3 h-4 w-4 text-slate-400"></i>
                        <input x-model="find" type="search" class="input pl-9 text-sm" placeholder="Cari teknisi / helpdesk">
                    </div>
                @endif
                <ul class="-mx-1 max-h-96 space-y-1 overflow-y-auto px-1 text-sm">
                    @forelse ($technicianWarehouses as $warehouse)
                        @php($holder = $warehouse->user?->name ?? $warehouse->name)
                        @php($empty = (int) $warehouse->stocks_sum_quantity === 0)
                        <li x-show="@js(Str::upper($holder)).includes(find.toUpperCase()) && (@js(! $empty) || showEmpty || find !== '')">
                            <a href="{{ route('warehouses.show', $warehouse) }}" class="flex items-center gap-3 rounded-lg px-2 py-2 transition hover:bg-indigo-50/60">
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full {{ $empty ? 'bg-slate-100 text-slate-400' : 'bg-indigo-100 text-indigo-700' }} text-xs font-bold">{{ Str::upper(Str::substr($holder, 0, 2)) }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium {{ $empty ? 'text-slate-500' : 'text-slate-800' }}">{{ $holder }}</span>
                                    <span class="text-xs text-slate-500">{{ $warehouse->stocks_count }} jenis · {{ $warehouse->serial_units_count }} SN</span>
                                </span>
                                <span class="shrink-0 text-right text-sm font-bold {{ $empty ? 'text-slate-300' : 'text-slate-800' }}">{{ number_format((int) $warehouse->stocks_sum_quantity, 0, ',', '.') }}</span>
                            </a>
                        </li>
                    @empty
                        <li class="py-4 text-center text-slate-400">Belum ada teknisi atau helpdesk yang memegang stok</li>
                    @endforelse
                </ul>
                @if ($emptyWarehouses > 0)
                    <button type="button" @click="showEmpty = ! showEmpty" class="mt-2 w-full text-center text-xs font-medium text-indigo-700 hover:underline"
                        x-text="showEmpty ? 'Sembunyikan gudang kosong' : 'Tampilkan {{ $emptyWarehouses }} gudang kosong'"></button>
                @endif
            </section>
        </aside>
    </div>
</x-layouts.app>
