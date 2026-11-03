{{-- Each line picks a crew member's warehouse, then only what that warehouse holds. --}}
<div class="space-y-2 rounded-lg border border-slate-200 p-3">
    <div class="flex items-center justify-between gap-2">
        <span class="flex items-center gap-1 text-sm font-semibold text-slate-700"><i data-lucide="package" class="h-4 w-4"></i>Material Terpakai</span>
        <button type="button" @click="add()" class="btn-secondary px-2 py-1 text-xs">+ Tambah</button>
    </div>
    <template x-for="(line, i) in lines" :key="i">
        <div class="grid grid-cols-[1fr_4.5rem_auto] gap-2 border-t border-slate-100 pt-2 first:border-0 first:pt-0">
            <select :name="`materials[${i}][technician_id]`" x-model="line.technician_id" @change="line.code = ''; line.serial = ''" class="input col-span-3 text-sm" required>
                <option value="">Stok dari gudang teknisi...</option>
                <template x-for="crew in technicians" :key="crew.id">
                    <option :value="crew.id" :selected="String(crew.id) === String(line.technician_id)" x-text="technicianLabel(crew)"></option>
                </template>
            </select>
            <select :name="`materials[${i}][code]`" x-model="line.code" @change="line.serial = ''" class="input text-sm" required :disabled="! line.technician_id">
                <option value="" x-text="itemsOf(line).length ? 'Pilih barang' : 'Gudang ini kosong'"></option>
                <template x-for="item in itemsOf(line)" :key="item.code">
                    <option :value="item.code" :selected="item.code === line.code" x-text="itemLabel(item)"></option>
                </template>
            </select>
            <input x-show="! isSerial(line)" :name="`materials[${i}][quantity]`" x-model="line.quantity" type="number" min="1" class="input text-sm" :required="! isSerial(line)">
            <span x-show="isSerial(line)" class="input bg-slate-50 text-center text-sm text-slate-500">1 unit</span>
            <button type="button" @click="lines.splice(i, 1)" class="btn-secondary px-2 text-xs" title="Hapus">&times;</button>
            <template x-if="isSerial(line)">
                <select :name="`materials[${i}][serial]`" x-model="line.serial" class="input col-span-3 font-mono text-sm" required>
                    <option value="" x-text="unitsFor(line, i).length ? 'Pilih SN modem yang dipasang' : 'Tidak ada SN tersisa'"></option>
                    <template x-for="unit in unitsFor(line, i)" :key="unit.sn">
                        <option :value="unit.sn" :selected="unit.sn === line.serial" x-text="unit.mac ? `${unit.sn} · ${unit.mac}` : unit.sn"></option>
                    </template>
                </select>
            </template>
            <input :name="`materials[${i}][notes]`" x-model="line.notes" class="input col-span-3 text-xs" maxlength="255" placeholder="Keterangan (opsional), mis. modem lama ditarik">
        </div>
    </template>
    <p x-show="! lines.length" class="text-xs text-slate-400">
        {{ empty($crewStock) ? 'Teknisi belum memegang stok. Minta Warehouse mengirim barang dulu.' : 'Material diajukan ke Gudang; stok baru berkurang setelah di-ACC. Tidak ada material? Biarkan kosong.' }}
    </p>
    <x-field-error name="materials" />
</div>
