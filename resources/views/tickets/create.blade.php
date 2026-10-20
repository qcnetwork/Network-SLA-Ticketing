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

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="customer_name">Nama Pelanggan <span class="text-rose-500">*</span></label>
                    <input id="customer_name" name="customer_name" value="{{ old('customer_name') }}" class="input" required maxlength="150">
                    <x-field-error name="customer_name" />
                </div>
                <div>
                    <label class="label" for="customer_phone">No. WhatsApp <span class="text-rose-500">*</span></label>
                    <input id="customer_phone" name="customer_phone" value="{{ old('customer_phone') }}" class="input" required placeholder="08xxxxxxxxxx" inputmode="tel">
                    <x-field-error name="customer_phone" />
                </div>
                <div>
                    <label class="label" for="customer_id">ID Pelanggan</label>
                    <input id="customer_id" name="customer_id" value="{{ old('customer_id') }}" class="input" placeholder="Kosongkan untuk dibuat otomatis">
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
                    <label class="label" for="customer_address">Alamat</label>
                    <input id="customer_address" name="customer_address" value="{{ old('customer_address') }}" class="input" maxlength="255">
                    <x-field-error name="customer_address" />
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="category">Kategori Kendala <span class="text-rose-500">*</span></label>
                    <select id="category" name="category" class="input" required>
                        @foreach ($categories as $category)
                            <option value="{{ $category }}" @selected(old('category') === $category)>{{ $category }}</option>
                        @endforeach
                    </select>
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
                <button class="btn-primary"><i data-lucide="send" class="h-4 w-4"></i>Simpan & Kirim ke Helpdesk</button>
            </div>
        </form>
    </div>
</x-layouts.app>
