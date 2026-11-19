<x-layouts.app title="Gudang">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-indigo-600 text-white shadow-sm"><i data-lucide="warehouse" class="h-6 w-6"></i></span>
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Gudang</h1>
                <p class="text-sm text-slate-500">Stok kantor di Gudang Utama, dikirim ke gudang teknisi (berkurang saat material Resume Perbaikan di-ACC) atau dijual ke mitra.</p>
            </div>
        </div>
        @if ($canManage)
            <div class="flex shrink-0 flex-wrap gap-2">
                <a href="{{ route('usages.index') }}" class="btn-secondary"><i data-lucide="clipboard-list" class="h-4 w-4"></i>Pemakaian Alat</a>
                <a href="{{ route('sales.create') }}" class="btn-primary"><i data-lucide="shopping-cart" class="h-4 w-4"></i>Jual Barang</a>
                <a href="{{ route('sales.index') }}" class="btn-secondary"><i data-lucide="receipt" class="h-4 w-4"></i>Log Penjualan</a>
                <a href="{{ route('partners.index') }}" class="btn-secondary"><i data-lucide="handshake" class="h-4 w-4"></i>Mitra</a>
                <a href="{{ route('items.index') }}" class="btn-secondary"><i data-lucide="boxes" class="h-4 w-4"></i>Master Barang</a>
            </div>
        @endif
    </div>

    @include('warehouses.partials.summary', ['tiles' => [
        ['Jenis Barang', $summary['kinds'], 'layers', 'indigo', 'di Gudang Utama'],
        ['Total Unit', $summary['units'], 'package', 'emerald', 'semua satuan'],
        ['Perangkat ber-SN', $summary['serials'], 'scan-barcode', 'violet', 'siap dikirim'],
        ['Stok Menipis', $lowStock->count(), 'circle-alert', $lowStock->isEmpty() ? 'slate' : 'rose', 'di bawah minimum'],
        ['Menunggu ACC', $materialRequests->count(), 'hourglass', $materialRequests->isEmpty() ? 'slate' : 'amber', 'pengajuan material'],
    ]])

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @if ($serialMatches->isNotEmpty())
                <section class="card p-5">
                    <h2 class="mb-3 flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="scan-barcode" class="h-4 w-4"></i>Posisi SN</h2>
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach ($serialMatches as $unit)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span>
                                    <span class="font-mono font-semibold">{{ $unit->serial_number }}</span>
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
            @include('warehouses.partials.stock-tabs', ['title' => $main->name, 'showLow' => true])
        </div>

        <aside class="order-first space-y-6 lg:order-none">
            @if ($canManage)
                {{-- One panel for everything that moves stock out of or into the main warehouse. --}}
                <section x-data="{ tab: @js(str_starts_with((string) old('_tab'), 'kirim') ? 'kirim' : 'masuk'), send: @js(old('_tab') === 'kirim-jumlah' ? 'jumlah' : 'sn') }" class="card">
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

                        {{-- Receive: the item decides whether units are scanned (device) or counted. --}}
                        <form x-show="tab === 'masuk'" method="POST" :action="action" class="space-y-4"
                            x-data="stockForm({ catalog: @js($catalog), serialAction: @js(route('warehouses.receive-serials')), countAction: @js(route('warehouses.receive')), selected: @js(old('_tab') === 'masuk' ? (string) old('item') : '') })">
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
                                <x-serial-scan :query="['new' => 1]" with-mac mac-toggle />
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

                        {{-- Send: devices go by scanned SN, everything else by item and quantity. --}}
                        <div x-show="tab === 'kirim'" x-cloak class="space-y-4">
                            <div class="grid grid-cols-2 rounded-lg bg-slate-100 p-1 text-xs font-semibold">
                                <button type="button" @click="send = 'sn'" :class="send === 'sn' ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-500'" class="flex items-center justify-center gap-1.5 rounded-md px-3 py-2">
                                    <i data-lucide="scan-barcode" class="h-3.5 w-3.5"></i>Perangkat (scan SN)
                                </button>
                                <button type="button" @click="send = 'jumlah'" :class="send === 'jumlah' ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-500'" class="flex items-center justify-center gap-1.5 rounded-md px-3 py-2">
                                    <i data-lucide="boxes" class="h-3.5 w-3.5"></i>Material (jumlah)
                                </button>
                            </div>

                            <form x-show="send === 'sn'" method="POST" action="{{ route('warehouses.send-serials') }}" class="space-y-4">
                                @csrf
                                <input type="hidden" name="_tab" value="kirim-sn">
                                @include('warehouses.partials.technician-select', ['selected' => old('_tab') === 'kirim-sn' ? old('technician_id') : null])
                                <div class="space-y-2">
                                    <label class="label">Scan SN perangkat</label>
                                    <p class="text-xs text-slate-500">Modem, switch, Mikrotik, dll. Hanya SN yang ada di Gudang Utama yang diterima.</p>
                                    <x-serial-scan :query="['warehouse' => $main->id]" />
                                </div>
                                <input name="notes" class="input" maxlength="255" placeholder="Keterangan (opsional)">
                                <button class="btn-primary w-full"><i data-lucide="truck" class="h-4 w-4"></i>Kirim Perangkat</button>
                            </form>

                            <form x-show="send === 'jumlah'" x-cloak method="POST" :action="action" class="space-y-4"
                                x-data="stockForm({ catalog: @js($catalog), countAction: @js(route('warehouses.send')), selected: @js(old('_tab') === 'kirim-jumlah' ? (string) old('item') : ''), countedOnly: true, inStockOnly: true })">
                                @csrf
                                <input type="hidden" name="_tab" value="kirim-jumlah">
                                @include('warehouses.partials.technician-select', ['selected' => old('_tab') === 'kirim-jumlah' ? old('technician_id') : null])
                                <div>
                                    <label class="label">Barang</label>
                                    @include('warehouses.partials.item-picker', ['placeholder' => 'Cari material yang ada di Gudang Utama'])
                                </div>
                                <div x-show="item" x-cloak>
                                    <label class="label" for="send_quantity">Jumlah <span class="font-normal text-slate-400" x-text="`(tersedia ${item?.held ?? 0})`"></span></label>
                                    <div class="flex">
                                        <input id="send_quantity" x-ref="quantity" name="quantity" type="number" min="1" :max="item?.held" required class="input rounded-r-none" placeholder="0">
                                        <span class="inline-flex items-center rounded-r-lg border border-l-0 border-slate-300 bg-slate-50 px-3 text-sm text-slate-500" x-text="item?.unit"></span>
                                    </div>
                                </div>
                                <input name="notes" class="input" maxlength="255" placeholder="Keterangan (opsional)">
                                <button class="btn-primary w-full" :disabled="! item"><i data-lucide="truck" class="h-4 w-4"></i>Kirim Material</button>
                            </form>
                        </div>
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
