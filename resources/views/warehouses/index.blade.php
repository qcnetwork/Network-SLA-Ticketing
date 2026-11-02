<x-layouts.app title="Gudang">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Gudang</h1>
            <p class="text-sm text-slate-500">Gudang Utama menampung semua stok kantor. Barang yang dikirim ke teknisi pindah ke gudang teknisi, lalu berkurang saat dipakai di Resume Perbaikan.</p>
        </div>
        @if ($canManage)
            <a href="{{ route('items.index') }}" class="btn-secondary"><i data-lucide="table" class="h-4 w-4"></i>Master Barang</a>
        @endif
    </div>

    <datalist id="item_options">
        @foreach ($items as $item)
            <option value="{{ $item->code }} · {{ $item->name }}">
        @endforeach
    </datalist>

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
            @include('warehouses.partials.stock-table', ['title' => $main->name])
            @include('warehouses.partials.movements')
        </div>

        <aside class="space-y-4">
            @if ($canManage)
                <form method="POST" action="{{ route('warehouses.receive-serials') }}" class="card space-y-3 p-5">
                    @csrf
                    <h3 class="flex items-center gap-2 font-semibold"><i data-lucide="scan-barcode" class="h-4 w-4 text-emerald-600"></i>Modem Masuk (Scan SN)</h3>
                    <p class="text-sm text-slate-500">Pilih jenis modem, lalu scan SN dan MAC tiap unit satu per satu.</p>
                    <select name="item" class="input" required>
                        <option value="">Pilih jenis modem</option>
                        @foreach ($serialItems as $item)
                            <option value="{{ $item->code }}" @selected(old('item') === $item->code)>{{ $item->code }} · {{ $item->name }}</option>
                        @endforeach
                    </select>
                    <x-serial-scan :query="['new' => 1]" with-mac />
                    <input name="notes" class="input" maxlength="255" placeholder="Keterangan paket, mis. PAKET 19 AGUSTUS dari PT X (opsional)">
                    <button class="btn-success w-full"><i data-lucide="download" class="h-4 w-4"></i>Simpan Modem Masuk</button>
                </form>

                <form method="POST" action="{{ route('warehouses.send-serials') }}" class="card space-y-3 p-5">
                    @csrf
                    <h3 class="flex items-center gap-2 font-semibold"><i data-lucide="truck" class="h-4 w-4 text-indigo-600"></i>Kirim Modem ke Teknisi (Scan SN)</h3>
                    <p class="text-sm text-slate-500">Scan SN modem yang dibawa teknisi. Hanya SN yang ada di Gudang Utama yang diterima.</p>
                    <select name="technician_id" class="input" required>
                        <option value="">Pilih teknisi</option>
                        @foreach ($technicians as $technician)
                            <option value="{{ $technician->id }}">{{ $technician->name }}</option>
                        @endforeach
                    </select>
                    <x-serial-scan :query="['warehouse' => $main->id]" />
                    <input name="notes" class="input" maxlength="255" placeholder="Keterangan (opsional)">
                    <button class="btn-primary w-full"><i data-lucide="truck" class="h-4 w-4"></i>Kirim Modem</button>
                </form>

                <form method="POST" action="{{ route('warehouses.receive') }}" class="card space-y-3 p-5">
                    @csrf
                    <h3 class="flex items-center gap-2 font-semibold"><i data-lucide="download" class="h-4 w-4 text-emerald-600"></i>Barang Masuk (Jumlah)</h3>
                    <p class="text-sm text-slate-500">Material tanpa SN (kabel, adaptor, dll) masuk ke Gudang Utama.</p>
                    <input name="item" list="item_options" class="input" required placeholder="Kode / nama barang" value="{{ old('item') }}">
                    <input name="quantity" type="number" min="1" class="input" required placeholder="Jumlah" value="{{ old('quantity') }}">
                    <input name="notes" class="input" maxlength="255" placeholder="Keterangan / SN (opsional)" value="{{ old('notes') }}">
                    <button class="btn-success w-full"><i data-lucide="download" class="h-4 w-4"></i>Simpan Barang Masuk</button>
                </form>

                <form method="POST" action="{{ route('warehouses.send') }}" class="card space-y-3 p-5">
                    @csrf
                    <h3 class="flex items-center gap-2 font-semibold"><i data-lucide="truck" class="h-4 w-4 text-indigo-600"></i>Kirim Material ke Teknisi (Jumlah)</h3>
                    <p class="text-sm text-slate-500">Stok pindah dari Gudang Utama ke gudang teknisi.</p>
                    <select name="technician_id" class="input" required>
                        <option value="">Pilih teknisi</option>
                        @foreach ($technicians as $technician)
                            <option value="{{ $technician->id }}" @selected(old('technician_id') == $technician->id)>{{ $technician->name }}</option>
                        @endforeach
                    </select>
                    <input name="item" list="item_options" class="input" required placeholder="Kode / nama barang">
                    <input name="quantity" type="number" min="1" class="input" required placeholder="Jumlah">
                    <input name="notes" class="input" maxlength="255" placeholder="Keterangan / SN (opsional)">
                    <button class="btn-primary w-full"><i data-lucide="truck" class="h-4 w-4"></i>Kirim</button>
                </form>
                @if ($errors->any())
                    <div class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif
            @endif

            <section class="card p-5">
                <h3 class="mb-3 flex items-center gap-2 font-semibold"><i data-lucide="users" class="h-4 w-4"></i>Gudang Teknisi</h3>
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse ($technicianWarehouses as $warehouse)
                        <li>
                            <a href="{{ route('warehouses.show', $warehouse) }}" class="flex items-center justify-between gap-2 py-2 hover:text-indigo-700">
                                <span class="font-medium">{{ $warehouse->user?->name ?? $warehouse->name }}</span>
                                <span class="text-xs text-slate-500">{{ $warehouse->stocks_count }} jenis · {{ (int) $warehouse->stocks_sum_quantity }} unit</span>
                            </a>
                        </li>
                    @empty
                        <li class="py-4 text-center text-slate-400">Belum ada teknisi yang memegang stok</li>
                    @endforelse
                </ul>
            </section>
        </aside>
    </div>
</x-layouts.app>
