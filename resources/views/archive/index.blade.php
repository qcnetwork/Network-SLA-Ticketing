<x-layouts.app title="Arsip Tiket">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-900">Arsip Tiket</h1>
        <p class="text-sm text-slate-500">
            @if ($isTechnician)
                Tiket yang dibagikan Helpdesk/Supervisor kepada Anda. Akses berakhir otomatis sesuai batas waktunya.
            @else
                Semua tiket, termasuk yang sudah lama ditutup. Cari berdasarkan nama pelanggan, no. tiket, atau ID pelanggan.
            @endif
        </p>
    </div>

    <form method="GET" action="{{ route('archive.index') }}" class="card mb-6 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))_auto] lg:items-end">
        <div class="sm:col-span-2 lg:col-span-1">
            <label class="label" for="q">Cari</label>
            <input id="q" name="q" value="{{ $filters['q'] }}" maxlength="100" class="input" placeholder="Nama pelanggan / TCK-… / ID pelanggan" autofocus>
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <select id="status" name="status" class="input">
                <option value="ALL" @selected($filters['status'] === 'ALL')>Semua</option>
                <option value="OPEN" @selected($filters['status'] === 'OPEN')>Masih aktif</option>
                <option value="TUTUP" @selected($filters['status'] === 'TUTUP')>Sudah tutup</option>
            </select>
        </div>
        <div>
            <label class="label" for="from">Dibuat dari</label>
            <input id="from" name="from" type="date" value="{{ $filters['from'] }}" class="input">
        </div>
        <div>
            <label class="label" for="to">Sampai</label>
            <input id="to" name="to" type="date" value="{{ $filters['to'] }}" class="input">
            <x-field-error name="to" />
        </div>
        <div class="flex gap-2 sm:col-span-2 lg:col-span-1">
            <button class="btn-primary flex-1 lg:flex-none"><i data-lucide="search" class="h-4 w-4"></i>Cari</button>
            @if (array_filter([$filters['q'], $filters['from'], $filters['to']]) || $filters['status'] !== 'ALL')
                <a href="{{ route('archive.index') }}" class="btn-secondary">Reset</a>
            @endif
        </div>
    </form>

    <p class="mb-3 text-sm text-slate-500">{{ $tickets->total() }} tiket ditemukan</p>

    {{-- Phones: one card per ticket. --}}
    <div class="space-y-3 md:hidden">
        @forelse ($tickets as $ticket)
            <a href="{{ route('tickets.show', $ticket) }}" class="card block p-4 transition hover:shadow-md">
                <div class="mb-1 flex items-center justify-between gap-2">
                    <span class="font-mono text-xs font-semibold text-indigo-700">{{ $ticket->ticket_number }}</span>
                    <span class="badge {{ $ticket->status->badgeClasses() }}">{{ $ticket->status->label() }}</span>
                </div>
                <div class="font-semibold text-slate-800">{{ $ticket->customer_name }}</div>
                <div class="text-xs text-slate-500">{{ $ticket->category }}</div>
                <div class="mt-2 flex items-center justify-between gap-2 text-xs text-slate-500">
                    <span>Dibuat {{ $ticket->created_at->format('d/m/Y') }}</span>
                    @if ($isTechnician && ($share = $ticket->shares->first()))
                        <span class="text-amber-700">Akses s.d. {{ $share->expires_at->format('d/m H:i') }}</span>
                    @elseif ($ticket->closed_at)
                        <span>Tutup {{ $ticket->closed_at->format('d/m/Y') }}</span>
                    @endif
                </div>
            </a>
        @empty
            <p class="card p-8 text-center text-sm text-slate-400">{{ $isTechnician ? 'Belum ada tiket yang dibagikan kepada Anda.' : 'Tidak ada tiket yang cocok.' }}</p>
        @endforelse
    </div>

    {{-- Larger screens: a table. --}}
    <div class="card hidden overflow-x-auto md:block">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                <tr>
                    <th class="px-4 py-3">No. Tiket</th>
                    <th class="px-4 py-3">Pelanggan</th>
                    <th class="px-4 py-3">Kategori</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Teknisi</th>
                    <th class="px-4 py-3">Dibuat</th>
                    <th class="px-4 py-3">{{ $isTechnician ? 'Akses Sampai' : 'Ditutup' }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($tickets as $ticket)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3"><a href="{{ route('tickets.show', $ticket) }}" class="font-mono text-xs font-semibold text-indigo-700 hover:underline">{{ $ticket->ticket_number }}</a></td>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $ticket->customer_name }}</div>
                            @if ($ticket->customer_id)
                                <div class="text-xs text-slate-500">{{ $ticket->customer_id }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $ticket->category }}</td>
                        <td class="px-4 py-3"><span class="badge {{ $ticket->status->badgeClasses() }}">{{ $ticket->status->label() }}</span></td>
                        <td class="px-4 py-3 text-slate-600">{{ $ticket->technicianNames() ?: '-' }}</td>
                        <td class="px-4 py-3 text-xs whitespace-nowrap text-slate-500">{{ $ticket->created_at->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-3 text-xs whitespace-nowrap text-slate-500">
                            @if ($isTechnician)
                                {{ $ticket->shares->first()?->expires_at->format('d/m/Y H:i') }}
                            @else
                                {{ $ticket->closed_at?->format('d/m/Y H:i') ?? '-' }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-slate-400">{{ $isTechnician ? 'Belum ada tiket yang dibagikan kepada Anda.' : 'Tidak ada tiket yang cocok.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $tickets->links() }}</div>
</x-layouts.app>
