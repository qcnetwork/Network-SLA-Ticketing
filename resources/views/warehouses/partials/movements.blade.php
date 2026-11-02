{{-- Latest stock movements in or out of a warehouse. --}}
<section class="card p-5">
    <h2 class="mb-4 flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="history" class="h-4 w-4"></i>Riwayat Stok Terbaru</h2>
    <ul class="divide-y divide-slate-100 text-sm">
        @forelse ($movements as $movement)
            <li class="py-2">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="badge {{ $movement->type->badgeClasses() }}">{{ $movement->type->label() }}</span>
                    <span class="font-mono text-xs text-slate-700">{{ $movement->item->code }}</span>
                    <span class="font-medium text-slate-800">{{ $movement->item->name }}</span>
                    @if ($movement->serialUnit)
                        <span class="font-mono text-xs font-semibold">SN {{ $movement->serialUnit->serial_number }}</span>
                    @else
                        <span class="font-semibold">× {{ $movement->quantity }} {{ $movement->item->unit }}</span>
                    @endif
                </div>
                <div class="text-xs text-slate-500">
                    {{ $movement->fromWarehouse?->name ?? 'Pemasok' }} → {{ $movement->toWarehouse?->name ?? ($movement->ticket ? 'Pelanggan' : '-') }}
                    @if ($movement->ticket)
                        · <a href="{{ route('tickets.show', $movement->ticket) }}" class="font-mono text-indigo-700 hover:underline">{{ $movement->ticket->ticket_number }}</a>
                    @endif
                    · {{ $movement->user?->name ?? 'Sistem' }} · {{ $movement->created_at->format('d/m/Y H:i') }}
                    @if ($movement->notes)
                        · {{ $movement->notes }}
                    @endif
                </div>
            </li>
        @empty
            <li class="py-6 text-center text-slate-400">Belum ada pergerakan stok</li>
        @endforelse
    </ul>
</section>
