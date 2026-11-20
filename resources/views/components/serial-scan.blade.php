@props(['query' => [], 'withMac' => false, 'followItem' => false])
{{-- Scan box for SN-tracked units; posts serials[i][sn] (and serials[i][mac]). Works with a USB/Bluetooth scanner or typing + Enter.
     With follow-item, inside a stock form, the MAC scan follows the picked item's master setting (modems yes, dropcore rolls and poles no). --}}
<div x-data="serialScanner({ lookupUrl: @js(route('warehouses.lookup-serial')), query: @js($query), withMac: @js($withMac) })" class="space-y-2"
    @if ($followItem) x-effect="withMac = Boolean(item?.mac)" @endif>
    {{-- One unit per row: a scanned SN jumps to the MAC box on its own; Tab there seals SN + MAC and returns to SN for the next unit. --}}
    <div class="grid gap-2" :class="withMac && 'sm:grid-cols-2'">
        <div class="relative">
            <i data-lucide="scan-barcode" class="pointer-events-none absolute top-2.5 left-3 h-4 w-4 text-slate-400"></i>
            <input x-ref="sn" x-model="sn" @input="snInput($event)" @keydown.enter.prevent="scanSn()" @keydown.tab="tabSn($event)" :disabled="pending !== null"
                class="input pl-9 font-mono" :class="pending !== null && 'border-emerald-300 bg-emerald-50 text-emerald-800'"
                :placeholder="withMac ? '1. Scan SN' : 'Scan SN (ketik manual: Enter)'" autocomplete="off" autocapitalize="characters" spellcheck="false">
        </div>
        @if ($withMac || $followItem)
            <div x-show="withMac" x-cloak class="flex gap-2">
                <input x-ref="mac" x-model="mac" @keydown.enter.prevent @keydown.tab="tabMac($event)" @keydown.escape.prevent="cancelPending()"
                    :disabled="pending === null" class="input font-mono" placeholder="2. Scan MAC, lalu Tab" autocomplete="off" spellcheck="false">
                <button type="button" x-show="pending !== null" @click="cancelPending()" class="btn-secondary px-2 text-xs" title="Batalkan SN ini (Esc)">Batal</button>
            </div>
        @endif
    </div>
    @if ($withMac || $followItem)
        <p x-show="withMac" x-cloak class="text-xs text-slate-500">
            <span x-show="pending === null">1 unit = scan SN (otomatis pindah ke MAC), scan MAC, lalu <b>Tab</b> untuk lanjut ke unit berikutnya. Scan semua dulu, baru Simpan.</span>
            <span x-show="pending !== null">SN OK. Scan MAC-nya lalu tekan <b>Tab</b>. Tidak ada label MAC? Langsung Tab.</span>
        </p>
    @endif
    <p x-show="checking" x-cloak class="text-xs text-slate-500">Mengecek SN...</p>
    <p x-show="error" x-cloak x-text="error" class="rounded-lg bg-rose-50 p-2 text-xs font-medium text-rose-700"></p>

    <div x-show="units.length" x-cloak class="rounded-lg border border-slate-200">
        <div class="flex items-center justify-between border-b border-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600">
            <span x-text="`${units.length} unit discan`"></span>
            <button type="button" @click="clearAll()" class="text-rose-600 hover:underline">Hapus semua</button>
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
