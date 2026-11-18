<x-layouts.app title="Detail Pemakaian Alat">
    <div class="mx-auto max-w-4xl">
        <a href="{{ route('usages.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-700"><i data-lucide="arrow-left" class="h-4 w-4"></i>Pemakaian Alat</a>

        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="mb-1 flex flex-wrap items-center gap-2">
                    <span class="badge {{ $usage->purpose->badgeClasses() }}">{{ $usage->purpose->label() }}</span>
                    @if ($usage->project)
                        <span class="badge border-slate-200 bg-white text-slate-600">{{ $usage->project }}</span>
                    @endif
                    @if ($usage->isImported())
                        <span class="badge border-slate-200 bg-slate-50 text-slate-500">Riwayat dari Excel · tidak mengubah stok</span>
                    @endif
                </div>
                <h1 class="text-2xl font-bold text-slate-900">{{ $usage->location }}</h1>
                <p class="text-sm text-slate-500">{{ $usage->used_at->translatedFormat('l, d F Y') }} · {{ $usage->team ?: 'Teknisi tidak diisi' }}</p>
            </div>
            @if ($usage->ticket)
                <a href="{{ route('tickets.show', $usage->ticket) }}" class="btn-secondary shrink-0"><i data-lucide="ticket" class="h-4 w-4"></i>{{ $usage->ticket->ticket_number }}</a>
            @endif
        </div>

        @if ($usage->isCancelled())
            <div class="mb-6 rounded-xl border border-slate-300 bg-slate-100 p-4 text-sm text-slate-700">
                <b>Dibatalkan</b> {{ $usage->cancelled_at->format('d/m/Y H:i') }} oleh {{ $usage->canceller?->name ?? '-' }}: {{ $usage->cancel_reason }}
                @unless ($usage->isImported())
                    <span class="block text-xs text-slate-500">Stok sudah dikembalikan ke gudang asalnya.</span>
                @endunless
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="card overflow-hidden lg:col-span-2">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">Barang</th>
                            <th class="px-4 py-3 text-right">Qty</th>
                            <th class="px-4 py-3">Stok dari</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($usage->lines as $line)
                            <tr class="align-top">
                                <td class="px-4 py-3">
                                    <span class="font-medium text-slate-800">{{ $line->item_name }}</span>
                                    @if ($line->item)
                                        <span class="font-mono text-xs text-slate-500">{{ $line->item->code }}</span>
                                    @endif
                                    @if ($line->serial_number)
                                        <span class="block font-mono text-xs text-violet-700">SN {{ $line->serial_number }}{{ $line->mac ? ' · MAC '.$line->formattedMac() : '' }}</span>
                                    @endif
                                    @if ($line->notes)
                                        <span class="block text-xs text-slate-500">{{ $line->notes }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-semibold whitespace-nowrap">{{ $line->quantity }} {{ $line->item?->unit }}</td>
                                <td class="px-4 py-3 text-xs text-slate-600">{{ $line->warehouse?->name ?? $usage->holder_name ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            <aside class="space-y-4">
                <section class="card space-y-2 p-5 text-sm">
                    <h2 class="mb-2 flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="info" class="h-4 w-4 text-teal-600"></i>Keterangan</h2>
                    @if ($usage->removed_device)
                        <p><span class="block text-xs text-slate-500">Alat cabutan</span>{{ $usage->removed_device }}</p>
                    @endif
                    @if ($usage->notes)
                        <p><span class="block text-xs text-slate-500">Catatan tambahan</span>{{ $usage->notes }}</p>
                    @endif
                    <p><span class="block text-xs text-slate-500">Dicatat oleh</span>{{ $usage->user?->name ?? ($usage->isImported() ? 'Impor Excel' : '-') }} · {{ $usage->created_at->format('d/m/Y H:i') }}</p>
                    @if ($usage->material_request_id)
                        <p class="rounded-lg bg-indigo-50 p-2 text-xs text-indigo-800">Otomatis dari material Resume Perbaikan yang di-ACC Gudang.</p>
                    @endif
                </section>

                @if ($canManage && ! $usage->isCancelled() && ! $usage->material_request_id)
                    <details class="card p-5 text-sm" @if ($errors->has('cancel_reason')) open @endif>
                        <summary class="cursor-pointer font-semibold text-rose-700">Batalkan pemakaian ini</summary>
                        <form method="POST" action="{{ route('usages.cancel', $usage) }}" class="mt-3 space-y-2"
                            data-confirm="{{ $usage->isImported() ? 'Tandai riwayat ini batal?' : 'Batalkan pemakaian? Stok dan SN dikembalikan ke gudang asalnya.' }}">
                            @csrf
                            <p class="text-xs text-slate-500">{{ $usage->isImported() ? 'Riwayat dari Excel hanya ditandai batal.' : 'Untuk salah input: stok dan SN kembali ke gudang asalnya.' }}</p>
                            <input name="cancel_reason" class="input" required maxlength="255" placeholder="Alasan, mis. salah pilih barang">
                            <x-field-error name="cancel_reason" />
                            <button class="btn-danger w-full"><i data-lucide="rotate-ccw" class="h-4 w-4"></i>Batalkan</button>
                        </form>
                    </details>
                @endif
            </aside>
        </div>
    </div>
</x-layouts.app>
