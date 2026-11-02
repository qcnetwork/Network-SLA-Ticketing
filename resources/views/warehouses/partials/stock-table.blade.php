{{-- A warehouse's stock with a search box. --}}
<section class="card overflow-hidden">
    <div class="flex flex-col gap-3 border-b border-slate-100 p-5 sm:flex-row sm:items-center sm:justify-between">
        <h2 class="flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="package" class="h-4 w-4"></i>{{ $title }}</h2>
        <form method="GET" class="flex gap-2">
            <input type="search" name="q" value="{{ $search }}" class="input sm:w-60" placeholder="Cari barang, SN, atau MAC">
            <button class="btn-secondary"><i data-lucide="search" class="h-4 w-4"></i></button>
        </form>
    </div>
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase">
            <tr>
                <th class="px-4 py-3">Kode</th>
                <th class="px-4 py-3">Nama Barang</th>
                <th class="px-4 py-3 text-right">Stok</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($stocks as $stock)
                <tr>
                    <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ $stock->item->code }}</td>
                    <td class="px-4 py-2 text-slate-800">
                        {{ $stock->item->name }}
                        @if ($stock->item->tracks_serial && $units->has($stock->item_id))
                            <details class="mt-1 text-xs text-slate-500">
                                <summary class="cursor-pointer text-indigo-700">Lihat {{ $units[$stock->item_id]->count() }} SN</summary>
                                <ul class="mt-1 grid gap-x-4 sm:grid-cols-2">
                                    @foreach ($units[$stock->item_id] as $unit)
                                        <li class="font-mono">{{ $unit->serial_number }} <span class="text-slate-400">{{ $unit->formattedMac() }}</span></li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-right font-semibold whitespace-nowrap">{{ $stock->quantity }} <span class="text-xs font-normal text-slate-500">{{ $stock->item->unit }}</span></td>
                </tr>
            @empty
                <tr><td colspan="3" class="px-4 py-8 text-center text-slate-400">{{ $search !== '' ? 'Tidak ada barang yang cocok' : 'Belum ada stok' }}</td></tr>
            @endforelse
        </tbody>
    </table>
    @if ($stocks->hasPages())
        <div class="border-t border-slate-100 p-4">{{ $stocks->links() }}</div>
    @endif
</section>
