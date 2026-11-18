<x-layouts.app :title="$warehouse->name">
    @unless (auth()->user()->role === App\Enums\Role::TEKNISI)
        <a href="{{ route('warehouses.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-700">
            <i data-lucide="arrow-left" class="h-4 w-4"></i>Kembali ke Gudang Utama
        </a>
    @endunless
    @php($holder = $warehouse->user?->name ?? 'Teknisi')
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex items-center gap-3">
        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-slate-800 text-sm font-bold text-white shadow-sm">{{ Str::upper(Str::substr($holder, 0, 2)) }}</span>
        <div>
            <h1 class="text-2xl font-bold text-slate-900">{{ $warehouse->name }}</h1>
            <p class="text-sm text-slate-500">Stok yang dibawa {{ $holder }}. Berkurang saat material di Resume Perbaikan di-ACC Gudang atau dicatat di Pemakaian Alat.</p>
        </div>
    </div>
    <div class="flex shrink-0 flex-wrap gap-2">
        <a href="{{ route('usages.index', $canManage ? ['stok' => $warehouse->id] : []) }}" class="btn-secondary"><i data-lucide="clipboard-list" class="h-4 w-4"></i>Riwayat Pemakaian</a>
        @if (auth()->user()->role === App\Enums\Role::TEKNISI)
            <a href="{{ route('usages.create') }}" class="btn-primary"><i data-lucide="plus" class="h-4 w-4"></i>Catat Pemakaian</a>
        @endif
    </div>
    </div>

    @include('warehouses.partials.summary', ['tiles' => [
        ['Jenis Barang', $summary['kinds'], 'layers', 'indigo', null],
        ['Total Unit', $summary['units'], 'package', 'emerald', null],
        ['Perangkat ber-SN', $summary['serials'], 'scan-barcode', 'violet', null],
        ['Menunggu ACC', $materialRequests->count(), 'hourglass', $materialRequests->isEmpty() ? 'slate' : 'amber', null],
    ]])

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 {{ $canManage ? 'lg:col-span-2' : 'lg:col-span-3' }}">
            @if ($rejectedTickets->isNotEmpty())
                <section class="card border-rose-200 p-5">
                    <h2 class="mb-1 flex items-center gap-2 font-semibold text-rose-700"><i data-lucide="refresh-cw" class="h-4 w-4"></i>Material Ditolak, Ajukan Ulang ({{ $rejectedTickets->count() }})</h2>
                    <p class="mb-3 text-sm text-slate-500">Buka tiketnya, lalu isi ulang material yang terpakai.</p>
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach ($rejectedTickets as $rejectedTicket)
                            @php($rejected = $rejectedTicket->materialRequests->last())
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span>
                                    <a href="{{ route('tickets.show', $rejectedTicket) }}" class="font-mono font-semibold text-indigo-700 hover:underline">{{ $rejectedTicket->ticket_number }}</a>
                                    · {{ $rejectedTicket->customer_name }}
                                    <span class="block text-xs text-rose-700">Ditolak {{ $rejected->reviewer?->name ?? 'Gudang' }}: {{ $rejected->review_notes }}</span>
                                </span>
                                <a href="{{ route('tickets.show', $rejectedTicket) }}" class="btn-secondary text-xs">Ajukan Ulang</a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
            @include('warehouses.partials.material-requests')
            @include('warehouses.partials.stock-table', ['title' => 'Stok'])
            @include('warehouses.partials.movements')
        </div>

        @if ($canManage)
            <aside class="order-first lg:order-none">
                <section x-data="{ mode: @js(old('_tab') === 'retur-jumlah' ? 'jumlah' : 'sn') }" class="card overflow-hidden">
                    <div class="flex items-center gap-2 border-b border-slate-200 bg-amber-50/60 px-5 py-3 font-semibold text-amber-800">
                        <i data-lucide="undo-2" class="h-4 w-4"></i>Retur ke Gudang Utama
                    </div>
                    <div class="space-y-4 p-5">
                        @if ($errors->any())
                            <div class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                                @foreach ($errors->all() as $error)
                                    <p>{{ $error }}</p>
                                @endforeach
                            </div>
                        @endif
                        <div class="grid grid-cols-2 rounded-lg bg-slate-100 p-1 text-xs font-semibold">
                            <button type="button" @click="mode = 'sn'" :class="mode === 'sn' ? 'bg-white text-amber-700 shadow-sm' : 'text-slate-500'" class="flex items-center justify-center gap-1.5 rounded-md px-3 py-2">
                                <i data-lucide="scan-barcode" class="h-3.5 w-3.5"></i>Perangkat (scan SN)
                            </button>
                            <button type="button" @click="mode = 'jumlah'" :class="mode === 'jumlah' ? 'bg-white text-amber-700 shadow-sm' : 'text-slate-500'" class="flex items-center justify-center gap-1.5 rounded-md px-3 py-2">
                                <i data-lucide="boxes" class="h-3.5 w-3.5"></i>Material (jumlah)
                            </button>
                        </div>

                        <form x-show="mode === 'sn'" method="POST" action="{{ route('warehouses.return-serials', $warehouse) }}" class="space-y-4">
                            @csrf
                            <input type="hidden" name="_tab" value="retur-sn">
                            <p class="text-xs text-slate-500">Scan SN perangkat yang dikembalikan {{ $holder }}.</p>
                            <x-serial-scan :query="['warehouse' => $warehouse->id]" />
                            <input name="notes" class="input" maxlength="255" placeholder="Alasan: sisa, rusak, error (opsional)">
                            <button class="btn-warning w-full"><i data-lucide="undo-2" class="h-4 w-4"></i>Retur Perangkat</button>
                        </form>

                        <form x-show="mode === 'jumlah'" x-cloak method="POST" :action="action" class="space-y-4"
                            x-data="stockForm({ catalog: @js($catalog), countAction: @js(route('warehouses.return', $warehouse)), selected: @js(old('_tab') === 'retur-jumlah' ? (string) old('item') : ''), countedOnly: true, inStockOnly: true })">
                            @csrf
                            <input type="hidden" name="_tab" value="retur-jumlah">
                            <div>
                                <label class="label">Barang</label>
                                @include('warehouses.partials.item-picker', ['placeholder' => "Cari material yang dibawa {$holder}"])
                            </div>
                            <div x-show="item" x-cloak>
                                <label class="label" for="return_quantity">Jumlah <span class="font-normal text-slate-400" x-text="`(dibawa ${item?.held ?? 0})`"></span></label>
                                <div class="flex">
                                    <input id="return_quantity" x-ref="quantity" name="quantity" type="number" min="1" :max="item?.held" required class="input rounded-r-none" placeholder="0">
                                    <span class="inline-flex items-center rounded-r-lg border border-l-0 border-slate-300 bg-slate-50 px-3 text-sm text-slate-500" x-text="item?.unit"></span>
                                </div>
                            </div>
                            <input name="notes" class="input" maxlength="255" placeholder="Alasan: sisa, rusak (opsional)">
                            <button class="btn-warning w-full" :disabled="! item"><i data-lucide="undo-2" class="h-4 w-4"></i>Retur Material</button>
                        </form>
                    </div>
                </section>
            </aside>
        @endif
    </div>
</x-layouts.app>
