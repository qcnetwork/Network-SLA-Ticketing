{{-- One SN's whole story: what it is, where it is now, and every step from the sheet and from the app. --}}
@php
    $destination = $unit->destination();
    $item = $unit->item;
@endphp
<x-layouts.app :title="'SN '.$unit->serial_number">
    <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('tracking.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-700">
        <i data-lucide="arrow-left" class="h-4 w-4"></i>Kembali
    </a>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="card space-y-4 p-5">
            <div>
                <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Serial Number</p>
                <p class="font-mono text-xl font-bold break-all text-slate-900">{{ $unit->serial_number }}</p>
                @if ($unit->mac)
                    <p class="font-mono text-sm text-slate-500">MAC {{ $unit->formattedMac() }}</p>
                @endif
            </div>
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-xs text-slate-500">Barang</dt>
                    <dd class="font-medium text-slate-800">{{ $item->name }}</dd>
                    <dd class="text-xs text-slate-500">
                        <span class="font-mono">{{ $item->code }}</span>
                        {{ collect([$item->category?->name, $item->variant, $item->brand])->filter()->map(fn ($part) => ' · '.$part)->implode('') }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Posisi sekarang</dt>
                    <dd>
                        @if ($destination === null)
                            <span class="badge border-emerald-300 bg-emerald-50 text-emerald-700">Tersedia</span>
                            <span class="mt-1 block font-medium text-slate-800">{{ $unit->warehouse->name }}</span>
                        @else
                            <span class="badge border-amber-300 bg-amber-50 text-amber-700">Terpakai · {{ App\Models\SerialUnit::DESTINATIONS[$destination] }}</span>
                            <span class="mt-1 block font-medium text-slate-800">
                                @if ($unit->ticket)
                                    <a href="{{ route('tickets.show', $unit->ticket) }}" class="text-indigo-700 hover:underline">{{ $unit->placeName() }}</a>
                                @else
                                    {{ $unit->placeName() }}
                                @endif
                            </span>
                        @endif
                    </dd>
                </div>
                @if ($unit->holder_name)
                    <div>
                        <dt class="text-xs text-slate-500">Teknisi / pemegang terakhir</dt>
                        <dd class="text-slate-800">{{ $unit->holder_name }}{{ $unit->taken_at ? ' · diambil '.$unit->taken_at->format('d/m/Y') : '' }}</dd>
                    </div>
                @endif
                @if ($unit->install_status || $unit->replaced_device)
                    <div>
                        <dt class="text-xs text-slate-500">Pemasangan</dt>
                        <dd class="text-slate-800">{{ $unit->install_status ?? '-' }}</dd>
                        @if ($unit->replaced_device)
                            <dd class="text-xs text-slate-500">Menggantikan {{ $unit->replaced_device }}</dd>
                        @endif
                    </div>
                @endif
                @if ($unit->notes)
                    <div>
                        <dt class="text-xs text-slate-500">Keterangan</dt>
                        <dd class="text-slate-700">{{ $unit->notes }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        <section class="card p-5 lg:col-span-2">
            <h2 class="mb-4 flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="history" class="h-4 w-4"></i>Riwayat Unit</h2>
            @if ($sheet->isEmpty() && $moves->isEmpty())
                <p class="py-6 text-center text-slate-400">Belum ada riwayat</p>
            @endif
            <ol class="relative space-y-4 border-l border-slate-200 pl-6">
                @foreach ($sheet as $event)
                    <li class="relative">
                        <span class="absolute top-0.5 -left-[2.05rem] grid h-7 w-7 place-items-center rounded-full border border-slate-300 bg-slate-50 text-slate-500"><i data-lucide="{{ $event['icon'] }}" class="h-3.5 w-3.5"></i></span>
                        <p class="text-sm font-medium text-slate-800">{{ $event['title'] }}</p>
                        @if ($event['detail'])
                            <p class="text-sm text-slate-600">{{ $event['detail'] }}</p>
                        @endif
                        <p class="text-xs text-slate-400">{{ $event['date']?->format('d/m/Y') ?? 'Tanpa tanggal' }} · data TRACKING ALAT</p>
                    </li>
                @endforeach
                @foreach ($moves as $move)
                    <li class="relative">
                        <span class="absolute top-0.5 -left-[2.05rem] grid h-7 w-7 place-items-center rounded-full border {{ $move->type->badgeClasses() }}"><i data-lucide="{{ $move->type->icon() }}" class="h-3.5 w-3.5"></i></span>
                        <p class="text-sm font-medium text-slate-800">{{ $move->type->label() }}</p>
                        <p class="text-sm text-slate-600">
                            {{ $move->fromWarehouse?->name ?? 'Pemasok' }} → {{ $move->toWarehouse?->name ?? ($move->ticket ? $move->ticket->customer_name : ($move->sale?->buyer_name ?? ($move->usage?->location ?? '-'))) }}
                            @if ($move->ticket)
                                · <a href="{{ route('tickets.show', $move->ticket) }}" class="font-mono text-indigo-700 hover:underline">{{ $move->ticket->ticket_number }}</a>
                            @endif
                        </p>
                        @if ($move->notes)
                            <p class="text-xs text-slate-500">{{ $move->notes }}</p>
                        @endif
                        <p class="text-xs text-slate-400">{{ $move->created_at->format('d/m/Y H:i') }} · {{ $move->user?->name ?? 'Sistem' }}</p>
                    </li>
                @endforeach
            </ol>
        </section>
    </div>
</x-layouts.app>
