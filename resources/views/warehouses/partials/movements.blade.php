{{-- Latest stock movements in or out of a warehouse. --}}
<section class="card p-5">
    <h2 class="mb-4 flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="history" class="h-4 w-4"></i>Riwayat Stok Terbaru</h2>
    <ul class="space-y-3 text-sm">
        @forelse ($movements as $movement)
            <li class="flex gap-3">
                <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-full border {{ $movement->type->badgeClasses() }}" title="{{ $movement->type->label() }}">
                    <i data-lucide="{{ $movement->type->icon() }}" class="h-3.5 w-3.5"></i>
                </span>
                <div class="min-w-0 flex-1 border-b border-slate-100 pb-3">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                        <p class="font-medium text-slate-800">
                            {{ $movement->item->name }}
                            <span class="font-mono text-xs font-normal text-slate-500">{{ $movement->item->code }}</span>
                        </p>
                        @if ($movement->serialUnit)
                            <span class="font-mono text-xs font-semibold text-slate-700">SN {{ $movement->serialUnit->serial_number }}</span>
                        @else
                            <span class="font-semibold whitespace-nowrap text-slate-800">× {{ $movement->quantity }} {{ $movement->item->unit }}</span>
                        @endif
                    </div>
                    <p class="text-xs text-slate-500">
                        <span class="font-medium text-slate-600">{{ $movement->type->label() }}</span> ·
                        @if ($movement->type === App\Enums\StockMovementType::PENYESUAIAN)
                            {{ ($movement->toWarehouse ?? $movement->fromWarehouse)?->name }} · {{ $movement->toWarehouse ? '+' : '−' }}{{ $movement->quantity }}
                        @else
                            {{ $movement->fromWarehouse?->name ?? 'Pemasok' }} → {{ $movement->toWarehouse?->name ?? ($movement->ticket ? 'Pelanggan' : ($movement->sale?->buyer_name ?? '-')) }}
                        @endif
                        @if ($movement->takenBy)
                            · <span class="font-medium text-amber-700">diambil {{ $movement->takenBy->name }}</span>
                        @endif
                        @if ($movement->sale)
                            · <a href="{{ route('sales.show', $movement->sale) }}" class="font-mono text-indigo-700 hover:underline">{{ $movement->sale->sale_number }}</a>
                        @endif
                        @if ($movement->ticket)
                            · <a href="{{ route('tickets.show', $movement->ticket) }}" class="font-mono text-indigo-700 hover:underline">{{ $movement->ticket->ticket_number }}</a>
                        @endif
                    </p>
                    <p class="text-xs text-slate-400">
                        {{ $movement->user?->name ?? 'Sistem' }} · {{ $movement->created_at->format('d/m/Y H:i') }}
                        @if ($movement->notes)
                            · <span class="text-slate-500">{{ $movement->notes }}</span>
                        @endif
                    </p>
                </div>
            </li>
        @empty
            <li class="py-6 text-center text-slate-400">Belum ada pergerakan stok</li>
        @endforelse
    </ul>
</section>
