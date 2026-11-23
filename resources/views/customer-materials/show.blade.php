{{-- One customer's material (Gudang Pelanggan): installed now with the nodes each piece travelled, removed, and the log. --}}
<x-layouts.app :title="'Material '.($customer?->name ?? $code)">
    @include('warehouses.partials.nav', ['back' => ['Gudang Pelanggan', route('customer-materials.index')]])

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex items-center gap-3">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-emerald-600 text-white shadow-sm"><i data-lucide="house" class="h-6 w-6"></i></span>
            <div>
                <h1 class="text-2xl font-bold text-slate-900">{{ $customer?->name ?? 'Pelanggan '.$code }}</h1>
                <p class="text-sm text-slate-500">
                    <span class="font-mono font-semibold text-slate-700">{{ $code }}</span>
                    @if ($customer?->address)
                        · {{ $customer->address }}
                    @endif
                    @unless ($customer)
                        · <span class="text-amber-700">ID ini belum ada di data pelanggan</span>
                    @endunless
                </p>
            </div>
        </div>
        @if ($customer?->mapsUrl())
            <a href="{{ $customer->mapsUrl() }}" target="_blank" rel="noopener" class="btn-secondary shrink-0"><i data-lucide="map-pin" class="h-4 w-4"></i>Lokasi</a>
        @endif
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <section class="mb-6">
        <h2 class="mb-3 flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="plug" class="h-4 w-4 text-emerald-600"></i>Terpasang Sekarang <span class="text-sm font-normal text-slate-500">({{ $installed->count() }})</span></h2>
        <div class="space-y-3">
            @forelse ($installed as $material)
                <article class="card p-4" x-data="{ removing: @js((int) old('material_id') === $material->id) }">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="font-semibold text-slate-900">{{ $material->item_name }}</p>
                            <p class="text-sm text-slate-600">
                                @if ($material->serial_number)
                                    @if ($material->serialUnit)
                                        <a href="{{ route('tracking.unit', $material->serialUnit) }}" class="font-mono font-semibold text-indigo-700 hover:underline">SN {{ $material->serial_number }}</a>
                                    @else
                                        <span class="font-mono font-semibold">SN {{ $material->serial_number }}</span>
                                    @endif
                                    @if ($material->mac)
                                        <span class="font-mono text-xs text-slate-500">· {{ $material->formattedMac() }}</span>
                                    @endif
                                @else
                                    <b>{{ number_format($material->quantity, 0, ',', '.') }}</b> {{ $material->unit }}
                                @endif
                            </p>
                            <p class="text-xs text-slate-500">
                                Dipasang {{ $material->installed_at->format('d/m/Y') }}
                                @if ($material->technician)
                                    oleh {{ $material->technician }}
                                @endif
                                @if ($material->ticket)
                                    · <a href="{{ route('tickets.show', $material->ticket) }}" class="font-mono text-indigo-700 hover:underline">{{ $material->ticket->ticket_number }}</a>
                                @elseif ($material->usage)
                                    · <a href="{{ route('usages.show', $material->usage) }}" class="text-indigo-700 hover:underline">{{ $material->usage->purpose->label() }}</a>
                                @endif
                            </p>
                        </div>
                        @if ($canManage)
                            <button type="button" x-show="! removing" @click="removing = true" class="btn-secondary shrink-0 text-xs"><i data-lucide="unplug" class="h-4 w-4 text-rose-600"></i>Lepas</button>
                        @endif
                    </div>
                    <div class="mt-3 border-t border-slate-100 pt-3">
                        @include('warehouses.partials.journey', ['nodes' => $journeys[$material->id], 'compact' => true])
                    </div>
                    @if ($canManage)
                        <form x-show="removing" x-cloak method="POST" action="{{ route('customer-materials.remove', $material) }}" class="mt-3 space-y-3 rounded-lg border border-rose-200 bg-rose-50/50 p-3">
                            @csrf
                            <input type="hidden" name="material_id" value="{{ $material->id }}">
                            <div>
                                <label class="label text-xs" for="reason_{{ $material->id }}">Alasan dilepas</label>
                                <input id="reason_{{ $material->id }}" name="reason" class="input" maxlength="200" required value="{{ (int) old('material_id') === $material->id ? old('reason') : '' }}"
                                    placeholder="mis. diganti modem baru, rusak, berhenti langganan">
                            </div>
                            <label class="flex items-start gap-2 text-sm text-slate-700">
                                <input type="checkbox" name="back_to_office" value="1" checked class="mt-0.5 rounded border-slate-300">
                                <span>Dibawa kembali ke kantor <span class="block text-xs text-slate-500">Masuk lagi ke Gudang Utama{{ $material->serial_number ? ' sebagai unit bekas' : '' }}. Hilangkan centang bila rusak / hilang / ditinggal.</span></span>
                            </label>
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="removing = false" class="btn-secondary text-xs">Batal</button>
                                <button class="btn-primary bg-rose-600 text-xs hover:bg-rose-700"><i data-lucide="unplug" class="h-4 w-4"></i>Lepas dari pelanggan</button>
                            </div>
                        </form>
                    @endif
                </article>
            @empty
                <div class="card p-8 text-center text-slate-400">
                    <i data-lucide="package-open" class="mx-auto mb-2 h-8 w-8 text-slate-300"></i>
                    Tidak ada material terpasang di pelanggan ini
                </div>
            @endforelse
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="card p-5">
            <h2 class="mb-3 flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="history" class="h-4 w-4"></i>Log Material</h2>
            <ol class="relative space-y-4 border-l border-slate-200 pl-6">
                @forelse ($logs as $log)
                    <li class="relative">
                        <span class="absolute top-0.5 -left-[2.05rem] grid h-7 w-7 place-items-center rounded-full border {{ $log->badgeClasses() }}"><i data-lucide="{{ $log->icon() }}" class="h-3.5 w-3.5"></i></span>
                        <p class="text-sm font-medium text-slate-800">{{ $log->label() }} · {{ $log->item_name }}{{ $log->serial_number ? '' : ' × '.$log->quantity.($log->unit ? ' '.$log->unit : '') }}</p>
                        @if ($log->serial_number)
                            <p class="font-mono text-xs text-slate-600">SN {{ $log->serial_number }}</p>
                        @endif
                        @if ($log->from_label || $log->to_label)
                            <p class="text-sm text-slate-600">{{ $log->from_label ?? '-' }} → {{ $log->to_label ?? '-' }}</p>
                        @endif
                        @if ($log->notes)
                            <p class="text-xs text-slate-500">{{ $log->notes }}</p>
                        @endif
                        <p class="text-xs text-slate-400">
                            {{ $log->happened_at->format('d/m/Y H:i') }} · {{ $log->user?->name ?? 'Sistem' }}
                            @if ($log->ticket)
                                · <a href="{{ route('tickets.show', $log->ticket) }}" class="font-mono text-indigo-700 hover:underline">{{ $log->ticket->ticket_number }}</a>
                            @endif
                        </p>
                    </li>
                @empty
                    <li class="text-slate-400">Belum ada log</li>
                @endforelse
            </ol>
        </section>

        <section class="card h-fit overflow-hidden">
            <h2 class="flex items-center gap-2 p-5 pb-3 font-semibold text-slate-800"><i data-lucide="unplug" class="h-4 w-4 text-slate-400"></i>Pernah Terpasang <span class="text-sm font-normal text-slate-500">({{ $removed->count() }})</span></h2>
            <ul class="divide-y divide-slate-100 border-t border-slate-100 text-sm">
                @forelse ($removed as $material)
                    <li class="px-5 py-2.5">
                        <p class="text-slate-800">{{ $material->item_name }}
                            @if ($material->serial_number)
                                <span class="font-mono text-xs text-slate-500">SN {{ $material->serial_number }}</span>
                            @else
                                <span class="text-xs text-slate-500">× {{ $material->quantity }}</span>
                            @endif
                        </p>
                        <p class="text-xs text-slate-500">{{ $material->installed_at->format('d/m/Y') }} – {{ $material->removed_at->format('d/m/Y') }} · {{ $material->removed_reason }}</p>
                    </li>
                @empty
                    <li class="px-5 py-6 text-center text-slate-400">Belum ada material yang dilepas</li>
                @endforelse
            </ul>
        </section>
    </div>
</x-layouts.app>
