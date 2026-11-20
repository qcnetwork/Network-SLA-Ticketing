{{-- Item search for a stockForm: type a code, name or category, pick with the mouse or arrows + Enter. Posts the code as "item". --}}
<div class="relative" @click.outside="open = false">
    <input type="hidden" name="item" :value="item ? item.code : ''">

    <div x-show="item" x-cloak class="flex items-center gap-3 rounded-lg border border-indigo-200 bg-indigo-50/60 p-3">
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-white text-indigo-600 shadow-sm"><i data-lucide="package-check" class="h-4 w-4"></i></span>
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-slate-800" x-text="item?.name"></p>
            <p class="flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
                <span class="font-mono" x-text="item?.code"></span>
                <span x-text="item?.category"></span>
                <span x-text="`· stok ${item?.held ?? 0} ${item?.unit ?? ''}`"></span>
            </p>
        </div>
        <button type="button" @click="clear()" class="text-xs font-semibold text-indigo-700 hover:underline">Ganti</button>
    </div>

    <div x-show="! item" class="relative">
        <i data-lucide="search" class="pointer-events-none absolute top-2.5 left-3 h-4 w-4 text-slate-400"></i>
        <input x-ref="search" x-model="query" @input="onInput()" @focus="open = true" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)"
            @keydown.enter.prevent="pickActive()" @keydown.escape="open = false" class="input pl-9" autocomplete="off" spellcheck="false"
            placeholder="{{ $placeholder ?? 'Cari kode, nama, atau kategori barang' }}">
        <div x-show="open" x-cloak x-ref="list" class="absolute z-30 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
            <template x-for="(match, index) in matches" :key="match.code">
                <div>
                    <p x-show="startsGroup(index)" class="sticky top-0 bg-slate-50 px-3 py-1 text-[11px] font-semibold tracking-wide text-slate-500 uppercase" x-text="match.category"></p>
                    <button type="button" :data-index="index" @click="choose(match)" @mouseenter="active = index"
                        :class="index === active ? 'bg-indigo-50' : ''" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm">
                        <span class="min-w-0">
                            <span class="block truncate text-slate-800" x-text="match.name"></span>
                            <span class="font-mono text-xs text-slate-500" x-text="match.code"></span>
                        </span>
                        <span class="flex shrink-0 items-center gap-1.5 text-xs">
                            <span x-show="match.serial" class="badge border-violet-300 bg-violet-50 text-violet-700">SN</span>
                            <span x-show="match.loose" class="badge border-amber-300 bg-amber-50 text-amber-700" title="Stok lama dari sebelum barang ini pakai SN">Tanpa SN</span>
                            <span :class="match.held > 0 ? 'text-slate-600' : 'text-slate-400'" x-text="`${match.held} ${match.unit}`"></span>
                        </span>
                    </button>
                </div>
            </template>
            <p x-show="! matches.length" class="px-3 py-3 text-center text-sm text-slate-400">Barang tidak ditemukan</p>
        </div>
    </div>
</div>
