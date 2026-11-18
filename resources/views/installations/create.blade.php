<x-layouts.app title="PSB Baru">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('installations.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-700">
            <i data-lucide="arrow-left" class="h-4 w-4"></i>Kembali ke daftar PSB
        </a>

        <form method="POST" action="{{ route('installations.store') }}" enctype="multipart/form-data" class="card p-6">
            @csrf
            <div class="mb-6">
                <h1 class="flex items-center gap-2 text-xl font-bold text-slate-900"><i data-lucide="house-plus" class="h-5 w-5 text-indigo-600"></i>Order Pasang Baru (PSB)</h1>
                <p class="text-sm text-slate-500">Catat calon pelanggan baru. Order masuk tahap <b>Cek Lokasi</b>, lalu Helpdesk memutuskan bisa langsung dipasang atau perlu survey dulu.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="customer_name">Nama Sesuai KTP <span class="text-rose-500">*</span></label>
                    <input id="customer_name" name="customer_name" value="{{ old('customer_name') }}" class="input" required maxlength="150" autocomplete="off">
                    <x-field-error name="customer_name" />
                </div>
                <div>
                    <label class="label" for="customer_phone">No. WhatsApp Aktif <span class="text-rose-500">*</span></label>
                    <input id="customer_phone" name="customer_phone" value="{{ old('customer_phone') }}" class="input" required inputmode="tel" placeholder="08xxxxxxxxxx">
                    <x-field-error name="customer_phone" />
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="customer_address">Shareloc / Alamat <span class="text-rose-500">*</span></label>
                    <input id="customer_address" name="customer_address" value="{{ old('customer_address') }}" class="input" required maxlength="255"
                        placeholder="Link shareloc Google Maps/WhatsApp, atau koordinat (-6.2088, 106.8456)">
                    <p class="mt-1 text-xs text-slate-500">Tempel link shareloc dari calon pelanggan, titik lokasinya otomatis terbaca untuk cek coverage.</p>
                    <x-field-error name="customer_address" />
                </div>
                <div>
                    <label class="label" for="package">Paket Layanan @if ($packages)<span class="text-rose-500">*</span>@endif</label>
                    @if ($packages)
                        <select id="package" name="package" class="input" required>
                            <option value="">Pilih paket</option>
                            @foreach ($packages as $package)
                                <option value="{{ $package }}" @selected(old('package') === $package)>{{ $package }}</option>
                            @endforeach
                        </select>
                    @else
                        <p class="rounded-lg bg-amber-50 p-2 text-xs text-amber-800">
                            Belum ada paket layanan.
                            @if (auth()->user()->hasRole(App\Enums\Role::SPV))
                                <a href="{{ route('packages.index') }}" class="font-semibold underline">Tambahkan paket</a>
                            @else
                                Minta Supervisor menambahkannya di Pengaturan.
                            @endif
                        </p>
                    @endif
                    <x-field-error name="package" />
                </div>
                <div>
                    <label class="label" for="sales">Sales / Referensi</label>
                    <input id="sales" name="sales" value="{{ old('sales') }}" class="input" maxlength="100" placeholder="Diisi tim internal (opsional)">
                    <x-field-error name="sales" />
                </div>
                <div class="sm:col-span-2">
                    <x-photo-input id="house_photos" label="Foto depan rumah" :required="true" />
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="notes">Catatan</label>
                    <textarea id="notes" name="notes" rows="3" class="input" maxlength="2000" placeholder="Contoh: Rumah cat hijau, sebelah warung. Pelanggan minta pasang hari Sabtu">{{ old('notes') }}</textarea>
                    <x-field-error name="notes" />
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-2">
                <a href="{{ route('installations.index') }}" class="btn-secondary">Batal</a>
                <button class="btn-primary"><i data-lucide="send" class="h-4 w-4"></i>Buat PSB</button>
            </div>
        </form>
    </div>
</x-layouts.app>
