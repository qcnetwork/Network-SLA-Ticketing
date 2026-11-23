<x-layouts.app title="Log Pengambilan">
    @include('warehouses.partials.nav')
    <div class="mb-6 flex items-center gap-3">
        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-indigo-600 text-white shadow-sm"><i data-lucide="hand" class="h-6 w-6"></i></span>
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Log Pengambilan</h1>
            <p class="text-sm text-slate-500">Barang yang dikirim dari Gudang Utama: masuk stok siapa, dan siapa yang mengambil. Pengambilan atas nama teknisi lain ditandai kuning.</p>
        </div>
    </div>

    <form method="GET" class="card mb-4 grid items-end gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6">
        {{-- Opened from a technician's page (?gudang=): the owner picker starts on that technician. --}}
        <div class="lg:col-span-2">
            @include('warehouses.partials.technician-select', ['technicians' => $people, 'field' => 'pemilik', 'label' => 'Masuk stok milik', 'placeholder' => 'Semua · ketik nama teknisi', 'required' => false,
                'selected' => $filters['owner'] !== '' ? $filters['owner'] : $filteredWarehouse?->user_id])
        </div>
        <div class="lg:col-span-2">
            @include('warehouses.partials.technician-select', ['technicians' => $people, 'field' => 'pengambil', 'label' => 'Diambil oleh', 'placeholder' => 'Semua · ketik nama pengambil', 'required' => false,
                'selected' => $filters['collector']])
        </div>
        <div class="flex gap-2 lg:col-span-2">
            <input type="date" name="dari" value="{{ $filters['from']->toDateString() }}" class="input" title="Dari tanggal">
            <input type="date" name="sampai" value="{{ $filters['to']->toDateString() }}" class="input" title="Sampai tanggal">
        </div>
        <button class="btn-primary"><i data-lucide="filter" class="h-4 w-4"></i>Tampilkan</button>
        <label class="flex items-center gap-2 text-sm text-slate-600 sm:col-span-2 lg:col-span-6">
            <input type="checkbox" name="orang_lain" value="1" @checked($filters['others']) class="rounded border-slate-300">
            Hanya yang diambil orang lain (bukan pemilik stok)
        </label>
    </form>

    <p class="mb-3 text-sm text-slate-500">
        <b class="text-slate-800">{{ number_format($totals['all'], 0, ',', '.') }}</b> pengiriman ·
        <b class="{{ $totals['others'] ? 'text-amber-700' : 'text-slate-800' }}">{{ number_format($totals['others'], 0, ',', '.') }}</b> diambil orang lain
    </p>

    <section class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase">
                    <tr>
                        <th class="px-5 py-3">Waktu</th>
                        <th class="px-4 py-3">Barang</th>
                        <th class="px-4 py-3 text-right">Jumlah</th>
                        <th class="px-4 py-3">Masuk stok</th>
                        <th class="px-4 py-3">Diambil oleh</th>
                        <th class="px-4 py-3">Dicatat</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($movements as $movement)
                        @php($owner = $movement->toWarehouse?->user)
                        <tr @class(['align-top', 'bg-amber-50/50' => $movement->takenBy])>
                            <td class="px-5 py-2.5 whitespace-nowrap text-slate-600">{{ $movement->created_at->format('d/m/Y') }} <span class="block text-xs text-slate-400">{{ $movement->created_at->format('H:i') }}</span></td>
                            <td class="px-4 py-2.5">
                                <p class="font-medium text-slate-800">{{ $movement->item?->name }}</p>
                                <p class="text-xs text-slate-500"><span class="font-mono">{{ $movement->item?->code }}</span>{{ $movement->serialUnit ? ' · SN '.$movement->serialUnit->serial_number : '' }}</p>
                            </td>
                            <td class="px-4 py-2.5 text-right whitespace-nowrap font-semibold">{{ number_format($movement->quantity, 0, ',', '.') }} <span class="text-xs font-normal text-slate-500">{{ $movement->item?->unit }}</span></td>
                            <td class="px-4 py-2.5">
                                @if ($movement->toWarehouse)
                                    <a href="{{ route('warehouses.show', $movement->toWarehouse) }}" class="font-medium text-indigo-700 hover:underline">{{ $owner?->name ?? $movement->toWarehouse->name }}</a>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                @if ($movement->takenBy)
                                    <span class="font-semibold text-amber-800">{{ $movement->takenBy->name }}</span>
                                    <span class="block text-xs text-amber-700">atas nama {{ $owner?->name ?? '-' }}</span>
                                @else
                                    <span class="text-slate-700">{{ $owner?->name ?? '-' }}</span>
                                    <span class="block text-xs text-slate-400">sendiri</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-xs text-slate-500">{{ $movement->user?->name ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-slate-400">
                                <i data-lucide="hand" class="mx-auto mb-2 h-8 w-8 text-slate-300"></i>
                                Belum ada pengambilan di periode ini
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($movements->hasPages())
            <div class="border-t border-slate-100 p-4">{{ $movements->links() }}</div>
        @endif
    </section>
</x-layouts.app>
