<x-layouts.app title="Buat Gamas">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('incidents.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-700">
            <i data-lucide="arrow-left" class="h-4 w-4"></i>Kembali ke daftar gamas
        </a>

        <form method="POST" action="{{ route('incidents.store') }}" class="card p-6">
            @csrf
            <div class="mb-6">
                <h1 class="text-xl font-bold text-slate-900">Buat Gangguan Massal</h1>
                <p class="text-sm text-slate-500">Kelompokkan tiket pelanggan yang terdampak gangguan yang sama, supaya cukup ditangani sekali.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="label" for="title">Judul Gangguan <span class="text-rose-500">*</span></label>
                    <input id="title" name="title" value="{{ old('title') }}" class="input" required maxlength="150" placeholder="Contoh: Kabel feeder putus tertimpa pohon">
                    <x-field-error name="title" />
                </div>
                <div>
                    <label class="label" for="area">Area Terdampak <span class="text-rose-500">*</span></label>
                    <input id="area" name="area" value="{{ old('area') }}" class="input" required maxlength="150" placeholder="Contoh: ODP SKM-03, Perum Sukamaju">
                    <x-field-error name="area" />
                </div>
                <div>
                    <label class="label" for="estimated_resolved_at">Estimasi Selesai</label>
                    <input id="estimated_resolved_at" type="datetime-local" name="estimated_resolved_at" value="{{ old('estimated_resolved_at') }}" class="input">
                    <x-field-error name="estimated_resolved_at" />
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="cause">Dugaan Penyebab</label>
                    <textarea id="cause" name="cause" rows="3" class="input" maxlength="2000" placeholder="Contoh: Banyak pelanggan LOS merah sejak 14.00, semua dari ODP yang sama">{{ old('cause') }}</textarea>
                    <x-field-error name="cause" />
                </div>
                <div class="sm:col-span-2">
                    <span class="label">Tautkan Tiket Pelanggan yang Terdampak</span>
                    <p class="mb-2 text-xs text-slate-500">Tiket yang dicentang pindah ke status <b>Gangguan Massal</b> dan keluar dari antrian Helpdesk.</p>
                    <x-ticket-picker :tickets="$linkableTickets" />
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-2">
                <a href="{{ route('incidents.index') }}" class="btn-secondary">Batal</a>
                <button class="btn-danger"><i data-lucide="radio-tower" class="h-4 w-4"></i>Buat Gamas</button>
            </div>
        </form>
    </div>
</x-layouts.app>
