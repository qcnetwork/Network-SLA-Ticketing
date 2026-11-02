@props(['query' => [], 'withMac' => false])
{{-- Scan box for SN-tracked units; posts serials[i][sn] (and serials[i][mac]). Works with a USB/Bluetooth scanner or typing + Enter. --}}
<div x-data="serialScanner({ lookupUrl: @js(route('warehouses.lookup-serial')), query: @js($query), withMac: @js($withMac) })" class="space-y-2">
    <div class="relative">
        <i data-lucide="scan-barcode" class="pointer-events-none absolute top-2.5 left-3 h-4 w-4 text-slate-400"></i>
        <input x-ref="sn" x-model="sn" @keydown.enter.prevent="scanSn()" :disabled="pending !== null" class="input pl-9 font-mono"
            placeholder="Scan / ketik SN lalu Enter" autocomplete="off" autocapitalize="characters" spellcheck="false">
    </div>
    @if ($withMac)
        <div x-show="pending !== null" x-cloak class="space-y-1 rounded-lg border border-indigo-200 bg-indigo-50 p-2">
            <p class="text-xs text-indigo-800">SN <span class="font-mono font-semibold" x-text="pending"></span> — sekarang scan MAC-nya</p>
            <div class="flex gap-2">
                <input x-ref="mac" x-model="mac" @keydown.enter.prevent="scanMac()" @keydown.escape.prevent="cancelPending()" class="input font-mono text-sm"
                    placeholder="Scan MAC lalu Enter (kosongkan jika tidak ada)" autocomplete="off" spellcheck="false">
                <button type="button" @click="cancelPending()" class="btn-secondary px-2 text-xs">Batal</button>
            </div>
        </div>
    @endif
    <p x-show="checking" x-cloak class="text-xs text-slate-500">Mengecek SN...</p>
    <p x-show="error" x-cloak x-text="error" class="rounded-lg bg-rose-50 p-2 text-xs font-medium text-rose-700"></p>

    <div x-show="units.length" x-cloak class="rounded-lg border border-slate-200">
        <div class="flex items-center justify-between border-b border-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600">
            <span x-text="`${units.length} unit discan`"></span>
            <button type="button" @click="units = []; focusSn()" class="text-rose-600 hover:underline">Hapus semua</button>
        </div>
        <ul class="max-h-60 divide-y divide-slate-100 overflow-y-auto text-xs">
            <template x-for="(unit, i) in units" :key="unit.sn">
                <li class="flex items-center justify-between gap-2 px-3 py-1.5">
                    <span>
                        <span class="font-mono font-semibold text-slate-800" x-text="unit.sn"></span>
                        <span x-show="unit.mac" class="font-mono text-slate-500" x-text="`· ${unit.mac}`"></span>
                        <span x-show="unit.item" class="block text-slate-500" x-text="unit.item"></span>
                    </span>
                    <button type="button" @click="remove(i)" class="px-1 text-slate-400 hover:text-rose-600" title="Hapus">&times;</button>
                    <input type="hidden" :name="`serials[${i}][sn]`" :value="unit.sn">
                    <input type="hidden" :name="`serials[${i}][mac]`" :value="unit.mac">
                </li>
            </template>
        </ul>
    </div>
    <x-field-error name="serials" />
</div>
