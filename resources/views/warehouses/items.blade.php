<x-layouts.app title="Master Barang">
    <a href="{{ route('warehouses.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-700">
        <i data-lucide="arrow-left" class="h-4 w-4"></i>Kembali ke Gudang
    </a>
    <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Master Barang</h1>
            <p class="text-sm text-slate-500">Daftar kode barang gudang. Impor dari Excel berisi kolom KODE PRODUK dan NAMA PRODUK; kode yang sudah ada diperbarui namanya.</p>
        </div>
        <form method="GET" action="{{ route('items.index') }}" class="flex gap-2">
            <input type="search" name="q" value="{{ $search }}" class="input md:w-72" placeholder="Cari kode atau nama barang">
            <button class="btn-secondary"><i data-lucide="search" class="h-4 w-4"></i>Cari</button>
        </form>
    </div>

    <div class="mb-6 grid gap-4 md:grid-cols-2">
        <form method="POST" action="{{ route('items.import') }}" enctype="multipart/form-data" class="card space-y-3 p-5">
            @csrf
            <h3 class="flex items-center gap-2 font-semibold"><i data-lucide="file-spreadsheet" class="h-4 w-4 text-emerald-600"></i>Impor dari Excel</h3>
            <input type="file" name="file" accept=".xlsx,.xls,.csv" class="input" required>
            <x-field-error name="file" />
            <button class="btn-secondary w-full"><i data-lucide="upload" class="h-4 w-4"></i>Impor</button>
        </form>

        <form method="POST" action="{{ route('items.store') }}" class="card space-y-3 p-5">
            @csrf
            <h3 class="flex items-center gap-2 font-semibold"><i data-lucide="plus-circle" class="h-4 w-4 text-indigo-600"></i>Tambah Barang</h3>
            <div class="grid grid-cols-3 gap-2">
                <input name="code" class="input" required maxlength="30" placeholder="Kode" value="{{ old('code') }}">
                <input name="name" class="input col-span-2" required maxlength="150" placeholder="Nama barang" value="{{ old('name') }}">
            </div>
            <input name="unit" class="input" required maxlength="20" value="{{ old('unit', 'pcs') }}" placeholder="Satuan (pcs, meter, roll)">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="tracks_serial" value="1" @checked(old('tracks_serial'))>Lacak per SN (modem / perangkat)</label>
            <x-field-error name="code" />
            <x-field-error name="name" />
            <button class="btn-primary w-full"><i data-lucide="save" class="h-4 w-4"></i>Simpan</button>
        </form>
    </div>

    <section class="card overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase">
                <tr>
                    <th class="px-4 py-3">Kode</th>
                    <th class="px-4 py-3">Nama Barang</th>
                    <th class="px-4 py-3">Satuan</th>
                    <th class="px-4 py-3 text-right">Total Stok</th>
                    <th class="px-4 py-3">Pakai SN</th>
                    <th class="px-4 py-3">Aktif</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($items as $item)
                    <tr x-data="{ editing: false }">
                        <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ $item->code }}</td>
                        <td class="px-4 py-2 text-slate-800">
                            <span x-show="! editing">{{ $item->name }}</span>
                            <form x-show="editing" x-cloak method="POST" action="{{ route('items.update', $item) }}" class="flex flex-wrap items-center gap-2">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="code" value="{{ $item->code }}">
                                <input name="name" value="{{ $item->name }}" class="input py-1 text-sm" required maxlength="150">
                                <input name="unit" value="{{ $item->unit }}" class="input w-24 py-1 text-sm" required maxlength="20">
                                <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="tracks_serial" value="1" @checked($item->tracks_serial)>Pakai SN</label>
                                <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="active" value="1" @checked($item->active)>Aktif</label>
                                <button class="btn-primary px-3 py-1 text-xs">Simpan</button>
                            </form>
                        </td>
                        <td class="px-4 py-2 text-slate-600">{{ $item->unit }}</td>
                        <td class="px-4 py-2 text-right font-semibold">{{ (int) $item->stocks_sum_quantity }}</td>
                        <td class="px-4 py-2">{{ $item->tracks_serial ? 'Ya' : '-' }}</td>
                        <td class="px-4 py-2">{{ $item->active ? 'Ya' : 'Tidak' }}</td>
                        <td class="px-4 py-2 text-right">
                            <button type="button" x-show="! editing" @click="editing = true" class="btn-secondary px-3 py-1 text-xs"><i data-lucide="pencil" class="h-3.5 w-3.5"></i>Ubah</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-slate-400">{{ $search !== '' ? 'Tidak ada barang yang cocok' : 'Belum ada barang. Impor dari Excel untuk memulai.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $items->links() }}</div>
</x-layouts.app>
