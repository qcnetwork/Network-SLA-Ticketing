@use('App\Enums\TicketStatus')
<x-layouts.app title="Pasang Baru (PSB)">
    @php
        $openTotal = collect($openStatuses)->sum(fn (TicketStatus $status) => $counts[$status->value] ?? 0);
        $tabs = [
            'aktif' => ['label' => 'Berjalan', 'icon' => 'list-todo', 'count' => $openTotal],
            'selesai' => ['label' => 'Selesai', 'icon' => 'check-circle-2', 'count' => $counts[TicketStatus::PSB_SELESAI->value] ?? 0],
            'batal' => ['label' => 'Batal', 'icon' => 'x-circle', 'count' => $counts[TicketStatus::PSB_BATAL->value] ?? 0],
        ];
        $isTechnician = auth()->user()->role === App\Enums\Role::TEKNISI;
    @endphp

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold text-slate-900"><i data-lucide="house-plus" class="h-6 w-6 text-indigo-600"></i>Pasang Baru (PSB)</h1>
            <p class="text-sm text-slate-500">
                {{ $isTechnician ? 'Survey dan pemasangan yang ditugaskan kepada Anda.' : 'Waiting list pemasangan baru, terpisah dari tiket gangguan.' }}
            </p>
        </div>
        @can('createInstallation', App\Models\Ticket::class)
            <a href="{{ route('installations.create') }}" class="btn-primary"><i data-lucide="plus" class="h-4 w-4"></i>PSB Baru</a>
        @endcan
    </div>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        @unless ($isTechnician)
            <nav class="flex gap-1 rounded-lg border border-slate-200 bg-white p-1">
                @foreach ($tabs as $key => $item)
                    <a href="{{ route('installations.index', array_filter(['tab' => $key, 'q' => $search])) }}"
                        @class(['flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium', 'bg-indigo-600 text-white' => $tab === $key, 'text-slate-600 hover:bg-slate-50' => $tab !== $key])>
                        <i data-lucide="{{ $item['icon'] }}" class="h-4 w-4"></i>{{ $item['label'] }}
                        <span @class(['rounded-full px-1.5 text-xs', 'bg-white/20' => $tab === $key, 'bg-slate-100 text-slate-500' => $tab !== $key])>{{ $item['count'] }}</span>
                    </a>
                @endforeach
            </nav>
        @endunless
        <form method="GET" class="flex gap-2 sm:w-80">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <input name="q" value="{{ $search }}" class="input" placeholder="Cari no. PSB, nama, WA, alamat, sales">
            <button class="btn-secondary px-3" aria-label="Cari"><i data-lucide="search" class="h-4 w-4"></i></button>
        </form>
    </div>

    @if ($tab === 'aktif')
        <div class="-mx-4 overflow-x-auto px-4 pb-2 sm:mx-0 sm:px-0">
            <div class="grid min-w-max auto-cols-[17rem] grid-flow-col gap-4">
                @foreach ($openStatuses as $status)
                    @php($items = $columns[$status->value])
                    @continue($isTechnician && ! $status->isFieldWork())
                    <section class="flex flex-col rounded-xl bg-slate-200/60 p-2">
                        <header class="card mb-2 border-t-4 px-3 py-2 {{ $status->accentClasses() }}">
                            <div class="flex items-center justify-between gap-2">
                                <h2 class="flex items-center gap-1.5 text-sm font-semibold text-slate-800"><i data-lucide="{{ $status->icon() }}" class="h-4 w-4 text-slate-500"></i>{{ $status->label() }}</h2>
                                <span class="rounded-full bg-slate-100 px-2 text-xs font-semibold text-slate-600">{{ $counts[$status->value] ?? 0 }}</span>
                            </div>
                        </header>
                        <div class="space-y-2">
                            @forelse ($items as $ticket)
                                <a href="{{ route('tickets.show', $ticket) }}" class="card block p-3 transition hover:shadow-md">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-mono text-xs font-semibold text-indigo-700">{{ $ticket->ticket_number }}</span>
                                        <span class="text-[11px] text-slate-400" title="Masuk tahap ini">{{ $ticket->updated_at->diffForHumans(short: true) }}</span>
                                    </div>
                                    <div class="mt-1 truncate font-semibold text-slate-800">{{ $ticket->customer_name }}</div>
                                    @if ($ticket->package)
                                        <div class="mt-1 inline-flex items-center gap-1 rounded bg-indigo-50 px-1.5 py-0.5 text-[11px] font-medium text-indigo-700"><i data-lucide="package" class="h-3 w-3"></i>{{ $ticket->package }}</div>
                                    @endif
                                    <div class="mt-1 line-clamp-2 text-xs break-all text-slate-500">{{ $ticket->customer_address }}</div>
                                    @if ($ticket->technicians->isNotEmpty())
                                        <div class="mt-2 flex items-center gap-1 text-xs text-orange-700"><i data-lucide="wrench" class="h-3.5 w-3.5"></i><span class="truncate">{{ $ticket->technicianNames() }}</span></div>
                                    @endif
                                </a>
                            @empty
                                <p class="px-2 py-6 text-center text-xs text-slate-400">Kosong</p>
                            @endforelse
                            @if (($counts[$status->value] ?? 0) > $items->count())
                                <p class="px-2 text-center text-xs text-slate-500">+{{ $counts[$status->value] - $items->count() }} lainnya. Gunakan pencarian.</p>
                            @endif
                        </div>
                    </section>
                @endforeach
            </div>
        </div>
    @else
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs text-slate-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">No. PSB</th>
                            <th class="px-4 py-3">Pelanggan</th>
                            <th class="px-4 py-3">Paket</th>
                            <th class="px-4 py-3">{{ $tab === 'selesai' ? 'ID Pelanggan' : 'Alasan' }}</th>
                            <th class="px-4 py-3">{{ $tab === 'selesai' ? 'Aktif' : 'Dibatalkan' }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($closed as $ticket)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3"><a href="{{ route('tickets.show', $ticket) }}" class="font-mono font-semibold text-indigo-700 hover:underline">{{ $ticket->ticket_number }}</a></td>
                                <td class="px-4 py-3"><div class="font-medium">{{ $ticket->customer_name }}</div><div class="text-xs text-slate-500">{{ $ticket->customer_phone }}</div></td>
                                <td class="px-4 py-3">{{ $ticket->package ?? '-' }}</td>
                                <td class="max-w-xs px-4 py-3 text-slate-600">
                                    @if ($tab === 'selesai')
                                        <span class="font-mono">{{ $ticket->customer_id ?? '-' }}</span>
                                    @else
                                        <span class="line-clamp-2">{{ $ticket->confirmation_notes ?? '-' }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-slate-500">{{ $ticket->closed_at?->format('d/m/Y H:i') ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-10 text-center text-slate-400">Belum ada PSB {{ $tab === 'selesai' ? 'yang selesai' : 'yang dibatalkan' }}.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-4">{{ $closed->links() }}</div>
    @endif
</x-layouts.app>
