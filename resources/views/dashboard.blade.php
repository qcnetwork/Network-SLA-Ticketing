<x-layouts.app title="Dashboard">
    @php
        $query = fn (array $extra) => route('dashboard', array_filter(array_merge($filters, $extra)));
        $visibleStatuses = $boardStatuses;
    @endphp

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">{{ $isTechnician ? 'Tugas Perbaikan Saya' : 'Dashboard Tiket' }}</h1>
            <p class="text-sm text-slate-500">{{ now()->translatedFormat('l, d F Y') }}</p>
        </div>
        @can('create', App\Models\Ticket::class)
            <a href="{{ route('tickets.create') }}" class="btn-primary"><i data-lucide="plus" class="h-4 w-4"></i>Buat Tiket</a>
        @endcan
    </div>

    @if ($openIncidents->isNotEmpty())
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4">
            <div class="mb-2 flex items-center gap-2 text-sm font-semibold text-red-800"><i data-lucide="radio-tower" class="h-4 w-4"></i>{{ $openIncidents->count() }} gangguan massal sedang berlangsung</div>
            <div class="flex flex-wrap gap-2">
                @foreach ($openIncidents as $incident)
                    <a href="{{ route('incidents.show', $incident) }}" class="rounded-lg border border-red-200 bg-white px-3 py-2 text-sm transition hover:border-red-400">
                        <span class="font-mono text-xs font-semibold text-red-700">{{ $incident->incident_number }}</span>
                        <span class="font-medium text-slate-800">{{ $incident->area }}</span>
                        <span class="text-xs text-slate-500">· {{ $incident->tickets_count }} pelanggan</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Status summary --}}
    <div @class(['mb-6 grid grid-cols-2 gap-3', 'sm:grid-cols-4 lg:grid-cols-8' => ! $isTechnician])>
        <a href="{{ $query(['status' => null]) }}" class="card border-t-4 border-t-slate-700 p-4 transition hover:shadow-md">
            <div class="text-xs font-medium text-slate-500">Total</div>
            <div class="mt-1 text-2xl font-bold">{{ $total }}</div>
        </a>
        @foreach ($visibleStatuses as $status)
            <a href="{{ $query(['status' => $status->value]) }}"
               @class(['card border-t-4 p-4 transition hover:shadow-md', $status->accentClasses(), 'ring-2 ring-indigo-400' => ($filters['status'] ?? null) === $status->value])>
                <div class="flex items-center gap-1 text-xs font-medium text-slate-500"><i data-lucide="{{ $status->icon() }}" class="h-3 w-3"></i>{{ $status->label() }}</div>
                <div class="mt-1 text-2xl font-bold">{{ $counts[$status->value] ?? 0 }}</div>
            </a>
        @endforeach
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ route('dashboard') }}" class="card mb-6 flex flex-col gap-3 p-4 md:flex-row md:items-end">
        <input type="hidden" name="view" value="{{ $viewMode }}">
        <div class="flex-1">
            <label class="label" for="search">Cari</label>
            <input id="search" name="search" value="{{ $filters['search'] ?? '' }}" class="input" placeholder="No. tiket, nama, nomor WA, kendala, teknisi">
        </div>
        @unless ($isTechnician)
            <div>
                <label class="label" for="status">Status</label>
                <select id="status" name="status" class="input">
                    <option value="ALL">Semua Status</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>
        @endunless
        <div>
            <label class="label" for="priority">Prioritas</label>
            <select id="priority" name="priority" class="input">
                <option value="ALL">Semua Prioritas</option>
                @foreach ($priorities as $priority)
                    <option value="{{ $priority->value }}" @selected(($filters['priority'] ?? '') === $priority->value)>{{ $priority->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex gap-2">
            <button class="btn-primary"><i data-lucide="filter" class="h-4 w-4"></i>Terapkan</button>
            <a href="{{ route('dashboard', ['view' => $viewMode]) }}" class="btn-secondary">Reset</a>
        </div>
    </form>

    {{-- View toggle --}}
    <div class="mb-4 flex items-center justify-between">
        <div>
            <p class="text-sm text-slate-500">{{ $shown }} tiket ditampilkan</p>
            @if (empty($filters['search']))
                <p class="text-xs text-slate-400">Tiket tutup lebih dari {{ App\Models\Ticket::CLOSED_VISIBLE_DAYS }} hari disembunyikan. Gunakan pencarian untuk menemukannya.</p>
            @endif
        </div>
        <div class="inline-flex rounded-lg border border-slate-300 bg-white p-1">
            <a href="{{ $query(['view' => 'kanban']) }}" @class(['flex items-center gap-1 rounded-md px-3 py-1.5 text-sm font-medium', 'bg-indigo-600 text-white' => $viewMode === 'kanban', 'text-slate-600' => $viewMode !== 'kanban'])>
                <i data-lucide="columns-3" class="h-4 w-4"></i>Kanban
            </a>
            <a href="{{ $query(['view' => 'table']) }}" @class(['flex items-center gap-1 rounded-md px-3 py-1.5 text-sm font-medium', 'bg-indigo-600 text-white' => $viewMode === 'table', 'text-slate-600' => $viewMode !== 'table'])>
                <i data-lucide="table" class="h-4 w-4"></i>Tabel
            </a>
        </div>
    </div>

    @if ($viewMode === 'kanban')
        <div class="-mx-4 snap-x snap-mandatory scroll-px-4 overflow-x-auto px-4 pb-4 sm:mx-0 sm:snap-none sm:px-0">
            <div class="flex gap-4" style="min-width: max-content">
                @foreach ($visibleStatuses as $status)
                    @php $column = $columns[$status->value]; @endphp
                    <section class="flex w-[calc(100vw-3rem)] max-w-72 shrink-0 snap-start flex-col rounded-xl bg-slate-200/60 p-3">
                        <header class="mb-3 flex items-center justify-between">
                            <x-status-badge :status="$status" />
                            <span class="rounded-full bg-white px-2 text-xs font-bold text-slate-600">{{ $column->total() }}</span>
                        </header>
                        <div class="flex-1 space-y-3">
                            @forelse ($column as $ticket)
                                <a href="{{ route('tickets.show', $ticket) }}" class="block rounded-lg border border-slate-200 bg-white p-3 shadow-sm transition hover:border-indigo-300 hover:shadow-md">
                                    <div class="mb-1 flex items-center justify-between gap-2">
                                        <span class="font-mono text-xs font-semibold text-indigo-700">{{ $ticket->ticket_number }}</span>
                                        <x-priority-badge :priority="$ticket->priority" />
                                    </div>
                                    <div class="text-sm font-semibold text-slate-800">{{ $ticket->customer_name }}</div>
                                    <div class="text-xs text-slate-500">{{ $ticket->category }}</div>
                                    <p class="mt-2 line-clamp-2 text-xs text-slate-600">{{ $ticket->complaint }}</p>
                                    <div class="mt-2 flex items-center justify-between text-[11px] text-slate-400">
                                        <span>{{ $ticket->technicianNames() ?: $ticket->helpdesk?->name ?? 'Belum diambil' }}</span>
                                        <span title="{{ $ticket->updated_at->format('d/m/Y H:i') }}">{{ $ticket->updated_at->diffForHumans() }}</span>
                                    </div>
                                </a>
                            @empty
                                <p class="rounded-lg border border-dashed border-slate-300 py-6 text-center text-xs text-slate-400">Tidak ada tiket</p>
                            @endforelse
                        </div>
                        @if ($column->hasPages())
                            <nav class="mt-3 flex items-center justify-between gap-2 text-xs" aria-label="Halaman {{ $status->label() }}">
                                @if ($column->onFirstPage())
                                    <span class="rounded-md bg-white/50 px-2.5 py-1.5 text-slate-300">&lsaquo; Sebelumnya</span>
                                @else
                                    <a href="{{ $column->previousPageUrl() }}" class="rounded-md bg-white px-2.5 py-1.5 font-semibold text-slate-600 shadow-sm hover:text-indigo-700">&lsaquo; Sebelumnya</a>
                                @endif
                                <span class="text-slate-500">{{ $column->currentPage() }} / {{ $column->lastPage() }}</span>
                                @if ($column->hasMorePages())
                                    <a href="{{ $column->nextPageUrl() }}" class="rounded-md bg-white px-2.5 py-1.5 font-semibold text-slate-600 shadow-sm hover:text-indigo-700">Berikutnya &rsaquo;</a>
                                @else
                                    <span class="rounded-md bg-white/50 px-2.5 py-1.5 text-slate-300">Berikutnya &rsaquo;</span>
                                @endif
                            </nav>
                        @endif
                    </section>
                @endforeach
            </div>
        </div>
    @else
        {{-- Phones get stacked cards; the table needs more width than a phone has. --}}
        <div class="space-y-3 md:hidden">
            @forelse ($tickets as $ticket)
                <a href="{{ route('tickets.show', $ticket) }}" class="card block p-4 transition hover:border-indigo-300">
                    <div class="mb-1 flex items-center justify-between gap-2">
                        <span class="font-mono text-xs font-semibold text-indigo-700">{{ $ticket->ticket_number }}</span>
                        <x-priority-badge :priority="$ticket->priority" />
                    </div>
                    <div class="font-semibold text-slate-800">{{ $ticket->customer_name }}</div>
                    <div class="text-xs text-slate-500">{{ $ticket->customer_phone }} · {{ $ticket->category }}</div>
                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                        <x-status-badge :status="$ticket->status" />
                        <span class="text-xs text-slate-500">{{ $ticket->updated_at->diffForHumans() }}</span>
                    </div>
                    <div class="mt-1 text-xs text-slate-500">Petugas: {{ $ticket->technicianNames() ?: $ticket->helpdesk?->name ?? '-' }}</div>
                </a>
            @empty
                <div class="card px-4 py-10 text-center text-sm text-slate-400">Tidak ada tiket yang cocok dengan filter</div>
            @endforelse
        </div>
        <div class="card hidden overflow-x-auto md:block">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th class="px-4 py-3">No. Tiket</th>
                        <th class="px-4 py-3">Pelanggan</th>
                        <th class="px-4 py-3">Kategori</th>
                        <th class="px-4 py-3">Prioritas</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Petugas</th>
                        <th class="px-4 py-3">Diperbarui</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($tickets as $ticket)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3"><a href="{{ route('tickets.show', $ticket) }}" class="font-mono text-xs font-semibold text-indigo-700 hover:underline">{{ $ticket->ticket_number }}</a></td>
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ $ticket->customer_name }}</div>
                                <div class="text-xs text-slate-500">{{ $ticket->customer_phone }}</div>
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $ticket->category }}</td>
                            <td class="px-4 py-3"><x-priority-badge :priority="$ticket->priority" /></td>
                            <td class="px-4 py-3"><x-status-badge :status="$ticket->status" /></td>
                            <td class="px-4 py-3 text-slate-600">{{ $ticket->technicianNames() ?: $ticket->helpdesk?->name ?? '-' }}</td>
                            <td class="px-4 py-3 text-xs text-slate-500">{{ $ticket->updated_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-slate-400">Tidak ada tiket yang cocok dengan filter</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $tickets->links() }}</div>
    @endif
</x-layouts.app>
