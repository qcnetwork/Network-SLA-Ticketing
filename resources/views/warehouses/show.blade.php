<x-layouts.app :title="$warehouse->name">
    @unless (auth()->user()->role === App\Enums\Role::TEKNISI)
        <a href="{{ route('warehouses.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-700">
            <i data-lucide="arrow-left" class="h-4 w-4"></i>Kembali ke Gudang Utama
        </a>
    @endunless
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-900">{{ $warehouse->name }}</h1>
        <p class="text-sm text-slate-500">Stok yang dibawa {{ $warehouse->user?->name ?? 'teknisi' }}. Berkurang saat material di Resume Perbaikan di-ACC Gudang.</p>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @include('warehouses.partials.material-requests')
            @include('warehouses.partials.stock-table', ['title' => 'Stok'])
            @include('warehouses.partials.movements')
        </div>

        @if ($canManage)
            <aside class="space-y-4">
                <form method="POST" action="{{ route('warehouses.return-serials', $warehouse) }}" class="card space-y-3 p-5">
                    @csrf
                    <h3 class="flex items-center gap-2 font-semibold"><i data-lucide="scan-barcode" class="h-4 w-4 text-amber-600"></i>Retur Modem (Scan SN)</h3>
                    <p class="text-sm text-slate-500">Scan SN modem yang dikembalikan teknisi ke Gudang Utama.</p>
                    <x-serial-scan :query="['warehouse' => $warehouse->id]" />
                    <input name="notes" class="input" maxlength="255" placeholder="Alasan: sisa, rusak, error (opsional)">
                    <button class="btn-warning w-full"><i data-lucide="undo-2" class="h-4 w-4"></i>Retur Modem</button>
                </form>

                <form method="POST" action="{{ route('warehouses.return', $warehouse) }}" class="card space-y-3 p-5">
                    @csrf
                    <h3 class="flex items-center gap-2 font-semibold"><i data-lucide="undo-2" class="h-4 w-4 text-amber-600"></i>Retur Material (Jumlah)</h3>
                    <p class="text-sm text-slate-500">Barang sisa atau rusak yang dikembalikan teknisi.</p>
                    <select name="item" class="input" required>
                        <option value="">Pilih barang</option>
                        @foreach ($stocks->reject(fn ($stock) => $stock->item->tracks_serial) as $stock)
                            <option value="{{ $stock->item->code }}">{{ $stock->item->label() }} (sisa {{ $stock->quantity }})</option>
                        @endforeach
                    </select>
                    <input name="quantity" type="number" min="1" class="input" required placeholder="Jumlah">
                    <input name="notes" class="input" maxlength="255" placeholder="Alasan: sisa, rusak, SN (opsional)">
                    <button class="btn-warning w-full"><i data-lucide="undo-2" class="h-4 w-4"></i>Retur</button>
                    @if ($errors->any())
                        <div class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                            @foreach ($errors->all() as $error)
                                <p>{{ $error }}</p>
                            @endforeach
                        </div>
                    @endif
                </form>
            </aside>
        @endif
    </div>
</x-layouts.app>
