<x-layouts.app title="Master Barang">
    <a href="{{ route('warehouses.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-700">
        <i data-lucide="arrow-left" class="h-4 w-4"></i>Kembali ke Gudang
    </a>
    <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div class="flex items-center gap-3">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-indigo-600 text-white shadow-sm"><i data-lucide="boxes" class="h-6 w-6"></i></span>
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Master Barang</h1>
                <p class="text-sm text-slate-500">Barang dikelompokkan kategori → jenis → merk, dan setiap model punya kode sendiri. Penanda unit menentukan apakah barang dihitung jumlah, discan SN, atau SN + MAC.</p>
            </div>
        </div>
        <form method="GET" action="{{ route('items.index') }}" class="flex gap-2">
            @if ($category !== '')
                <input type="hidden" name="kategori" value="{{ $category }}">
            @endif
            @if ($kind !== '')
                <input type="hidden" name="sifat" value="{{ $kind }}">
            @endif
            <input type="search" name="q" value="{{ $search }}" class="input md:w-72" placeholder="Cari kode, nama, jenis, atau merk">
            <button class="btn-secondary"><i data-lucide="search" class="h-4 w-4"></i>Cari</button>
        </form>
    </div>

    {{-- Suggestions shared by every item form: units, brands, and each category's jenis. --}}
    <datalist id="unit_options"><option value="pcs"><option value="unit"><option value="meter"><option value="roll"><option value="batang"><option value="box"><option value="pack"></datalist>
    <datalist id="brand_options">
        @foreach ($brands as $brand)
            <option value="{{ $brand }}">
        @endforeach
    </datalist>
    @foreach ($variantsByCategory as $categoryId => $variants)
        <datalist id="variants-{{ $categoryId }}">
            @foreach ($variants as $variant)
                <option value="{{ $variant }}">
            @endforeach
        </datalist>
    @endforeach

    <div class="grid gap-6 lg:grid-cols-4">
        {{-- Categories: filter the list, and manage them in place. --}}
        <aside class="space-y-4" x-data="{ manage: @js($errors->has('category') || $errors->has('icon') || ($errors->has('name') && old('_form') === 'category')) }">
            <section class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-slate-800"><i data-lucide="layers" class="h-4 w-4"></i>Kategori</h2>
                    <button type="button" @click="manage = ! manage" class="text-xs font-semibold text-indigo-700 hover:underline" x-text="manage ? 'Selesai' : 'Kelola'"></button>
                </div>
                @php
                    $link = fn (?string $value) => route('items.index', array_filter(['kategori' => $value, 'sifat' => $kind !== '' ? $kind : null, 'q' => $search !== '' ? $search : null]));
                @endphp
                <nav class="p-2 text-sm">
                    <a href="{{ $link(null) }}" class="flex items-center justify-between rounded-lg px-3 py-2 {{ $category === '' ? 'bg-indigo-50 font-semibold text-indigo-700' : 'text-slate-600 hover:bg-slate-50' }}">
                        <span class="flex items-center gap-2"><i data-lucide="package" class="h-4 w-4"></i>Semua barang</span>
                        <span class="text-xs">{{ $categories->sum('items_count') + $uncategorised }}</span>
                    </a>
                    @foreach ($categories as $row)
                        <div x-data="{ editing: false }">
                            <div class="flex items-center gap-1">
                                <a href="{{ $link((string) $row->id) }}" class="flex min-w-0 flex-1 items-center justify-between gap-2 rounded-lg px-3 py-2 {{ $category === (string) $row->id ? 'bg-indigo-50 font-semibold text-indigo-700' : 'text-slate-600 hover:bg-slate-50' }}">
                                    <span class="flex min-w-0 items-center gap-2">
                                        <i data-lucide="{{ $row->icon }}" class="h-4 w-4 shrink-0"></i>
                                        <span class="truncate">{{ $row->name }}</span>
                                        @if ($row->requires_serial)
                                            <span class="badge border-violet-300 bg-violet-50 px-1.5 text-[10px] text-violet-700">SN</span>
                                        @endif
                                    </span>
                                    <span class="text-xs">{{ $row->items_count }}</span>
                                </a>
                                <button type="button" x-show="manage" x-cloak @click="editing = ! editing" class="rounded p-1.5 text-slate-400 hover:bg-slate-100 hover:text-indigo-700" title="Ubah kategori"><i data-lucide="pencil" class="h-3.5 w-3.5"></i></button>
                            </div>
                            <div x-show="manage && editing" x-cloak class="mx-1 mb-2 space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <form method="POST" action="{{ route('item-categories.update', $row) }}" class="space-y-2">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="_form" value="category">
                                    <input name="name" value="{{ $row->name }}" class="input text-sm" required maxlength="80">
                                    @include('warehouses.partials.icon-select', ['selected' => $row->icon])
                                    <div class="grid grid-cols-2 gap-2">
                                        <input name="code_prefix" value="{{ $row->code_prefix }}" class="input font-mono text-sm uppercase" maxlength="10" placeholder="Awalan kode" title="Awalan kode barang baru, mis. MDM">
                                        <select name="kind" class="input text-sm" title="Sifat barang baru di kategori ini">
                                            @foreach (App\Enums\ItemKind::cases() as $case)
                                                <option value="{{ $case->value }}" @selected($row->kind === $case)>{{ $case->label() }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <label class="flex items-center gap-2 text-xs text-slate-700"><input type="checkbox" name="requires_serial" value="1" @checked($row->requires_serial) class="rounded border-slate-300">Wajib SN / barcode per unit</label>
                                    <button class="btn-primary w-full py-1.5 text-xs"><i data-lucide="save" class="h-3.5 w-3.5"></i>Simpan</button>
                                </form>
                                <form method="POST" action="{{ route('item-categories.destroy', $row) }}" data-confirm="Hapus kategori {{ $row->name }}?">
                                    @csrf
                                    @method('DELETE')
                                    <button class="flex w-full items-center justify-center gap-1 text-xs font-medium text-rose-600 hover:underline" @disabled($row->items_count > 0) title="{{ $row->items_count > 0 ? 'Kategori masih berisi barang' : '' }}">
                                        <i data-lucide="trash-2" class="h-3.5 w-3.5"></i>Hapus kategori
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                    @if ($uncategorised > 0 || $category === 'none')
                        <a href="{{ $link('none') }}" class="flex items-center justify-between rounded-lg px-3 py-2 {{ $category === 'none' ? 'bg-amber-50 font-semibold text-amber-700' : 'text-amber-700 hover:bg-amber-50' }}">
                            <span class="flex items-center gap-2"><i data-lucide="circle-alert" class="h-4 w-4"></i>Tanpa kategori</span>
                            <span class="text-xs">{{ $uncategorised }}</span>
                        </a>
                    @endif
                </nav>

                <form x-show="manage" x-cloak method="POST" action="{{ route('item-categories.store') }}" class="space-y-2 border-t border-slate-100 p-4">
                    @csrf
                    <input type="hidden" name="_form" value="category">
                    <p class="text-xs font-semibold text-slate-600">Kategori baru</p>
                    <input name="name" class="input text-sm" required maxlength="80" placeholder="mis. CCTV" value="{{ old('_form') === 'category' ? old('name') : '' }}">
                    @include('warehouses.partials.icon-select', ['selected' => 'package'])
                    <div class="grid grid-cols-2 gap-2">
                        <input name="code_prefix" class="input font-mono text-sm uppercase" maxlength="10" placeholder="Awalan kode" title="Awalan kode barang baru, mis. TNG">
                        <select name="kind" class="input text-sm" title="Sifat barang baru di kategori ini">
                            @foreach (App\Enums\ItemKind::cases() as $case)
                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <label class="flex items-center gap-2 text-xs text-slate-700"><input type="checkbox" name="requires_serial" value="1" class="rounded border-slate-300">Wajib SN / barcode per unit</label>
                    <button class="btn-secondary w-full py-1.5 text-xs"><i data-lucide="plus" class="h-3.5 w-3.5"></i>Tambah Kategori</button>
                </form>
                @if ($errors->has('category') || old('_form') === 'category')
                    <div class="px-4 pb-4">
                        <x-field-error name="category" />
                        <x-field-error name="name" />
                        <x-field-error name="icon" />
                        <x-field-error name="code_prefix" />
                    </div>
                @endif
            </section>

            <form method="POST" action="{{ route('items.import') }}" enctype="multipart/form-data" class="card space-y-3 p-4">
                @csrf
                <h3 class="flex items-center gap-2 text-sm font-semibold"><i data-lucide="file-spreadsheet" class="h-4 w-4 text-emerald-600"></i>Impor dari Excel</h3>
                <p class="text-xs text-slate-500">Kolom KODE PRODUK dan NAMA PRODUK. Barang baru otomatis masuk kategori yang cocok dari nama/kodenya.</p>
                <input type="file" name="file" accept=".xlsx,.xls,.csv" class="input text-sm" required>
                <x-field-error name="file" />
                <button class="btn-secondary w-full"><i data-lucide="upload" class="h-4 w-4"></i>Impor</button>
            </form>
        </aside>

        <div class="space-y-6 lg:col-span-3">
            {{-- New item: category first, then jenis, merk and the model; the code fills itself from the category. --}}
            <details class="card group" @if ($errors->hasAny(['code', 'unit', 'item_category_id', 'min_stock', 'variant', 'brand', 'kind', 'identifier']) || old('_form') === 'item') open @endif>
                <summary class="flex cursor-pointer list-none items-center justify-between p-5">
                    <span class="flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="plus-circle" class="h-4 w-4 text-indigo-600"></i>Tambah Barang</span>
                    <i data-lucide="chevron-down" class="h-4 w-4 text-slate-400 transition group-open:rotate-180"></i>
                </summary>
                <form method="POST" action="{{ route('items.store') }}" class="space-y-3 border-t border-slate-100 p-5">
                    @csrf
                    <input type="hidden" name="_form" value="item">
                    @include('warehouses.partials.item-fields', ['item' => null, 'preselectCategory' => ctype_digit($category) ? $category : ''])
                    <div>
                        <x-field-error name="code" />
                        <x-field-error name="item_category_id" />
                        <x-field-error name="min_stock" />
                        <x-field-error name="variant" />
                        <x-field-error name="brand" />
                        @if (old('_form') === 'item')
                            <x-field-error name="name" />
                        @endif
                    </div>
                    <div class="sm:flex sm:justify-end">
                        <button class="btn-primary w-full sm:w-auto"><i data-lucide="save" class="h-4 w-4"></i>Simpan Barang</button>
                    </div>
                </form>
            </details>

            {{-- Filter by sifat. --}}
            @php
                $kindLink = fn (?string $value) => route('items.index', array_filter(['kategori' => $category !== '' ? $category : null, 'sifat' => $value, 'q' => $search !== '' ? $search : null]));
            @endphp
            <div class="flex flex-wrap gap-2 text-xs">
                <a href="{{ $kindLink(null) }}" class="rounded-full border px-3 py-1 font-semibold {{ $kind === '' ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-indigo-300' }}">Semua sifat</a>
                @foreach (App\Enums\ItemKind::cases() as $case)
                    <a href="{{ $kindLink($case->value) }}" title="{{ $case->hint() }}" class="rounded-full border px-3 py-1 font-semibold {{ $kind === $case->value ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-indigo-300' }}">{{ $case->label() }}</a>
                @endforeach
            </div>

            <x-field-error name="tracks_serial" />
            <x-field-error name="send_as_item_id" />
            <x-field-error name="send_ratio" />
            <x-field-error name="item" />

            <section class="card overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">Barang</th>
                            <th class="px-4 py-3">Kategori</th>
                            <th class="px-4 py-3 text-right">Total Stok</th>
                            <th class="px-4 py-3 text-right">Min</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    @forelse ($items as $item)
                        <tbody x-data="{ editing: false }" class="border-b border-slate-100">
                            <tr class="{{ $item->active ? '' : 'bg-slate-50 text-slate-400' }}">
                                <td class="px-4 py-3">
                                    <p class="font-medium {{ $item->active ? 'text-slate-800' : '' }}">{{ $item->name }}</p>
                                    <p class="flex flex-wrap gap-x-2 text-xs text-slate-500">
                                        <span class="font-mono">{{ $item->code }}</span>
                                        @if ($item->variant || $item->brand)
                                            <span>{{ collect([$item->variant, $item->brand])->filter()->implode(' · ') }}</span>
                                        @endif
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($item->category)
                                        <span class="inline-flex items-center gap-1.5 text-xs text-slate-600"><i data-lucide="{{ $item->category->icon }}" class="h-3.5 w-3.5 text-slate-400"></i>{{ $item->category->name }}</span>
                                    @else
                                        <span class="text-xs text-amber-600">Tanpa kategori</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap"><span class="font-semibold">{{ (int) $item->stocks_sum_quantity }}</span> <span class="text-xs text-slate-500">{{ $item->unit }}</span></td>
                                <td class="px-4 py-3 text-right text-slate-500">{{ $item->min_stock ?: '-' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        <span class="badge {{ $item->kind->badgeClasses() }}">{{ $item->kind->label() }}</span>
                                        @if ($item->tracks_serial)
                                            <span class="badge border-violet-300 bg-violet-50 text-violet-700">{{ $item->scans_mac ? 'SN + MAC' : 'SN' }}</span>
                                        @endif
                                        @if ($item->conversionLabel())
                                            <span class="badge border-indigo-300 bg-indigo-50 text-indigo-700" title="Dikirim ke teknisi sebagai {{ $item->sendAs->name }}">{{ $item->conversionLabel() }}</span>
                                        @endif
                                        @unless ($item->active)
                                            <span class="badge border-slate-300 bg-slate-100 text-slate-500">Nonaktif</span>
                                        @endunless
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button" @click="editing = ! editing" class="btn-secondary px-3 py-1 text-xs"><i data-lucide="pencil" class="h-3.5 w-3.5"></i>Ubah</button>
                                </td>
                            </tr>
                            <tr x-show="editing" x-cloak>
                                <td colspan="6" class="bg-slate-50 px-4 py-4">
                                    <form method="POST" action="{{ route('items.update', $item) }}" class="grid gap-3 sm:grid-cols-6">
                                        @csrf
                                        @method('PUT')
                                        <div class="sm:col-span-6">
                                            @include('warehouses.partials.item-fields', ['item' => $item])
                                        </div>
                                        <label class="flex items-center gap-1.5 text-xs sm:col-span-6"><input type="checkbox" name="active" value="1" @checked($item->active) class="rounded border-slate-300">Aktif (muncul di pilihan barang)</label>
                                        {{-- A roll of dropcore can reach the technician as meters of the per-meter item. --}}
                                        <div class="grid gap-3 rounded-lg border border-indigo-100 bg-white p-3 sm:col-span-6 sm:grid-cols-6">
                                            <p class="flex items-center gap-1.5 text-xs font-semibold text-indigo-700 sm:col-span-6"><i data-lucide="repeat" class="h-3.5 w-3.5"></i>Konversi saat dikirim ke teknisi</p>
                                            <div class="sm:col-span-4">
                                                <label class="label text-xs">Teknisi menerima barang</label>
                                                <select name="send_as_item_id" class="input text-sm">
                                                    <option value="">Tidak dikonversi (barang yang sama)</option>
                                                    @foreach ($conversionTargets as $target)
                                                        @continue($target->id === $item->id)
                                                        <option value="{{ $target->id }}" @selected($item->send_as_item_id === $target->id)>{{ $target->code }} · {{ $target->name }} ({{ $target->unit }})</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="sm:col-span-2">
                                                <label class="label text-xs">Isi per 1 {{ $item->unit }}</label>
                                                <input name="send_ratio" type="number" min="1" value="{{ $item->send_ratio }}" class="input text-sm" placeholder="mis. 1000">
                                            </div>
                                            <p class="text-xs text-slate-500 sm:col-span-6">Contoh: kirim 1 roll dropcore → gudang utama berkurang 1 roll, teknisi bertambah 1000 meter di barang dropcore per meter. Roll ber-SN tetap discan per roll saat dikirim. Rasio yang diubah hanya berlaku untuk kiriman berikutnya.</p>
                                        </div>
                                        <div class="flex items-end gap-2 sm:col-span-6 sm:justify-end">
                                            <button type="button" @click="editing = false" class="btn-secondary px-3 py-1.5 text-xs">Batal</button>
                                            <button class="btn-primary px-3 py-1.5 text-xs"><i data-lucide="save" class="h-3.5 w-3.5"></i>Simpan</button>
                                        </div>
                                    </form>
                                    <form method="POST" action="{{ route('items.destroy', $item) }}" data-confirm="Hapus barang {{ $item->code }} · {{ $item->name }}? Data barang ini akan hilang dari master." class="mt-3 flex justify-end border-t border-slate-200 pt-3">
                                        @csrf
                                        @method('DELETE')
                                        <button class="flex items-center gap-1 text-xs font-medium text-rose-600 hover:underline"><i data-lucide="trash-2" class="h-3.5 w-3.5"></i>Hapus barang</button>
                                    </form>
                                </td>
                            </tr>
                        </tbody>
                    @empty
                        <tbody>
                            <tr><td colspan="6" class="px-4 py-10 text-center text-slate-400">{{ $search !== '' || $category !== '' ? 'Tidak ada barang yang cocok' : 'Belum ada barang. Tambah atau impor dari Excel untuk memulai.' }}</td></tr>
                        </tbody>
                    @endforelse
                </table>
            </section>

            <div>{{ $items->links() }}</div>
        </div>
    </div>
</x-layouts.app>
