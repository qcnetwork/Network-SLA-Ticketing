@use('App\Enums\UsagePurpose')
<x-layouts.app title="Catat Pemakaian Alat">
    @php
        $source = (int) old('warehouse_id', $sources->first()->id);
        $me = auth()->user();
    @endphp

    <div class="mx-auto max-w-5xl">
        <a href="{{ route('usages.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-700"><i data-lucide="arrow-left" class="h-4 w-4"></i>Pemakaian Alat</a>
        <div class="mb-6 flex items-center gap-3">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-teal-600 text-white shadow-sm"><i data-lucide="clipboard-list" class="h-6 w-6"></i></span>
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Catat Pemakaian Alat</h1>
                <p class="text-sm text-slate-500">Alat & material yang terpakai, dipakai di mana dan untuk apa. Stok gudang yang dipilih langsung berkurang.</p>
            </div>
        </div>

        <form method="POST" action="{{ route('usages.store') }}" data-confirm="Simpan pemakaian ini? Stok langsung berkurang."
            x-data="usageForm({ catalogs: @js($catalogs), source: {{ $source }}, lookupUrl: @js(route('warehouses.lookup-serial')), old: @js(array_values(old('lines', []))) })"
            class="grid gap-6 lg:grid-cols-3">
            @csrf

            {{-- The job --}}
            <section class="card space-y-4 p-5 lg:order-last">
                <h2 class="flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="map-pin" class="h-4 w-4 text-teal-600"></i>Pemakaian</h2>

                <div>
                    <label class="label" for="warehouse_id">Stok dari</label>
                    @if ($canManage)
                        <select id="warehouse_id" name="warehouse_id" x-model="source" @change="switchSource()" class="input">
                            @foreach ($sources as $warehouse)
                                <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                            @endforeach
                        </select>
                    @else
                        <input type="hidden" name="warehouse_id" value="{{ $sources->first()->id }}">
                        <p class="input bg-slate-50 text-slate-700">{{ $sources->first()->name }}</p>
                    @endif
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="used_at">Tanggal pakai</label>
                        <input id="used_at" name="used_at" type="date" value="{{ old('used_at', today()->toDateString()) }}" max="{{ today()->toDateString() }}" class="input" required>
                    </div>
                    <div>
                        <label class="label" for="purpose">Keperluan</label>
                        <select id="purpose" name="purpose" class="input" required>
                            @foreach (UsagePurpose::cases() as $purpose)
                                <option value="{{ $purpose->value }}" @selected(old('purpose', UsagePurpose::PEMASANGAN_BARU->value) === $purpose->value)>{{ $purpose->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <x-field-error name="used_at" />

                <div>
                    <label class="label" for="location">Lokasi pemasangan</label>
                    <input id="location" name="location" value="{{ old('location') }}" class="input" required maxlength="255" placeholder="Nama pelanggan + desa, atau nama lokasi">
                    <x-field-error name="location" />
                </div>

                <div>
                    <label class="label" for="project">Report / Project <span class="font-normal text-slate-400">(opsional)</span></label>
                    <input id="project" name="project" value="{{ old('project') }}" class="input" maxlength="100" list="project_options" placeholder="mis. PSB POP KAJEN, POS KURIPAN">
                    <datalist id="project_options">
                        @foreach ($projects as $project)
                            <option value="{{ $project }}">
                        @endforeach
                    </datalist>
                </div>

                <div>
                    <label class="label" for="team">Teknisi</label>
                    <input id="team" name="team" value="{{ old('team', $canManage ? '' : $me->name) }}" class="input" maxlength="150" list="team_options" placeholder="mis. ALI - BAMBANG">
                    <datalist id="team_options">
                        @foreach ($technicians as $name)
                            <option value="{{ $name }}">
                        @endforeach
                    </datalist>
                </div>

                <div>
                    <label class="label" for="ticket_number">No. tiket <span class="font-normal text-slate-400">(opsional)</span></label>
                    <input id="ticket_number" name="ticket_number" value="{{ old('ticket_number') }}" class="input font-mono uppercase" maxlength="40" placeholder="Jika terkait tiket komplain / PSB">
                    <x-field-error name="ticket_number" />
                </div>

                <div>
                    <label class="label" for="removed_device">Alat cabutan <span class="font-normal text-slate-400">(opsional)</span></label>
                    <input id="removed_device" name="removed_device" value="{{ old('removed_device') }}" class="input" maxlength="255" placeholder="Alat lama yang dicabut, mis. MODEM F609 + SN">
                </div>

                <div>
                    <label class="label" for="notes">Catatan tambahan <span class="font-normal text-slate-400">(opsional)</span></label>
                    <input id="notes" name="notes" value="{{ old('notes') }}" class="input" maxlength="255" placeholder="mis. ALAT PINJAMI, LOS, PROMO FREE">
                </div>

                <button class="btn-primary w-full" :disabled="! lines.length"><i data-lucide="save" class="h-4 w-4"></i>Simpan Pemakaian</button>
            </section>

            {{-- The items --}}
            <section class="space-y-4 lg:col-span-2">
                <div class="card space-y-4 p-5">
                    <h2 class="flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="package-minus" class="h-4 w-4 text-teal-600"></i>Barang yang dipakai</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
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
                                            :class="index === active ? 'bg-teal-50' : ''" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm">
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
                                <p x-show="! matches.length" class="px-3 py-3 text-center text-sm text-slate-400">Tidak ada barang di stok ini</p>
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
                                <div class="sm:col-span-2">
                                    <label class="label text-xs">Catatan barang <span class="font-normal text-slate-400">(opsional)</span></label>
                                    <input x-model="line.notes" :name="`lines[${index}][notes]`" class="input" maxlength="255" placeholder="mis. CABUTAN, TANPA ADAPTOR">
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
                                <p x-show="! line.serials.length" class="text-xs text-amber-700">Scan SN unit <span x-text="line.code"></span> yang dipakai di kotak scan di atas.</p>
                            </div>
                        </div>
                    </template>
                    <div x-show="! lines.length" class="px-5 py-12 text-center text-sm text-slate-400">
                        <i data-lucide="clipboard-list" class="mx-auto mb-2 h-8 w-8 text-slate-300"></i>
                        Scan SN perangkat atau cari material untuk mulai.
                    </div>
                </div>
            </section>
        </form>
    </div>
</x-layouts.app>
