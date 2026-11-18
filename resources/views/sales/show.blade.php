@use('App\Enums\PaymentStatus')
@use('App\Models\Sale')
<x-layouts.app :title="'Penjualan '.$sale->sale_number">
    <div class="mx-auto max-w-4xl">
        <a href="{{ route('sales.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-700 print:hidden"><i data-lucide="arrow-left" class="h-4 w-4"></i>Log Penjualan</a>

        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="font-mono text-sm font-semibold text-sky-700">{{ $sale->sale_number }}</p>
                <h1 class="text-2xl font-bold text-slate-900">{{ $sale->buyer_name }}</h1>
                <p class="text-sm text-slate-500">
                    {{ $sale->partner_id ? 'Mitra' : 'Bukan mitra' }}{{ $sale->buyer_phone ? ' · '.$sale->buyer_phone : '' }}
                    · {{ $sale->sold_at->translatedFormat('d F Y') }}
                </p>
            </div>
            <div class="flex items-center gap-2 print:hidden">
                <span class="badge {{ $sale->payment_status->badgeClasses() }} px-3 py-1 text-sm"><i data-lucide="{{ $sale->payment_status->icon() }}" class="h-4 w-4"></i>{{ $sale->payment_status->label() }}</span>
                <button type="button" x-data @click="window.print()" class="btn-secondary"><i data-lucide="printer" class="h-4 w-4"></i>Cetak Nota</button>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="card overflow-hidden lg:col-span-2">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">Barang</th>
                            <th class="px-4 py-3 text-right">Jumlah</th>
                            <th class="px-4 py-3 text-right">Harga</th>
                            <th class="px-4 py-3 text-right">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($sale->lines as $line)
                            @php($serials = $sale->serialUnits->where('item_id', $line->item_id))
                            <tr class="align-top">
                                <td class="px-4 py-3">
                                    <span class="font-medium text-slate-800">{{ $line->item->name }}</span>
                                    <span class="block font-mono text-xs text-slate-500">{{ $line->item->code }}</span>
                                    @if ($serials->isNotEmpty())
                                        <span class="mt-1 flex flex-wrap gap-1">
                                            @foreach ($serials as $unit)
                                                <span class="rounded bg-violet-50 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-violet-700">{{ $unit->serial_number }}</span>
                                            @endforeach
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">{{ $line->quantity }} {{ $line->item->unit }}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">{{ Sale::rupiah($line->unit_price) }}</td>
                                <td class="px-4 py-3 text-right font-semibold whitespace-nowrap">{{ Sale::rupiah($line->subtotal) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t-2 border-slate-200 bg-slate-50">
                        <tr>
                            <td colspan="3" class="px-4 py-3 text-right font-semibold text-slate-600">Total</td>
                            <td class="px-4 py-3 text-right text-lg font-bold whitespace-nowrap text-slate-900">{{ Sale::rupiah($sale->total) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </section>

            <aside class="space-y-4">
                <section class="card space-y-2 p-5 text-sm">
                    <h2 class="mb-2 flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="info" class="h-4 w-4 text-sky-600"></i>Keterangan</h2>
                    <p class="flex justify-between gap-2"><span class="text-slate-500">Dicatat oleh</span><span class="text-right font-medium">{{ $sale->user?->name ?? '-' }}</span></p>
                    <p class="flex justify-between gap-2"><span class="text-slate-500">Waktu catat</span><span class="text-right">{{ $sale->created_at->format('d/m/Y H:i') }}</span></p>
                    @if ($sale->partner)
                        <p class="flex justify-between gap-2"><span class="text-slate-500">Alamat mitra</span><span class="text-right">{{ $sale->partner->address ?: '-' }}</span></p>
                    @endif
                    @if ($sale->notes)
                        <p class="rounded-lg bg-slate-50 p-2 text-slate-700">{{ $sale->notes }}</p>
                    @endif
                </section>

                <section class="card space-y-3 p-5 text-sm print:hidden">
                    <h2 class="flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="wallet" class="h-4 w-4 text-sky-600"></i>Pembayaran</h2>
                    @if ($sale->isPaid())
                        <p class="rounded-lg bg-emerald-50 p-3 text-emerald-800">
                            Lunas {{ $sale->paid_at?->format('d/m/Y H:i') }}{{ $sale->payer ? ', ditandai oleh '.$sale->payer->name : '' }}.
                            @if ($sale->payment_notes)
                                <span class="block text-xs">{{ $sale->payment_notes }}</span>
                            @endif
                        </p>
                        <form method="POST" action="{{ route('sales.payment', $sale) }}" data-confirm="Kembalikan {{ $sale->sale_number }} ke Belum Lunas?">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="payment_status" value="{{ PaymentStatus::BELUM_LUNAS->value }}">
                            <button class="text-xs font-semibold text-slate-500 hover:text-rose-600 hover:underline">Salah tandai? Kembalikan ke Belum Lunas</button>
                        </form>
                    @else
                        <p class="rounded-lg bg-amber-50 p-3 text-amber-800">Belum lunas, sisa tagihan <b>{{ Sale::rupiah($sale->total) }}</b>.</p>
                        <form method="POST" action="{{ route('sales.payment', $sale) }}" class="space-y-2" data-confirm="Tandai {{ $sale->sale_number }} sudah lunas?">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="payment_status" value="{{ PaymentStatus::LUNAS->value }}">
                            <input name="payment_notes" class="input" maxlength="255" placeholder="Catatan bayar (opsional), mis. transfer BRI">
                            <button class="btn-success w-full"><i data-lucide="badge-check" class="h-4 w-4"></i>Tandai Lunas</button>
                        </form>
                    @endif
                </section>
            </aside>
        </div>
    </div>
</x-layouts.app>
