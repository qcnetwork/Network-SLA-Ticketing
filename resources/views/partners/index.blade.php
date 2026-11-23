@use('App\Models\Sale')
<x-layouts.app title="Mitra">
    @include('warehouses.partials.nav', ['back' => ['Log Penjualan', route('sales.index')]])
    <div class="mb-6 flex items-center gap-3">
        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-sky-600 text-white shadow-sm"><i data-lucide="handshake" class="h-6 w-6"></i></span>
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Mitra</h1>
            <p class="text-sm text-slate-500">Mitra yang membeli barang dari gudang. Pembeli yang bukan mitra cukup diketik namanya saat jual.</p>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="card h-fit p-5">
            <h2 class="mb-4 flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="plus-circle" class="h-4 w-4 text-sky-600"></i>Tambah Mitra</h2>
            <form method="POST" action="{{ route('partners.store') }}" class="space-y-3">
                @csrf
                <div>
                    <label class="label" for="name">Nama mitra</label>
                    <input id="name" name="name" value="{{ old('name') }}" class="input" required maxlength="150" placeholder="mis. RT/RW Net Sumberejo">
                    <x-field-error name="name" />
                </div>
                <div>
                    <label class="label" for="phone">No. WA <span class="font-normal text-slate-400">(opsional)</span></label>
                    <input id="phone" name="phone" value="{{ old('phone') }}" class="input" maxlength="30" inputmode="tel">
                </div>
                <div>
                    <label class="label" for="address">Alamat <span class="font-normal text-slate-400">(opsional)</span></label>
                    <input id="address" name="address" value="{{ old('address') }}" class="input" maxlength="255">
                </div>
                <div>
                    <label class="label" for="partner_notes">Catatan <span class="font-normal text-slate-400">(opsional)</span></label>
                    <input id="partner_notes" name="notes" value="{{ old('notes') }}" class="input" maxlength="255">
                </div>
                <button class="btn-primary w-full"><i data-lucide="save" class="h-4 w-4"></i>Simpan Mitra</button>
            </form>
        </section>

        <section class="space-y-3 lg:col-span-2">
            @forelse ($partners as $partner)
                <div x-data="{ edit: false }" @class(['card p-4', 'opacity-60' => ! $partner->active])>
                    <div x-show="! edit" class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="flex items-center gap-2 font-semibold text-slate-800">
                                {{ $partner->name }}
                                @unless ($partner->active)
                                    <span class="badge border-slate-300 bg-slate-100 text-slate-600">Nonaktif</span>
                                @endunless
                            </p>
                            <p class="text-xs text-slate-500">{{ collect([$partner->phone, $partner->address])->filter()->implode(' · ') ?: 'Belum ada kontak' }}</p>
                            @if ($partner->notes)
                                <p class="text-xs text-slate-400">{{ $partner->notes }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-4 text-right text-xs">
                            <a href="{{ route('sales.index', ['mitra' => $partner->id]) }}" class="hover:underline">
                                <span class="block text-sm font-bold text-slate-900">{{ Sale::rupiah($partner->sales_sum_total) }}</span>
                                <span class="text-slate-500">{{ $partner->sales_count }} transaksi</span>
                            </a>
                            @if ($partner->unpaid_total)
                                <a href="{{ route('sales.index', ['mitra' => $partner->id, 'status' => 'BELUM_LUNAS']) }}" class="hover:underline">
                                    <span class="block text-sm font-bold text-amber-700">{{ Sale::rupiah($partner->unpaid_total) }}</span>
                                    <span class="text-amber-600">belum lunas</span>
                                </a>
                            @endif
                            <button type="button" @click="edit = true" class="btn-secondary px-2 py-1 text-xs"><i data-lucide="pencil" class="h-3.5 w-3.5"></i>Ubah</button>
                        </div>
                    </div>

                    <form x-show="edit" x-cloak method="POST" action="{{ route('partners.update', $partner) }}" class="grid gap-3 sm:grid-cols-2">
                        @csrf
                        @method('PUT')
                        <input name="name" value="{{ $partner->name }}" class="input" required maxlength="150" placeholder="Nama mitra">
                        <input name="phone" value="{{ $partner->phone }}" class="input" maxlength="30" placeholder="No. WA">
                        <input name="address" value="{{ $partner->address }}" class="input" maxlength="255" placeholder="Alamat">
                        <input name="notes" value="{{ $partner->notes }}" class="input" maxlength="255" placeholder="Catatan">
                        <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="active" value="1" @checked($partner->active) class="rounded border-slate-300">Aktif (muncul di pilihan saat jual)</label>
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="edit = false" class="btn-secondary">Batal</button>
                            <button class="btn-primary"><i data-lucide="save" class="h-4 w-4"></i>Simpan</button>
                        </div>
                    </form>
                </div>
            @empty
                <div class="card px-5 py-12 text-center text-sm text-slate-400">Belum ada mitra. Tambahkan di form sebelah kiri.</div>
            @endforelse
        </section>
    </div>
</x-layouts.app>
