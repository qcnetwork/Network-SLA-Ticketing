{{-- Gudang Pelanggan: customers holding material, by customer ID, with the latest installs and removals. --}}
<x-layouts.app title="Gudang Pelanggan">
    @include('warehouses.partials.nav', ['back' => ['Gudang', route('warehouses.index')]])

    <div class="mb-6 flex items-center gap-3">
        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-emerald-600 text-white shadow-sm"><i data-lucide="house" class="h-6 w-6"></i></span>
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Gudang Pelanggan</h1>
            <p class="text-sm text-slate-500">Material yang terpasang di tiap pelanggan, berdasarkan ID Pelanggan. Tercatat otomatis saat material tiket di-ACC atau Pemakaian Alat diisi ID pelanggannya.</p>
        </div>
    </div>

    @include('warehouses.partials.summary', ['tiles' => [
        ['Pelanggan', $stats['customers'], 'users', 'emerald', 'punya material terpasang'],
        ['Material Terpasang', $stats['items'], 'package', 'indigo', 'baris material'],
        ['Unit SN Terpasang', $stats['units'], 'scan-barcode', 'violet', 'modem / perangkat'],
        ['Dilepas Bulan Ini', $stats['removed'], 'unplug', $stats['removed'] ? 'rose' : 'slate', 'kembali / dihapus'],
    ]])

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="card overflow-hidden lg:col-span-2">
            <form method="GET" class="flex gap-2 border-b border-slate-100 p-4">
                <div class="relative flex-1">
                    <i data-lucide="search" class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400"></i>
                    <input type="search" name="q" value="{{ $search }}" class="input pl-9" autocomplete="off" placeholder="Cari ID pelanggan, nama, SN / MAC, atau nama barang">
                </div>
                <button class="btn-primary">Cari</button>
                @if ($search !== '')
                    <a href="{{ route('customer-materials.index') }}" class="btn-secondary">Reset</a>
                @endif
            </form>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">Pelanggan</th>
                            <th class="px-4 py-3">Perangkat</th>
                            <th class="px-4 py-3 text-right">Material</th>
                            <th class="px-4 py-3">Terakhir Dipasang</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($customers as $row)
                            @php
                                $key = mb_strtolower($row->customer_code);
                                $customer = $directory[$key] ?? null;
                            @endphp
                            <tr class="align-top hover:bg-slate-50/60">
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('customer-materials.show', $row->customer_code) }}" class="font-medium text-indigo-700 hover:underline">{{ $customer?->name ?? 'Belum ada di data pelanggan' }}</a>
                                    <span class="block font-mono text-xs text-slate-500">{{ $row->customer_code }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-xs">
                                    @forelse (($devices[$key] ?? collect())->take(3) as $device)
                                        <span class="block"><span class="font-mono font-semibold text-slate-700">{{ $device->serial_number }}</span> <span class="text-slate-500">{{ Str::limit($device->item_name, 30) }}</span></span>
                                    @empty
                                        <span class="text-slate-400">-</span>
                                    @endforelse
                                </td>
                                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                    <span class="font-semibold text-slate-900">{{ $row->items }}</span>
                                    <span class="text-xs text-slate-500">item</span>
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">{{ \Illuminate\Support\Carbon::parse($row->last_installed)->format('d/m/Y') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-10 text-center text-slate-400">
                                    <i data-lucide="package-open" class="mx-auto mb-2 h-8 w-8 text-slate-300"></i>
                                    {{ $search !== '' ? 'Tidak ada material pelanggan yang cocok' : 'Belum ada material yang tercatat di pelanggan' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($customers->hasPages())
                <div class="border-t border-slate-100 p-4">{{ $customers->links() }}</div>
            @endif
        </section>

        <section class="card h-fit p-5">
            <h2 class="mb-3 flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="history" class="h-4 w-4"></i>Log Terbaru</h2>
            <ol class="space-y-3 text-sm">
                @forelse ($logs as $log)
                    <li class="flex gap-2.5">
                        <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full border {{ $log->badgeClasses() }}"><i data-lucide="{{ $log->icon() }}" class="h-3 w-3"></i></span>
                        <div class="min-w-0">
                            <p class="text-slate-800"><b>{{ $log->label() }}</b> · {{ $log->item_name }}{{ $log->serial_number ? '' : " × {$log->quantity}" }}</p>
                            @if ($log->serial_number)
                                <p class="font-mono text-xs text-slate-600">{{ $log->serial_number }}</p>
                            @endif
                            <a href="{{ route('customer-materials.show', $log->customer_code) }}" class="text-xs text-indigo-700 hover:underline">{{ $names[mb_strtolower($log->customer_code)] ?? $log->customer_code }}</a>
                            <p class="text-[11px] text-slate-400">{{ $log->happened_at->format('d/m/Y H:i') }} · {{ $log->user?->name ?? 'Sistem' }}</p>
                        </div>
                    </li>
                @empty
                    <li class="py-4 text-center text-slate-400">Belum ada log</li>
                @endforelse
            </ol>
        </section>
    </div>
</x-layouts.app>
