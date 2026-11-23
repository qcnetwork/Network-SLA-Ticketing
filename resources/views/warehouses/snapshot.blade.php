{{-- A technician's whole stock on one plain page for the closing screenshot: no navbar, every SN listed, nothing
     folded away. The toolbar above the sheet is left out of the picture (and of printing). --}}
@php
    $holder = $warehouse->user?->name ?? $warehouse->name;
    $allStocks = $stocks->flatten();
    $minus = $allStocks->where('quantity', '<', 0);
    $snCount = $units->flatten()->count();
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stok {{ $holder }} · {{ now()->format('d/m/Y H:i') }}</title>
    <link rel="icon" href="/icons/logo-mark.png" type="image/png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-200 font-sans text-slate-900 antialiased">
    <div class="mx-auto max-w-5xl px-3 py-4 sm:px-6">
        <div x-data class="mb-3 flex flex-wrap items-center gap-2 text-sm print:hidden">
            <a href="{{ route('warehouses.show', $warehouse) }}" class="btn-secondary"><i data-lucide="arrow-left" class="h-4 w-4"></i>Kembali</a>
            @if ($others->isNotEmpty())
                <select class="input w-auto" @change="window.location = $event.target.value" aria-label="Pilih teknisi">
                    @foreach ($others as $other)
                        <option value="{{ route('warehouses.snapshot', [$other] + ($withMac ? ['mac' => 1] : [])) }}" @selected($other->is($warehouse))>{{ $other->user?->name ?? $other->name }}</option>
                    @endforeach
                </select>
            @endif
            <a href="{{ route('warehouses.snapshot', $withMac ? [$warehouse] : [$warehouse, 'mac' => 1]) }}" class="btn-secondary">{{ $withMac ? 'Sembunyikan MAC' : 'Tampilkan MAC' }}</a>
            <button type="button" @click="window.print()" class="btn-secondary"><i data-lucide="printer" class="h-4 w-4"></i>Cetak / PDF</button>
            <span class="text-xs text-slate-500">Screenshot area putih di bawah.</span>
        </div>

        <main class="rounded-xl bg-white p-5 shadow-sm sm:p-6 print:rounded-none print:p-0 print:shadow-none">
            <header class="mb-4 flex flex-wrap items-end justify-between gap-3 border-b-2 border-slate-800 pb-3">
                <div>
                    <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase">Stok Teknisi · QCNet</p>
                    <h1 class="text-2xl leading-tight font-bold">{{ $holder }}</h1>
                    <p class="text-sm text-slate-600">Per {{ now()->translatedFormat('l, d F Y · H:i') }} WIB</p>
                </div>
                <div class="flex gap-4 text-center">
                    <div><p class="text-xl font-bold">{{ $allStocks->count() }}</p><p class="text-[11px] text-slate-500">jenis barang</p></div>
                    <div><p class="text-xl font-bold">{{ $snCount }}</p><p class="text-[11px] text-slate-500">unit ber-SN</p></div>
                    @if ($minus->isNotEmpty())
                        <div><p class="text-xl font-bold text-rose-600">{{ $minus->count() }}</p><p class="text-[11px] text-rose-600">stok minus</p></div>
                    @endif
                </div>
            </header>

            @forelse ($stocks as $category => $rows)
                <section class="mb-4 break-inside-avoid">
                    <h2 class="mb-1 bg-slate-800 px-2 py-1 text-xs font-bold tracking-wide text-white uppercase">{{ $category }}</h2>
                    <div class="divide-y divide-slate-200 border-x border-b border-slate-200">
                        @foreach ($rows as $stock)
                            @php
                                $item = $stock->item;
                                $sns = $units[$item->id] ?? collect();
                                $loose = $item->tracks_serial ? $stock->quantity - $sns->count() : 0;
                            @endphp
                            <div class="px-2 py-1.5">
                                <div class="flex items-baseline gap-3">
                                    <span class="w-20 shrink-0 font-mono text-xs text-slate-500">{{ $item->code }}</span>
                                    <span class="min-w-0 flex-1 text-sm font-medium">
                                        {{ $item->name }}
                                        @if ($item->variant || $item->brand)
                                            <span class="text-xs font-normal text-slate-500">· {{ collect([$item->variant, $item->brand])->filter()->implode(' · ') }}</span>
                                        @endif
                                    </span>
                                    <span class="shrink-0 text-right text-base font-bold {{ $stock->quantity < 0 ? 'text-rose-600' : '' }}">
                                        {{ number_format($stock->quantity, 0, ',', '.') }} <span class="text-xs font-normal text-slate-500">{{ $item->unit }}</span>
                                    </span>
                                </div>
                                @if ($sns->isNotEmpty())
                                    <ol class="mt-1 ml-23 grid grid-cols-2 gap-x-4 font-mono text-[11px] leading-5 text-slate-700 sm:grid-cols-3 {{ $withMac ? 'lg:grid-cols-2' : 'lg:grid-cols-4' }}">
                                        @foreach ($sns as $unit)
                                            <li><span class="inline-block w-6 text-right text-slate-400">{{ $loop->iteration }}.</span> {{ $unit->serial_number }}@if ($withMac && $unit->mac)<span class="text-slate-400"> · {{ implode(':', str_split($unit->mac, 2)) }}</span>@endif</li>
                                        @endforeach
                                    </ol>
                                @endif
                                @if ($loose > 0)
                                    <p class="mt-0.5 ml-23 text-[11px] font-medium text-amber-700">+ {{ $loose }} {{ $item->unit }} stok lama tanpa SN</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @empty
                <p class="py-10 text-center text-slate-400">Tidak memegang stok</p>
            @endforelse

            @if ($pending->isNotEmpty())
                <section class="mb-4 break-inside-avoid">
                    <h2 class="mb-1 bg-amber-500 px-2 py-1 text-xs font-bold tracking-wide text-white uppercase">Sudah dipakai, menunggu ACC Gudang (stok di atas belum berkurang)</h2>
                    <ul class="divide-y divide-slate-200 border-x border-b border-slate-200 text-sm">
                        @foreach ($pending as $line)
                            <li class="flex items-baseline gap-3 px-2 py-1">
                                <span class="w-20 shrink-0 font-mono text-xs text-slate-500">{{ $line->item->code }}</span>
                                <span class="min-w-0 flex-1">{{ $line->item->name }}{{ $line->serialUnit ? ' · SN '.$line->serialUnit->serial_number : '' }} <span class="text-xs text-slate-500">· {{ $line->request->ticket?->ticket_number }}</span></span>
                                <span class="shrink-0 font-semibold">{{ $line->quantity }} <span class="text-xs font-normal text-slate-500">{{ $line->item->unit }}</span></span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($removedDevices->isNotEmpty())
                <section class="mb-4 break-inside-avoid">
                    <h2 class="mb-1 bg-rose-600 px-2 py-1 text-xs font-bold tracking-wide text-white uppercase">Alat cabutan belum diserahkan ke Gudang</h2>
                    <ul class="divide-y divide-slate-200 border-x border-b border-slate-200 text-sm">
                        @foreach ($removedDevices as $device)
                            <li class="flex items-baseline gap-3 px-2 py-1">
                                <span class="min-w-0 flex-1">{{ $device->name }}{{ $device->quantity > 1 ? " × {$device->quantity}" : '' }}{{ $device->serial_number ? ' · SN '.$device->serial_number : '' }} <span class="text-xs text-slate-500">· {{ collect([$device->customer_name, $device->ticket?->ticket_number])->filter()->implode(' · ') }}</span></span>
                                <span class="shrink-0 text-xs {{ $device->isOverdue() ? 'font-bold text-rose-600' : 'text-slate-500' }}">{{ $device->isOverdue() ? 'Terlambat' : 'Batas '.$device->due_at->format('d/m H:i') }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <footer class="mt-2 flex justify-between border-t border-slate-200 pt-2 text-[11px] text-slate-400">
                <span>{{ $warehouse->name }}</span>
                <span>Dibuat {{ now()->format('d/m/Y H:i') }} oleh {{ auth()->user()->name }}</span>
            </footer>
        </main>
    </div>
</body>
</html>
