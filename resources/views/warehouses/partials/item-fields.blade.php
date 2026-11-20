{{-- The fields of one item, for the new-item form ($item null) and each item's edit form. Picking a category
     suggests its jenis, sets the sifat a new item starts with and the code it would get; a category that
     requires SN rules out "dihitung jumlah". --}}
@php
    $item ??= null;
    $isNew = $item === null;
    $old = fn (string $key, $default = null) => $isNew && old('_form') === 'item' ? old($key, $default) : $default;
    $size = $isNew ? '' : 'text-sm';
@endphp
<div class="grid gap-3 sm:grid-cols-6"
    x-data="{
        meta: @js($categoryMeta),
        category: @js((string) ($isNew ? $old('item_category_id', $preselectCategory) : $item->item_category_id)),
        identifier: @js($isNew ? $old('identifier', 'JUMLAH') : $item->identifier()),
        kind: @js($isNew ? $old('kind', '') : $item->kind->value),
        get info() { return this.meta[this.category] || null },
        get serialRequired() { return Boolean(this.info?.requires_serial) },
        pickCategory() {
            if (@js($isNew) && this.info) { this.kind = this.info.kind; this.identifier = this.info.identifier }
            if (this.serialRequired && this.identifier === 'JUMLAH') { this.identifier = this.info.identifier }
        },
    }" x-init="if (! kind && info) kind = info.kind">
    <div class="sm:col-span-2">
        <label class="label {{ $size ? 'text-xs' : '' }}">Kategori</label>
        <select name="item_category_id" x-model="category" @change="pickCategory()" class="input {{ $size }}">
            <option value="">Tanpa kategori</option>
            @foreach ($categories as $row)
                <option value="{{ $row->id }}">{{ $row->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="sm:col-span-2">
        <label class="label {{ $size ? 'text-xs' : '' }}">Jenis <span class="font-normal text-slate-400">(mis. ONT GPON)</span></label>
        <input name="variant" :list="`variants-${category}`" maxlength="60" class="input {{ $size }}" value="{{ $isNew ? $old('variant') : $item->variant }}" placeholder="Kosongkan bila tidak ada">
    </div>
    <div class="sm:col-span-2">
        <label class="label {{ $size ? 'text-xs' : '' }}">Merk</label>
        <input name="brand" list="brand_options" maxlength="60" class="input {{ $size }}" value="{{ $isNew ? $old('brand') : $item->brand }}" placeholder="mis. Fiberhome">
    </div>
    <div class="sm:col-span-4">
        <label class="label {{ $size ? 'text-xs' : '' }}">Nama / model barang</label>
        <input name="name" class="input {{ $size }}" required maxlength="150" value="{{ $isNew ? $old('name') : $item->name }}" placeholder="mis. MODEM FIBERHOME HG6145D2">
    </div>
    <div class="sm:col-span-2">
        <label class="label {{ $size ? 'text-xs' : '' }}">Kode barang</label>
        @if ($isNew)
            <input name="code" class="input font-mono uppercase" maxlength="30" value="{{ $old('code') }}" :placeholder="info?.next_code ? `Otomatis: ${info.next_code}` : 'mis. SWT001'">
        @else
            <input type="hidden" name="code" value="{{ $item->code }}">
            <p class="input bg-slate-100 font-mono {{ $size }} text-slate-600">{{ $item->code }}</p>
        @endif
    </div>
    <div class="sm:col-span-2">
        <label class="label {{ $size ? 'text-xs' : '' }}">Sifat barang</label>
        <select name="kind" x-model="kind" class="input {{ $size }}">
            @foreach (App\Enums\ItemKind::cases() as $case)
                <option value="{{ $case->value }}" title="{{ $case->hint() }}">{{ $case->label() }}</option>
            @endforeach
        </select>
    </div>
    <div class="sm:col-span-2">
        <label class="label {{ $size ? 'text-xs' : '' }}">Penanda unit</label>
        <select name="identifier" x-model="identifier" class="input {{ $size }}">
            <option value="JUMLAH" :disabled="serialRequired">Dihitung jumlah (tanpa SN)</option>
            <option value="SN">SN / nomor seri</option>
            <option value="SN_MAC">SN + MAC</option>
        </select>
        <p x-show="serialRequired" x-cloak class="mt-1 text-[11px] text-violet-700">Kategori ini wajib SN</p>
    </div>
    <div class="sm:col-span-1">
        <label class="label {{ $size ? 'text-xs' : '' }}">Satuan</label>
        <input name="unit" class="input {{ $size }}" required maxlength="20" list="unit_options" value="{{ $isNew ? $old('unit', 'pcs') : $item->unit }}">
    </div>
    <div class="sm:col-span-1">
        <label class="label {{ $size ? 'text-xs' : '' }}">Stok min</label>
        <input name="min_stock" type="number" min="0" class="input {{ $size }}" value="{{ $isNew ? $old('min_stock', 0) : $item->min_stock }}" title="0 = tanpa peringatan stok menipis">
    </div>
    <p class="text-xs text-slate-500 sm:col-span-6">
        <span x-show="identifier === 'JUMLAH'">Stok dicatat per jumlah.</span>
        <span x-show="identifier === 'SN'" x-cloak>Setiap unit discan SN / nomor seri (stiker sendiri juga bisa, mis. untuk tiang atau roll kabel).</span>
        <span x-show="identifier === 'SN_MAC'" x-cloak>Setiap unit discan SN lalu label MAC-nya (modem, router, switch).</span>
    </p>
</div>
