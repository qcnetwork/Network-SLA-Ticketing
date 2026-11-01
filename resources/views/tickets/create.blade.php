<x-layouts.app title="Tiket Baru">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('dashboard') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-700">
            <i data-lucide="arrow-left" class="h-4 w-4"></i>Kembali ke dashboard
        </a>

        <form method="POST" action="{{ route('tickets.store') }}" class="card p-6">
            @csrf
            <div class="mb-6">
                <h1 class="text-xl font-bold text-slate-900">Buat Tiket Baru</h1>
                <p class="text-sm text-slate-500">Catat komplain pelanggan dari WhatsApp. Tiket tetap di tahap <b>Proses CS</b> selama koordinasi dengan pelanggan, lalu diteruskan ke <b>Antrian Helpdesk</b> untuk diambil tim HD.</p>
            </div>

            @if ($openIncidents->isNotEmpty())
                <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4">
                    <label class="label flex items-center gap-2 text-red-800" for="incident_id"><i data-lucide="radio-tower" class="h-4 w-4"></i>Pelanggan terdampak gangguan massal?</label>
                    <select id="incident_id" name="incident_id" class="input">
                        <option value="">Tidak, tiket biasa (diproses CS dulu)</option>
                        @foreach ($openIncidents as $incident)
                            <option value="{{ $incident->id }}" @selected((string) old('incident_id', request('incident')) === (string) $incident->id)>
                                {{ $incident->incident_number }} · {{ $incident->title }} ({{ $incident->area }})
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-red-700">Jika dipilih, tiket langsung masuk gamas dan tidak perlu ditangani Helpdesk satu per satu.</p>
                    <x-field-error name="incident_id" />
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2" x-data="customerPicker(@js(route('customers.search')), @js($customer))">
                <div class="relative sm:col-span-2">
                    <label class="label" for="customer_search">Cari Pelanggan Lama</label>
                    <div class="relative">
                        <input id="customer_search" x-ref="search" x-model="query" type="text" autocomplete="off" class="input pr-9"
                            placeholder="Ketik ID pelanggan, nama, atau no. WhatsApp untuk isi otomatis"
                            role="combobox" aria-autocomplete="list" :aria-expanded="open" aria-controls="customer_search_list"
                            @input="onInput()" @focus="open = true" @blur="open = false"
                            @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
                            @keydown.enter.prevent="accept()" @keydown.escape="open = false">
                        <i data-lucide="search" class="pointer-events-none absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 text-slate-400"></i>
                    </div>
                    <ul id="customer_search_list" x-show="open && results.length" x-cloak role="listbox"
                        class="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg">
                        <template x-for="(customer, index) in results" :key="customer.code">
                            {{-- mousedown so the choice lands before the input's blur closes the list. --}}
                            <li role="option" :aria-selected="index === active" @mousedown.prevent="choose(customer)" @mouseenter="active = index"
                                :class="index === active ? 'bg-indigo-50' : ''" class="cursor-pointer px-3 py-2">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-medium text-slate-800" x-text="customer.name"></span>
                                    <span class="font-mono text-xs text-slate-500" x-text="customer.code"></span>
                                </div>
                                <div class="text-xs text-slate-500">
                                    <span x-text="customer.phone"></span> · <span x-text="customer.tickets + ' tiket'"></span>
                                    <span x-show="customer.open_ticket" class="font-semibold text-amber-600"> · masih ada tiket aktif</span>
                                </div>
                            </li>
                        </template>
                    </ul>
                    <p x-show="open && !loading && query.trim().length >= 2 && !results.length" x-cloak class="mt-1 text-xs text-slate-500">
                        Pelanggan belum terdaftar. Isi data di bawah, nanti otomatis tersimpan sebagai pelanggan baru.
                    </p>

                    <div x-show="picked" x-cloak class="mt-2 rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-sm text-indigo-800">
                        <div class="flex items-start justify-between gap-2">
                            <p>
                                <i data-lucide="user" class="mr-1 inline h-4 w-4"></i>
                                Data diisi dari pelanggan <b x-text="picked?.name"></b> (<span class="font-mono" x-text="picked?.code"></span>),
                                <span x-text="picked?.tickets"></span> tiket sebelumnya. Perubahan di form ikut memperbarui data pelanggan.
                            </p>
                            <button type="button" @click="clear()" class="shrink-0 text-xs font-semibold text-indigo-700 hover:underline">Ganti</button>
                        </div>
                        <p x-show="picked?.open_ticket" class="mt-2 flex items-start gap-1 font-medium text-amber-700">
                            <i data-lucide="alert-triangle" class="mt-0.5 h-4 w-4 shrink-0"></i>
                            <span>Pelanggan ini masih punya tiket aktif
                                <a :href="picked?.open_ticket?.url" target="_blank" class="font-mono underline" x-text="picked?.open_ticket?.number"></a>
                                (<span x-text="picked?.open_ticket?.status"></span>). Pastikan bukan komplain yang sama.</span>
                        </p>
                    </div>
                </div>
                <div>
                    <label class="label" for="customer_name">Nama Pelanggan <span class="text-rose-500">*</span></label>
                    <input id="customer_name" x-ref="name" name="customer_name" value="{{ old('customer_name', $customer['name'] ?? '') }}" class="input" required maxlength="150">
                    <x-field-error name="customer_name" />
                </div>
                <div>
                    <label class="label" for="customer_phone">No. WhatsApp <span class="text-rose-500">*</span></label>
                    <input id="customer_phone" x-ref="phone" name="customer_phone" value="{{ old('customer_phone', $customer['phone'] ?? '') }}" class="input" required placeholder="08xxxxxxxxxx" inputmode="tel">
                    <x-field-error name="customer_phone" />
                </div>
                <div>
                    <label class="label" for="customer_id">ID Pelanggan</label>
                    <input id="customer_id" x-ref="id" name="customer_id" value="{{ old('customer_id', $customer['code'] ?? '') }}" class="input" maxlength="50"
                        placeholder="Kosongkan untuk dibuat otomatis" @change="lookupId()">
                    <p class="mt-1 text-xs text-slate-500">Isi ID pelanggan yang sudah terdaftar untuk mengisi data otomatis.</p>
                    <x-field-error name="customer_id" />
                </div>
                <div>
                    <label class="label" for="priority">Prioritas <span class="text-rose-500">*</span></label>
                    <select id="priority" name="priority" class="input" required>
                        @foreach ($priorities as $priority)
                            <option value="{{ $priority->value }}" @selected(old('priority', 'SEDANG') === $priority->value)>{{ $priority->label() }}</option>
                        @endforeach
                    </select>
                    <x-field-error name="priority" />
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="customer_address">Alamat / Shareloc</label>
                    <input id="customer_address" x-ref="address" name="customer_address" value="{{ old('customer_address', $customer['address'] ?? '') }}" class="input" maxlength="255" placeholder="Alamat, link shareloc Google Maps/WhatsApp, atau koordinat (-6.2088, 106.8456)">
                    <p class="mt-1 text-xs text-slate-500">Kalau diisi link shareloc atau koordinat, titik lokasinya otomatis tampil di detail tiket.</p>
                    <x-field-error name="customer_address" />
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="category">Kategori Kendala <span class="text-rose-500">*</span></label>
                    <x-option-search name="category" id="category" :options="$categories" :selected="old('category')"
                        placeholder="Ketik untuk mencari, contoh: los, lambat, modem" message="Pilih kategori yang ada di daftar" />
                    <x-field-error name="category" />
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="complaint">Detail Kendala <span class="text-rose-500">*</span></label>
                    <textarea id="complaint" name="complaint" rows="4" class="input" required maxlength="2000" placeholder="Contoh: Lampu LOS merah sejak pagi setelah hujan">{{ old('complaint') }}</textarea>
                    <x-field-error name="complaint" />
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-2">
                <a href="{{ route('dashboard') }}" class="btn-secondary">Batal</a>
                <button class="btn-primary"><i data-lucide="save" class="h-4 w-4"></i>Simpan Tiket</button>
            </div>
        </form>
    </div>
</x-layouts.app>
