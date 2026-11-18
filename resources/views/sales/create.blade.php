<x-layouts.app title="Jual Barang">
    @php
        $buyerType = old('buyer_type', $partners->isEmpty() ? 'lain' : 'mitra');
    @endphp

    <div class="mx-auto max-w-5xl">
        <a href="{{ route('sales.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-700"><i data-lucide="arrow-left" class="h-4 w-4"></i>Log Penjualan</a>
        <div class="mb-6 flex items-center gap-3">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-sky-600 text-white shadow-sm"><i data-lucide="shopping-cart" class="h-6 w-6"></i></span>
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Jual Barang</h1>
                <p class="text-sm text-slate-500">Barang diambil dari stok {{ $main->name }}. Stok berkurang begitu penjualan disimpan.</p>
            </div>
        </div>

        <form method="POST" action="{{ route('sales.store') }}" data-confirm="Simpan penjualan ini? Stok Gudang Utama langsung berkurang."
            x-data="saleForm({ catalog: @js($catalog), lookupUrl: @js(route('warehouses.lookup-serial')), warehouseId: {{ $main->id }}, old: @js(array_values(old('lines', []))) })"
            class="grid gap-6 lg:grid-cols-3">
            @csrf

            {{-- Buyer, date, payment --}}
            <section class="card space-y-4 p-5 lg:order-last" x-data="{ type: @js($buyerType), paid: @js((string) old('paid', '0')) }">
                <h2 class="flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="user" class="h-4 w-4 text-sky-600"></i>Pembeli</h2>
                <div class="grid grid-cols-2 rounded-lg bg-slate-100 p-1 text-xs font-semibold">
                    <label class="cursor-pointer rounded-md px-3 py-2 text-center" :class="type === 'mitra' ? 'bg-white text-sky-700 shadow-sm' : 'text-slate-500'">
                        <input type="radio" name="buyer_type" value="mitra" x-model="type" class="sr-only">Mitra
                    </label>
                    <label class="cursor-pointer rounded-md px-3 py-2 text-center" :class="type === 'lain' ? 'bg-white text-sky-700 shadow-sm' : 'text-slate-500'">
                        <input type="radio" name="buyer_type" value="lain" x-model="type" class="sr-only">Bukan Mitra
                    </label>
                </div>

                <div x-show="type === 'mitra'">
                    <label class="label" for="partner_id">Mitra</label>
                    @if ($partners->isEmpty())
                        <p class="rounded-lg bg-amber-50 p-3 text-xs text-amber-800">Belum ada mitra. <a href="{{ route('partners.index') }}" class="font-semibold underline">Tambah mitra</a> dulu, atau pilih "Bukan Mitra".</p>
                    @else
                        <select id="partner_id" name="partner_id" class="input" :disabled="type !== 'mitra'" :required="type === 'mitra'">
                            <option value="">Pilih mitra</option>
                            @foreach ($partners as $partner)
                                <option value="{{ $partner->id }}" @selected((string) old('partner_id') === (string) $partner->id)>{{ $partner->name }}{{ $partner->phone ? ' · '.$partner->phone : '' }}</option>
                            @endforeach
                        </select>
                    @endif
                    <x-field-error name="partner_id" />
                </div>

                <div x-show="type === 'lain'" x-cloak class="space-y-3">
                    <div>
                        <label class="label" for="buyer_name">Nama pembeli</label>
                        <input id="buyer_name" name="buyer_name" value="{{ old('buyer_name') }}" class="input" maxlength="150" :disabled="type !== 'lain'" :required="type === 'lain'" placeholder="Nama orang / instansi">
                        <x-field-error name="buyer_name" />
                    </div>
                    <div>
                        <label class="label" for="buyer_phone">No. WA <span class="font-normal text-slate-400">(opsional)</span></label>
                        <input id="buyer_phone" name="buyer_phone" value="{{ old('buyer_phone') }}" class="input" maxlength="30" :disabled="type !== 'lain'" inputmode="tel">
                    </div>
                </div>

                <div>
                    <label class="label" for="sold_at">Tanggal jual</label>
                    <input id="sold_at" name="sold_at" type="date" value="{{ old('sold_at', today()->toDateString()) }}" max="{{ today()->toDateString() }}" class="input" required>
                    <x-field-error name="sold_at" />
                </div>

                <div>
                    <span class="label">Pembayaran</span>
                    <div class="grid grid-cols-2 gap-2 text-sm font-semibold">
                        <label class="flex cursor-pointer items-center justify-center gap-1.5 rounded-lg border px-3 py-2"
                            :class="paid === '0' ? 'border-amber-400 bg-amber-50 text-amber-800' : 'border-slate-200 text-slate-500'">
                            <input type="radio" name="paid" value="0" x-model="paid" class="sr-only"><i data-lucide="hourglass" class="h-4 w-4"></i>Belum Lunas
                        </label>
                        <label class="flex cursor-pointer items-center justify-center gap-1.5 rounded-lg border px-3 py-2"
                            :class="paid === '1' ? 'border-emerald-400 bg-emerald-50 text-emerald-800' : 'border-slate-200 text-slate-500'">
                            <input type="radio" name="paid" value="1" x-model="paid" class="sr-only"><i data-lucide="badge-check" class="h-4 w-4"></i>Lunas
                        </label>
                    </div>
                </div>

                <div>
                    <label class="label" for="notes">Keterangan <span class="font-normal text-slate-400">(opsional)</span></label>
                    <input id="notes" name="notes" value="{{ old('notes') }}" class="input" maxlength="255" placeholder="mis. ambil sendiri, transfer BCA">
                </div>

                <div class="rounded-lg bg-slate-50 p-3">
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="text-sm text-slate-500">Total</span>
                        <span class="text-2xl font-bold text-slate-900" x-text="money(total)"></span>
                    </div>
                    <p class="text-xs text-slate-400" x-text="`${lines.length} jenis barang`"></p>
                </div>
                <button class="btn-primary w-full" :disabled="! lines.length"><i data-lucide="save" class="h-4 w-4"></i>Simpan Penjualan</button>
            </section>

            {{-- Cart --}}
            <section class="space-y-4 lg:col-span-2">
                <div class="card space-y-4 p-5">
                    <h2 class="flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="package-plus" class="h-4 w-4 text-sky-600"></i>Barang yang dijual</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        {{-- Devices: scan, and the item is found from its SN. --}}
                        <div>
                            <label class="label">Scan SN perangkat</label>
                            <div class="relative">
                                <i data-lucide="scan-barcode" class="pointer-events-none absolute top-2.5 left-3 h-4 w-4 text-slate-400"></i>
                                <input x-ref="scan" x-model="sn" @keydown.enter.prevent="scan()" :disabled="checking" class="input pl-9 font-mono"
                                    placeholder="Scan / ketik SN lalu Enter" autocomplete="off" autocapitalize="characters" spellcheck="false">
                            </div>
                            <p x-show="checking" x-cloak class="mt-1 text-xs text-slate-500">Mengecek SN...</p>
                            <p x-show="scanError" x-cloak x-text="scanError" class="mt-1 rounded-lg bg-rose-50 p-2 text-xs font-medium text-rose-700"></p>
                        </div>
                        {{-- Material: search the main warehouse's stock. --}}
                        <div class="relative" @click.outside="open = false">
                            <label class="label">Cari material / barang</label>
                            <div class="relative">
                                <i data-lucide="search" class="pointer-events-none absolute top-2.5 left-3 h-4 w-4 text-slate-400"></i>
                                <input x-model="query" @input="open = true; active = 0" @focus="open = true" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)"
                                    @keydown.enter.prevent="pickActive()" @keydown.escape="open = false" class="input pl-9" autocomplete="off" spellcheck="false"
                                    placeholder="Kode, nama, atau kategori">
                            </div>
                            <div x-show="open" x-cloak x-ref="list" class="absolute z-30 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
                                <template x-for="(match, index) in matches" :key="match.code">
                                    <div>
                                        <p x-show="startsGroup(index)" class="sticky top-0 bg-slate-50 px-3 py-1 text-[11px] font-semibold tracking-wide text-slate-500 uppercase" x-text="match.category"></p>
                                        <button type="button" :data-index="index" @click="add(match)" @mouseenter="active = index"
                                            :class="index === active ? 'bg-sky-50' : ''" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm">
                                            <span class="min-w-0">
                                                <span class="block truncate text-slate-800" x-text="match.name"></span>
                                                <span class="font-mono text-xs text-slate-500" x-text="match.code"></span>
                                            </span>
                                            <span class="flex shrink-0 items-center gap-1.5 text-xs">
                                                <span x-show="match.serial" class="badge border-violet-300 bg-violet-50 text-violet-700">SN</span>
                                                <span class="text-slate-600" x-text="`${match.held} ${match.unit}`"></span>
                                            </span>
                                        </button>
                                    </div>
                                </template>
                                <p x-show="! matches.length" class="px-3 py-3 text-center text-sm text-slate-400">Tidak ada barang di stok Gudang Utama</p>
                            </div>
                        </div>
                    </div>
                    <x-field-error name="lines" />
                </div>

                <div class="card overflow-hidden">
                    <template x-for="(line, index) in lines" :key="line.code">
                        <div :data-line="line.code" class="border-b border-slate-100 p-4 last:border-b-0">
                            <input type="hidden" :name="`lines[${index}][item]`" :value="line.code">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate font-semibold text-slate-800" x-text="line.name"></p>
                                    <p class="text-xs text-slate-500"><span class="font-mono" x-text="line.code"></span> · stok <span x-text="`${line.held} ${line.unit}`"></span></p>
                                </div>
                                <button type="button" @click="remove(index)" class="shrink-0 rounded-md p-1 text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Hapus dari daftar">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path d="M18 6 6 18M6 6l12 12" /></svg>
                                </button>
                            </div>

                            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                                <div>
                                    <label class="label text-xs">Jumlah</label>
                                    <template x-if="! line.serial">
                                        <div class="flex">
                                            <input data-quantity type="number" min="1" :max="line.held" x-model.number="line.quantity" :name="`lines[${index}][quantity]`" required class="input rounded-r-none">
                                            <span class="inline-flex items-center rounded-r-lg border border-l-0 border-slate-300 bg-slate-50 px-3 text-sm text-slate-500" x-text="line.unit"></span>
                                        </div>
                                    </template>
                                    <template x-if="line.serial">
                                        <p class="input bg-slate-50 text-slate-600" x-text="`${line.serials.length} unit (scan SN)`"></p>
                                    </template>
                                </div>
                                <div>
                                    <label class="label text-xs">Harga satuan</label>
                                    <div class="flex">
                                        <span class="inline-flex items-center rounded-l-lg border border-r-0 border-slate-300 bg-slate-50 px-3 text-sm text-slate-500">Rp</span>
                                        <input type="number" min="0" step="1" x-model.number="line.price" :name="`lines[${index}][price]`" required class="input rounded-l-none" placeholder="0">
                                    </div>
                                </div>
                                <div class="text-right">
                                    <span class="label text-xs">Subtotal</span>
                                    <p class="py-2 text-lg font-bold text-slate-900" x-text="money(subtotal(line))"></p>
                                </div>
                            </div>

                            <div x-show="line.serial" class="mt-3">
                                <div class="flex flex-wrap gap-1.5">
                                    <template x-for="(serial, i) in line.serials" :key="serial">
                                        <span class="inline-flex items-center gap-1 rounded-full border border-violet-200 bg-violet-50 py-0.5 pr-1 pl-2.5 font-mono text-xs font-semibold text-violet-800">
                                            <span x-text="serial"></span>
                                            <input type="hidden" :name="`lines[${index}][serials][${i}]`" :value="serial">
                                            <button type="button" @click="removeSerial(line, serial)" class="rounded-full px-1 hover:bg-violet-200" :aria-label="`Hapus SN ${serial}`">&times;</button>
                                        </span>
                                    </template>
                                </div>
                                <p x-show="! line.serials.length" class="text-xs text-amber-700">Scan SN unit <span x-text="line.code"></span> yang dijual di kotak scan di atas.</p>
                            </div>
                        </div>
                    </template>
                    <div x-show="! lines.length" class="px-5 py-12 text-center text-sm text-slate-400">
                        <i data-lucide="shopping-cart" class="mx-auto mb-2 h-8 w-8 text-slate-300"></i>
                        Scan SN perangkat atau cari material untuk mulai.
                    </div>
                </div>
            </section>
        </form>
    </div>
</x-layouts.app>
