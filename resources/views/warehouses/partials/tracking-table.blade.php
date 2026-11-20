{{-- Used units ("kotor"), one row per SN: where it went, who took it and when, why, and what it replaced.
     Needs $units (a paginator of SerialUnit with item, ticket, usage, sale, convertedTo loaded). --}}
@php
    $tone = [
        'pelanggan' => 'border-emerald-300 bg-emerald-50 text-emerald-700',
        'mitra' => 'border-sky-300 bg-sky-50 text-sky-700',
        'retur' => 'border-rose-300 bg-rose-50 text-rose-700',
        'lainnya' => 'border-slate-300 bg-slate-50 text-slate-600',
    ];
@endphp
<div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase">
            <tr>
                <th class="px-4 py-3">SN / MAC</th>
                <th class="px-4 py-3">Barang</th>
                <th class="px-4 py-3">Dipasang di / Tujuan</th>
                <th class="px-4 py-3">Teknisi</th>
                <th class="px-4 py-3">Tgl Ambil</th>
                <th class="px-4 py-3">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($units as $unit)
                @php
                    $destination = $unit->destination();
                @endphp
                <tr class="align-top hover:bg-slate-50/60">
                    <td class="px-4 py-2.5 whitespace-nowrap">
                        <a href="{{ route('tracking.unit', $unit) }}" class="font-mono font-semibold text-indigo-700 hover:underline">{{ $unit->serial_number }}</a>
                        @if ($unit->mac)
                            <span class="block font-mono text-xs text-slate-500">{{ $unit->formattedMac() }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5">
                        <span class="block text-slate-800">{{ $unit->item->name }}</span>
                        <span class="text-xs text-slate-500"><span class="font-mono">{{ $unit->item->code }}</span>{{ $unit->item->brand ? ' · '.$unit->item->brand : '' }}</span>
                    </td>
                    <td class="max-w-xs px-4 py-2.5">
                        <span class="badge mb-0.5 {{ $tone[$destination] ?? $tone['lainnya'] }}">{{ App\Models\SerialUnit::DESTINATIONS[$destination] ?? '-' }}</span>
                        <span class="block text-slate-700">
                            @if ($unit->ticket)
                                <a href="{{ route('tickets.show', $unit->ticket) }}" class="hover:text-indigo-700 hover:underline">{{ $unit->placeName() }}</a>
                            @else
                                {{ $unit->placeName() }}
                            @endif
                        </span>
                        @if ($destination === 'retur' && $unit->installed_to)
                            <span class="block text-xs text-slate-400">sebelumnya di {{ $unit->installed_to }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5 text-slate-700">{{ $unit->holder_name ?? '-' }}</td>
                    <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">{{ $unit->taken_at?->format('d/m/Y') ?? '-' }}</td>
                    <td class="px-4 py-2.5 text-xs">
                        @if ($unit->install_status)
                            <span class="block font-medium text-slate-700">{{ $unit->install_status }}</span>
                        @endif
                        @if ($unit->replaced_device)
                            <span class="block text-slate-500">ganti: {{ $unit->replaced_device }}</span>
                        @endif
                        @if ($unit->notes)
                            <span class="block text-slate-400">{{ $unit->notes }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-10 text-center text-slate-400">
                        <i data-lucide="search-x" class="mx-auto mb-2 h-8 w-8 text-slate-300"></i>
                        Tidak ada unit terpakai yang cocok
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@if ($units->hasPages())
    <div class="border-t border-slate-100 p-4">{{ $units->links() }}</div>
@endif
