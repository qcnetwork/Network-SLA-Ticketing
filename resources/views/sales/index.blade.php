@use('App\Enums\PaymentStatus')
@use('App\Models\Sale')
<x-layouts.app title="Log Penjualan">
    @php
        $query = array_filter(['q' => $filters['q'], 'mitra' => $filters['partner'], 'status' => $filters['status'], 'dari' => $filters['from'], 'sampai' => $filters['to']]);
    @endphp

    @include('warehouses.partials.nav', ['back' => ['Gudang', route('warehouses.index')]])
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-sky-600 text-white shadow-sm"><i data-lucide="receipt" class="h-6 w-6"></i></span>
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Log Penjualan</h1>
                <p class="text-sm text-slate-500">Barang Gudang Utama yang dijual ke mitra atau pembeli lain.</p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('sales.export', $query) }}" class="btn-secondary"><i data-lucide="file-spreadsheet" class="h-4 w-4 text-emerald-600"></i>Export Excel</a>
            <a href="{{ route('sales.create') }}" class="btn-primary"><i data-lucide="shopping-cart" class="h-4 w-4"></i>Jual Barang</a>
        </div>
    </div>

    @include('warehouses.partials.summary', ['tiles' => [
        ['Penjualan Bulan Ini', Sale::rupiah($stats['monthTotal']), 'trending-up', 'sky', $stats['monthCount'].' transaksi'],
        ['Belum Lunas', Sale::rupiah($stats['unpaidTotal']), 'hourglass', $stats['unpaidCount'] ? 'amber' : 'slate', $stats['unpaidCount'].' nota'],
        ['Total Sesuai Filter', Sale::rupiah($stats['filteredTotal']), 'sigma', 'indigo', $sales->total().' transaksi'],
    ]])

    <form method="GET" class="card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6">
        <div class="lg:col-span-2">
            <label class="label text-xs" for="q">Cari</label>
            <input id="q" name="q" value="{{ $filters['q'] }}" class="input" placeholder="No. nota, pembeli, barang, atau SN">
        </div>
        <div>
            <label class="label text-xs" for="mitra">Pembeli</label>
            <select id="mitra" name="mitra" class="input">
                <option value="">Semua</option>
                <option value="lain" @selected($filters['partner'] === 'lain')>Bukan mitra</option>
                @foreach ($partners as $partner)
                    <option value="{{ $partner->id }}" @selected($filters['partner'] === (string) $partner->id)>{{ $partner->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label text-xs" for="status">Pembayaran</label>
            <select id="status" name="status" class="input">
                <option value="">Semua</option>
                @foreach (PaymentStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label text-xs" for="dari">Dari</label>
            <input id="dari" name="dari" type="date" value="{{ $filters['from'] }}" class="input">
        </div>
        <div>
            <label class="label text-xs" for="sampai">Sampai</label>
            <input id="sampai" name="sampai" type="date" value="{{ $filters['to'] }}" class="input">
        </div>
        <div class="flex gap-2 sm:col-span-2 lg:col-span-6 lg:justify-end">
            @if ($query)
                <a href="{{ route('sales.index') }}" class="btn-secondary">Reset</a>
            @endif
            <button class="btn-primary"><i data-lucide="filter" class="h-4 w-4"></i>Terapkan</button>
        </div>
    </form>

    <div class="card overflow-x-auto">
        <table class="w-full min-w-[720px] text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                <tr>
                    <th class="px-4 py-3">No. Nota</th>
                    <th class="px-4 py-3">Tanggal</th>
                    <th class="px-4 py-3">Pembeli</th>
                    <th class="px-4 py-3 text-right">Barang</th>
                    <th class="px-4 py-3 text-right">Total</th>
                    <th class="px-4 py-3">Pembayaran</th>
                    <th class="px-4 py-3">Dicatat</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($sales as $sale)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3"><a href="{{ route('sales.show', $sale) }}" class="font-mono font-semibold text-indigo-700 hover:underline">{{ $sale->sale_number }}</a></td>
                        <td class="px-4 py-3 whitespace-nowrap text-slate-600">{{ $sale->sold_at->format('d/m/Y') }}</td>
                        <td class="px-4 py-3">
                            <span class="font-medium text-slate-800">{{ $sale->buyer_name }}</span>
                            <span class="block text-xs text-slate-500">{{ $sale->partner_id ? 'Mitra' : 'Bukan mitra' }}{{ $sale->buyer_phone ? ' · '.$sale->buyer_phone : '' }}</span>
                        </td>
                        <td class="px-4 py-3 text-right text-slate-600">{{ (int) $sale->lines_sum_quantity }}</td>
                        <td class="px-4 py-3 text-right font-semibold whitespace-nowrap text-slate-900">{{ Sale::rupiah($sale->total) }}</td>
                        <td class="px-4 py-3"><span class="badge {{ $sale->payment_status->badgeClasses() }}">{{ $sale->payment_status->label() }}</span></td>
                        <td class="px-4 py-3 text-xs text-slate-500">{{ $sale->user?->name ?? '-' }}<span class="block">{{ $sale->created_at->format('d/m H:i') }}</span></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-slate-400">{{ $query ? 'Tidak ada penjualan yang cocok dengan filter.' : 'Belum ada penjualan.' }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $sales->links() }}</div>
</x-layouts.app>
