<x-layouts.app title="Pelanggan">
    <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Pelanggan</h1>
            <p class="text-sm text-slate-500">Data pelanggan tersimpan otomatis dari tiket. Data terbaru yang ditulis di tiket menjadi data pelanggan.</p>
        </div>
        <form method="GET" action="{{ route('customers.index') }}" class="flex gap-2">
            <input type="search" name="q" value="{{ $search }}" class="input md:w-72" placeholder="Cari ID, nama, atau no. WhatsApp">
            <button class="btn-secondary"><i data-lucide="search" class="h-4 w-4"></i>Cari</button>
        </form>
    </div>

    <section class="card overflow-x-auto">
        {{-- Phones get a stacked list; the table needs more width than a phone has. --}}
        <ul class="divide-y divide-slate-100 md:hidden">
            @forelse ($customers as $customer)
                <li class="px-5 py-3">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-medium text-slate-800">{{ $customer->name }}</span>
                        <span class="font-mono text-xs text-slate-500">{{ $customer->code }}</span>
                    </div>
                    <div class="text-xs text-slate-500">{{ $customer->phone }} · {{ $customer->tickets_count }} tiket</div>
                    @if ($customer->address)
                        <div class="truncate text-xs text-slate-500">{{ $customer->address }}</div>
                    @endif
                    @if ($customer->hasLocation())
                        <div>
                                <a href="{{ $customer->mapsUrl() }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-mono text-xs text-indigo-700 hover:underline">
                                    <i data-lucide="map-pin" class="h-3.5 w-3.5"></i>{{ App\Support\GeoLocation::format($customer->latitude) }}, {{ App\Support\GeoLocation::format($customer->longitude) }}
                                </a>
                        </div>
                    @endif
                    @can('create', App\Models\Ticket::class)
                        <a href="{{ route('tickets.create', ['customer' => $customer->code]) }}" class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-indigo-700 hover:underline">
                            <i data-lucide="plus-circle" class="h-3.5 w-3.5"></i>Buat tiket
                        </a>
                    @endcan
                </li>
            @empty
                <li class="px-5 py-8 text-center text-sm text-slate-400">{{ $search !== '' ? 'Tidak ada pelanggan yang cocok' : 'Belum ada data pelanggan' }}</li>
            @endforelse
        </ul>
        <table class="hidden min-w-full divide-y divide-slate-200 text-sm md:table">
            <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase">
                <tr>
                    <th class="px-4 py-3">ID Pelanggan</th>
                    <th class="px-4 py-3">Nama</th>
                    <th class="px-4 py-3">WhatsApp</th>
                    <th class="px-4 py-3">Alamat</th>
                    <th class="px-4 py-3">Tikor</th>
                    <th class="px-4 py-3 text-right">Tiket</th>
                    <th class="px-4 py-3">Tiket Terakhir</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($customers as $customer)
                    <tr>
                        <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ $customer->code }}</td>
                        <td class="px-4 py-2 font-medium text-slate-800">{{ $customer->name }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ $customer->phone }}</td>
                        <td class="max-w-xs truncate px-4 py-2 text-slate-600" title="{{ $customer->address }}">{{ $customer->address ?: '-' }}</td>
                        <td class="whitespace-nowrap px-4 py-2">
                            @if ($customer->hasLocation())
                                <a href="{{ $customer->mapsUrl() }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-mono text-xs text-indigo-700 hover:underline">
                                    <i data-lucide="map-pin" class="h-3.5 w-3.5"></i>{{ App\Support\GeoLocation::format($customer->latitude) }}, {{ App\Support\GeoLocation::format($customer->longitude) }}
                                </a>
                            @else
                                <span class="text-slate-400">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right font-semibold">{{ $customer->tickets_count }}</td>
                        <td class="px-4 py-2 text-xs text-slate-500">
                            {{ $customer->tickets_max_created_at ? \Illuminate\Support\Carbon::parse($customer->tickets_max_created_at)->format('d/m/Y') : '-' }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            @can('create', App\Models\Ticket::class)
                                <a href="{{ route('tickets.create', ['customer' => $customer->code]) }}" class="btn-secondary text-xs">
                                    <i data-lucide="plus-circle" class="h-4 w-4"></i>Buat Tiket
                                </a>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-8 text-center text-slate-400">{{ $search !== '' ? 'Tidak ada pelanggan yang cocok' : 'Belum ada data pelanggan' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $customers->links() }}</div>
</x-layouts.app>
