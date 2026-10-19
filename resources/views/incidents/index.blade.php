<x-layouts.app title="Gamas">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Gangguan Massal (Gamas)</h1>
            <p class="text-sm text-slate-500">Satu gamas mengelompokkan tiket semua pelanggan yang terdampak gangguan yang sama.</p>
        </div>
        @can('create', App\Models\Incident::class)
            <a href="{{ route('incidents.create') }}" class="btn-danger"><i data-lucide="radio-tower" class="h-4 w-4"></i>Buat Gamas</a>
        @endcan
    </div>

    <h2 class="mb-3 font-semibold text-slate-800">Sedang Berlangsung</h2>
    <div class="mb-8 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        @forelse ($openIncidents as $incident)
            <a href="{{ route('incidents.show', $incident) }}" class="card block border-t-4 border-t-red-600 p-4 transition hover:shadow-md">
                <div class="mb-1 flex items-center justify-between gap-2">
                    <span class="font-mono text-xs font-semibold text-indigo-700">{{ $incident->incident_number }}</span>
                    <span class="badge {{ $incident->status->badgeClasses() }}">{{ $incident->status->label() }}</span>
                </div>
                <div class="font-semibold text-slate-800">{{ $incident->title }}</div>
                <div class="flex items-center gap-1 text-xs text-slate-500"><i data-lucide="map-pin" class="h-3 w-3"></i>{{ $incident->area }}</div>
                <div class="mt-3 flex items-center justify-between text-xs text-slate-500">
                    <span class="flex items-center gap-1 font-semibold text-red-700"><i data-lucide="users" class="h-3.5 w-3.5"></i>{{ $incident->tickets_count }} pelanggan</span>
                    <span>{{ $incident->technicianNames() ?: 'Belum ada teknisi' }}</span>
                </div>
                @if ($incident->estimated_resolved_at)
                    <div class="mt-1 text-xs text-slate-400">Estimasi selesai {{ $incident->estimated_resolved_at->format('d/m/Y H:i') }}</div>
                @endif
            </a>
        @empty
            <p class="card p-6 text-center text-sm text-slate-400 md:col-span-2 lg:col-span-3">Tidak ada gamas yang sedang berlangsung.</p>
        @endforelse
    </div>

    <h2 class="mb-3 font-semibold text-slate-800">Riwayat Gamas</h2>
    <div class="card overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                <tr>
                    <th class="px-4 py-3">No. Gamas</th>
                    <th class="px-4 py-3">Gangguan</th>
                    <th class="px-4 py-3">Area</th>
                    <th class="px-4 py-3">Pelanggan</th>
                    <th class="px-4 py-3">Durasi</th>
                    <th class="px-4 py-3">Selesai</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($closedIncidents as $incident)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3"><a href="{{ route('incidents.show', $incident) }}" class="font-mono text-xs font-semibold text-indigo-700 hover:underline">{{ $incident->incident_number }}</a></td>
                        <td class="px-4 py-3 font-medium">{{ $incident->title }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $incident->area }}</td>
                        <td class="px-4 py-3">{{ $incident->tickets_count }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $incident->created_at->diffForHumans($incident->resolved_at, true) }}</td>
                        <td class="px-4 py-3 text-xs text-slate-500">{{ $incident->resolved_at?->format('d/m/Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-slate-400">Belum ada gamas yang selesai</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layouts.app>
