@php
    $isDaily = $report['period'] === 'daily';
    $title = $isDaily ? 'Laporan Harian' : 'Laporan Bulanan';
@endphp
<x-layouts.app :title="$title">
    @php
        $r = $report;
        $exportQuery = $isDaily ? ['period' => 'daily', 'date' => $r['date']] : ['year' => $r['year'], 'month' => $r['month']];
        $kpis = [
            ['Total Tiket', $r['total'], 'inbox', 'text-slate-800'],
            ['Selesai (Tutup)', $r['closed'], 'check-circle-2', 'text-emerald-600'],
            ['Masih Berjalan', $r['in_progress'], 'loader', 'text-amber-600'],
            ['Pending Pelanggan', $r['pending'], 'message-square', 'text-purple-600'],
            ['Resolution Rate', $r['resolution_rate'].'%', 'target', 'text-indigo-600'],
            ['Selesai Remote', $r['remote_resolved'], 'zap', 'text-sky-600'],
            ['Ditangani Teknisi', $r['technician_assigned'], 'wrench', 'text-orange-600'],
            ['Sempat Cek Ulang', $r['rechecked'], 'refresh-cw', 'text-rose-600'],
        ];
        $maxTech = max(1, $r['technicians']->max('count') ?? 1);
    @endphp

    <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">{{ $title }}</h1>
            <p class="text-sm text-slate-500">Periode {{ $r['period_label'] }}, berdasarkan tanggal tiket masuk.</p>
            <div class="mt-3 inline-flex rounded-lg border border-slate-200 bg-white p-1 text-sm font-semibold">
                <a href="{{ route('reports.index', ['period' => 'daily']) }}" @class(['rounded-md px-3 py-1', 'bg-indigo-600 text-white' => $isDaily, 'text-slate-600 hover:text-indigo-700' => ! $isDaily])>Harian</a>
                <a href="{{ route('reports.index') }}" @class(['rounded-md px-3 py-1', 'bg-indigo-600 text-white' => ! $isDaily, 'text-slate-600 hover:text-indigo-700' => $isDaily])>Bulanan</a>
            </div>
        </div>
        <form method="GET" action="{{ route('reports.index') }}" class="flex flex-wrap items-end gap-2">
            @if ($isDaily)
                <input type="hidden" name="period" value="daily">
                <div class="basis-full sm:basis-auto">
                    <label class="label" for="date">Tanggal</label>
                    <input type="date" id="date" name="date" value="{{ $r['date'] }}" max="{{ now()->toDateString() }}" class="input">
                </div>
            @else
                <div class="grow sm:grow-0">
                    <label class="label" for="month">Bulan</label>
                    <select id="month" name="month" class="input">
                        @foreach ($months as $number => $name)
                            <option value="{{ $number }}" @selected($r['month'] === $number)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grow sm:grow-0">
                    <label class="label" for="year">Tahun</label>
                    <select id="year" name="year" class="input">
                        @foreach (range(now()->year - 3, now()->year + 1) as $year)
                            <option value="{{ $year }}" @selected($r['year'] === $year)>{{ $year }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <button class="btn-secondary grow sm:grow-0">Tampilkan</button>
            <a href="{{ route('reports.export', $exportQuery) }}" class="btn-success grow sm:grow-0">
                <i data-lucide="file-spreadsheet" class="h-4 w-4"></i>Ekspor Excel
            </a>
        </form>
    </div>

    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
        @foreach ($kpis as [$label, $value, $icon, $color])
            <div class="card p-4">
                <div class="flex items-center gap-1 text-xs font-medium text-slate-500"><i data-lucide="{{ $icon }}" class="h-3.5 w-3.5"></i>{{ $label }}</div>
                <div class="mt-1 text-2xl font-bold {{ $color }}">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    @php
        // Same hues as the matching ticket statuses, so CS reads cyan like "Proses CS", and so on.
        $closureColors = [
            'closed_cs' => 'bg-cyan-500',
            'closed_helpdesk' => 'bg-blue-500',
            'closed_teknisi' => 'bg-orange-500',
            'closed_gamas' => 'bg-red-500',
            'closed_other' => 'bg-emerald-500',
            'pending' => 'bg-purple-500',
            'in_progress' => 'bg-slate-300',
        ];
    @endphp
    <section class="card mb-6 p-5">
        <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-semibold">Penyelesaian Tiket</h2>
            <p class="text-xs text-slate-500">Siapa yang menyelesaikan tiket, dari total {{ $r['total'] }} tiket periode ini. Tiket yang tutup otomatis dihitung untuk yang menyelesaikannya.</p>
        </div>
        @if ($r['total'] > 0)
            <div class="mb-5 flex h-3 overflow-hidden rounded-full bg-slate-100" role="img" aria-label="Komposisi penyelesaian tiket">
                @foreach ($r['closure'] as $row)
                    @if ($row['count'] > 0)
                        <div class="{{ $closureColors[$row['key']] }}" style="width: {{ $row['count'] / $r['total'] * 100 }}%" title="{{ $row['label'] }}: {{ $row['count'] }} ({{ $row['percentage'] }}%)"></div>
                    @endif
                @endforeach
            </div>
            <div class="grid gap-x-8 gap-y-3 sm:grid-cols-2">
                @foreach ($r['closure'] as $row)
                    <div class="flex items-center gap-3 text-sm">
                        <span class="h-3 w-3 shrink-0 rounded-sm {{ $closureColors[$row['key']] }}"></span>
                        <span class="flex-1 text-slate-700">{{ $row['label'] }}</span>
                        <span class="font-semibold text-slate-900">{{ $row['percentage'] }}%</span>
                        <span class="w-14 text-right text-xs text-slate-500">{{ $row['count'] }} tiket</span>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-sm text-slate-400">Belum ada tiket pada periode ini.</p>
        @endif
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="card p-5">
            <h2 class="mb-4 font-semibold">Kategori Gangguan</h2>
            @forelse ($r['categories'] as $category)
                <div class="mb-3">
                    <div class="mb-1 flex justify-between text-sm"><span>{{ $category['category'] }}</span><span class="font-semibold">{{ $category['count'] }} ({{ $category['percentage'] }}%)</span></div>
                    <div class="h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-indigo-500" style="width: {{ $category['percentage'] }}%"></div></div>
                </div>
            @empty
                <p class="text-sm text-slate-400">Belum ada tiket pada periode ini.</p>
            @endforelse
        </section>

        <section class="card p-5">
            <h2 class="mb-4 font-semibold">Beban Kerja Teknisi</h2>
            @forelse ($r['technicians'] as $technician)
                <div class="mb-3">
                    <div class="mb-1 flex justify-between text-sm"><span>{{ $technician['name'] }}</span><span class="font-semibold">{{ $technician['count'] }} tiket</span></div>
                    <div class="h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-orange-500" style="width: {{ round($technician['count'] / $maxTech * 100) }}%"></div></div>
                </div>
            @empty
                <p class="text-sm text-slate-400">Belum ada tiket yang ditangani teknisi.</p>
            @endforelse

            <h3 class="mt-6 mb-2 text-sm font-semibold">Prioritas</h3>
            <div class="flex flex-wrap gap-2">
                @foreach ($r['priorities'] as $priority => $count)
                    <x-priority-badge :priority="App\Enums\TicketPriority::from($priority)" /> <span class="mr-3 text-sm font-semibold">{{ $count }}</span>
                @endforeach
            </div>
        </section>
    </div>

    <section class="card mt-6 overflow-x-auto">
        <h2 class="px-5 pt-5 font-semibold">Daftar Tiket</h2>
        {{-- Phones get a stacked list; the table needs more width than a phone has. --}}
        <ul class="mt-3 divide-y divide-slate-100 border-t border-slate-200 md:hidden">
            @forelse ($r['tickets'] as $ticket)
                <li>
                    <a href="{{ route('tickets.show', $ticket) }}" class="block px-5 py-3 hover:bg-slate-50">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-mono text-xs font-semibold text-indigo-700">{{ $ticket->ticket_number }}</span>
                            <x-status-badge :status="$ticket->status" />
                        </div>
                        <div class="mt-1 text-sm font-medium text-slate-800">{{ $ticket->customer_name }}</div>
                        <div class="text-xs text-slate-500">{{ $ticket->created_at->format('d/m/Y H:i') }} · {{ $ticket->category }}</div>
                        <div class="text-xs text-slate-500">Teknisi: {{ $ticket->technicianNames() ?: '-' }}</div>
                    </a>
                </li>
            @empty
                <li class="px-5 py-8 text-center text-sm text-slate-400">Tidak ada tiket</li>
            @endforelse
        </ul>
        <table class="mt-3 hidden min-w-full divide-y divide-slate-200 text-sm md:table">
            <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase">
                <tr>
                    <th class="px-4 py-3">No. Tiket</th>
                    <th class="px-4 py-3">Masuk</th>
                    <th class="px-4 py-3">Pelanggan</th>
                    <th class="px-4 py-3">Kategori</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Teknisi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($r['tickets'] as $ticket)
                    <tr>
                        <td class="px-4 py-2"><a href="{{ route('tickets.show', $ticket) }}" class="font-mono text-xs text-indigo-700 hover:underline">{{ $ticket->ticket_number }}</a></td>
                        <td class="px-4 py-2 text-xs text-slate-500">{{ $ticket->created_at->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-2">{{ $ticket->customer_name }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ $ticket->category }}</td>
                        <td class="px-4 py-2"><x-status-badge :status="$ticket->status" /></td>
                        <td class="px-4 py-2 text-slate-600">{{ $ticket->technicianNames() ?: '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">Tidak ada tiket</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</x-layouts.app>
