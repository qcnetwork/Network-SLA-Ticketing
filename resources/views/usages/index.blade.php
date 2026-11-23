@use('App\Enums\UsagePurpose')
<x-layouts.app title="Pemakaian Alat">
    @php
        $query = array_filter(['q' => $filters['q'], 'keperluan' => $filters['purpose'], 'stok' => $filters['warehouse'], 'dari' => $filters['from'], 'sampai' => $filters['to'],
            'status' => $filters['status'] === 'batal' ? 'batal' : null]);
    @endphp

    @include('warehouses.partials.nav', ['back' => ['Gudang', route('warehouses.index')]])
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-teal-600 text-white shadow-sm"><i data-lucide="clipboard-list" class="h-6 w-6"></i></span>
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Pemakaian Alat</h1>
                <p class="text-sm text-slate-500">Alat & material yang terpakai: tanggal, keperluan, lokasi, dan stok siapa. Material ACC dari tiket ikut tercatat di sini.</p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('usages.export', $query) }}" class="btn-secondary"><i data-lucide="file-spreadsheet" class="h-4 w-4 text-emerald-600"></i>Export Excel</a>
            @if ($canRecord)
                <a href="{{ route('usages.create') }}" class="btn-primary"><i data-lucide="plus" class="h-4 w-4"></i>Catat Pemakaian</a>
            @endif
        </div>
    </div>

    @include('warehouses.partials.summary', ['tiles' => [
        ['Pekerjaan Bulan Ini', $stats['jobs'], 'clipboard-list', 'emerald', 'pemakaian tercatat'],
        ['Unit Terpakai', $stats['units'], 'package-minus', 'indigo', 'bulan ini, semua satuan'],
        ['Unit ber-SN', $stats['devices'], 'scan-barcode', 'violet', 'terpasang bulan ini'],
    ]])

    {{-- This month by purpose; each chip filters the list. --}}
    @if ($stats['byPurpose']->isNotEmpty())
        <div class="mb-4 flex flex-wrap gap-2">
            @foreach (UsagePurpose::cases() as $purpose)
                @if ($count = $stats['byPurpose'][$purpose->value] ?? 0)
                    <a href="{{ route('usages.index', ['keperluan' => $purpose->value, 'dari' => now()->startOfMonth()->toDateString()]) }}"
                        class="badge {{ $purpose->badgeClasses() }} px-2.5 py-1 hover:shadow-sm">{{ $purpose->label() }} <b>{{ $count }}</b></a>
                @endif
            @endforeach
        </div>
    @endif

    <form method="GET" class="card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6">
        <div class="lg:col-span-2">
            <label class="label text-xs" for="q">Cari</label>
            <input id="q" name="q" value="{{ $filters['q'] }}" class="input" placeholder="Lokasi, barang, SN/MAC, teknisi, project, no. tiket">
        </div>
        <div>
            <label class="label text-xs" for="keperluan">Keperluan</label>
            <select id="keperluan" name="keperluan" class="input">
                <option value="">Semua</option>
                @foreach (UsagePurpose::cases() as $purpose)
                    <option value="{{ $purpose->value }}" @selected($filters['purpose'] === $purpose->value)>{{ $purpose->label() }}</option>
                @endforeach
            </select>
        </div>
        @if ($warehouses->isNotEmpty())
            <div>
                <label class="label text-xs" for="stok">Stok dari</label>
                <select id="stok" name="stok" class="input">
                    <option value="">Semua gudang</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected($filters['warehouse'] === (string) $warehouse->id)>{{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div>
            <label class="label text-xs" for="dari">Dari</label>
            <input id="dari" name="dari" type="date" value="{{ $filters['from'] }}" class="input">
        </div>
        <div>
            <label class="label text-xs" for="sampai">Sampai</label>
            <input id="sampai" name="sampai" type="date" value="{{ $filters['to'] }}" class="input">
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2 sm:col-span-2 lg:col-span-6">
            <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="status" value="batal" @checked($filters['status'] === 'batal') class="rounded border-slate-300">Tampilkan yang dibatalkan saja</label>
            <div class="flex gap-2">
                @if ($query)
                    <a href="{{ route('usages.index') }}" class="btn-secondary">Reset</a>
                @endif
                <button class="btn-primary"><i data-lucide="filter" class="h-4 w-4"></i>Terapkan</button>
            </div>
        </div>
    </form>

    <div class="card overflow-x-auto">
        <table class="w-full min-w-[860px] text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                <tr>
                    <th class="px-4 py-3">Tanggal</th>
                    <th class="px-4 py-3">Keperluan</th>
                    <th class="px-4 py-3">Lokasi</th>
                    <th class="px-4 py-3">Barang</th>
                    <th class="px-4 py-3">Teknisi / Stok</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($usages as $usage)
                    <tr @class(['align-top hover:bg-slate-50', 'opacity-60' => $usage->isCancelled()])>
                        <td class="px-4 py-3 whitespace-nowrap text-slate-600">{{ $usage->used_at->format('d/m/Y') }}</td>
                        <td class="px-4 py-3">
                            <span class="badge {{ $usage->purpose->badgeClasses() }}">{{ $usage->purpose->label() }}</span>
                            @if ($usage->project)
                                <span class="mt-1 block text-xs text-slate-500">{{ $usage->project }}</span>
                            @endif
                        </td>
                        <td class="max-w-56 px-4 py-3">
                            <span class="font-medium text-slate-800">{{ $usage->location }}</span>
                            @if ($usage->ticket)
                                <a href="{{ route('tickets.show', $usage->ticket) }}" class="block font-mono text-xs text-indigo-700 hover:underline">{{ $usage->ticket->ticket_number }}</a>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <ul class="space-y-0.5 text-xs text-slate-700">
                                @foreach ($usage->lines->take(4) as $line)
                                    <li>
                                        {{ $line->item_name }} <b>×{{ $line->quantity }}</b>
                                        @if ($line->serial_number)
                                            <span class="font-mono text-violet-700">{{ $line->serial_number }}</span>
                                        @endif
                                    </li>
                                @endforeach
                                @if ($usage->lines->count() > 4)
                                    <li class="text-slate-400">+{{ $usage->lines->count() - 4 }} barang lain</li>
                                @endif
                            </ul>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            <span class="block font-medium text-slate-700">{{ $usage->team ?: '-' }}</span>
                            <span class="text-slate-500">{{ $usage->holders() }}</span>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if ($usage->isCancelled())
                                <span class="badge border-slate-300 bg-slate-100 text-slate-600">Batal</span>
                            @elseif ($usage->isImported())
                                <span class="badge border-slate-200 bg-slate-50 text-slate-500" title="Dari Excel lama, tidak mengubah stok">Excel</span>
                            @endif
                            <a href="{{ route('usages.show', $usage) }}" class="ml-1 text-xs font-semibold text-indigo-700 hover:underline">Detail</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-slate-400">{{ $query ? 'Tidak ada pemakaian yang cocok dengan filter.' : 'Belum ada pemakaian tercatat.' }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $usages->links() }}</div>
</x-layouts.app>
