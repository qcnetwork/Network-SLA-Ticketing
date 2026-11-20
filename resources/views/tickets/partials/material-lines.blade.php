{{-- Material grouped by whose stock it comes from: one block per warehouse, holding every item taken from it. --}}
<div class="space-y-3 rounded-lg border border-slate-200 p-3">
    <div class="flex items-center justify-between gap-2">
        <span class="flex items-center gap-1 text-sm font-semibold text-slate-700"><i data-lucide="package" class="h-4 w-4"></i>Material Terpakai</span>
        <button type="button" x-show="! groups.length" @click="addGroup()" class="btn-secondary px-2 py-1 text-xs">+ Tambah</button>
    </div>

    <template x-for="(group, g) in groups" :key="group.key">
        <div class="space-y-2 rounded-lg border border-slate-200 bg-slate-50/60 p-2.5">
            <div class="flex items-center gap-2">
                <div class="relative flex-1" @click.outside="closeTech(group)">
                    <input type="text" x-model="group.query" :data-tech-search="group.key" autocomplete="off" spellcheck="false"
                        @focus="openTech(group)" @click="openTech(group)" @input="openTech(group)"
                        @keydown.arrow-down.prevent="moveTech(group, 1)" @keydown.arrow-up.prevent="moveTech(group, -1)"
                        @keydown.enter="acceptTech(group, $event)" @keydown.escape.prevent="closeTech(group); $event.target.blur()" @keydown.tab="closeTech(group)"
                        :placeholder="group.technician_id ? `Stok ${technicianName(group)}` : 'Cari stok siapa... (ketik nama)'"
                        class="input w-full text-sm" :class="group.technician_id && 'font-medium placeholder:text-slate-800'">
                    <ul x-show="group.open" x-cloak class="absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg">
                        <template x-for="(crew, c) in techMatches(group)" :key="crew.id">
                            <li @mousedown.prevent="pickTechnician(group, crew)" @mouseenter="group.active = c"
                                :class="c === group.active ? 'bg-indigo-50 text-indigo-800' : 'text-slate-700'"
                                class="flex cursor-pointer items-center justify-between gap-2 px-3 py-1.5">
                                <span>Stok <b x-text="technicianLabel(crew)"></b></span>
                                <span class="shrink-0 text-xs text-slate-400" x-text="`${stockCount(crew)} jenis barang`"></span>
                            </li>
                        </template>
                        <li x-show="! techMatches(group).length" class="px-3 py-2 text-xs text-slate-400">Tidak ada stok teknisi yang cocok</li>
                    </ul>
                </div>
                <button type="button" @click="removeGroup(g)" class="btn-secondary px-2 text-xs" title="Hapus semua material dari stok ini">&times;</button>
            </div>

            <template x-for="(line, l) in group.lines" :key="line.key">
                <div class="grid grid-cols-[1fr_4.5rem_auto] gap-2 rounded-md border border-slate-200 bg-white p-2">
                    <input type="hidden" :name="`materials[${rowIndex(g, l)}][technician_id]`" :value="group.technician_id">
                    <select :name="`materials[${rowIndex(g, l)}][code]`" x-model="line.code" @change="line.serial = ''" class="input text-sm" required :disabled="! group.technician_id">
                        <option value="" x-text="! group.technician_id ? 'Pilih stok gudang dulu' : (itemsOf(group).length ? 'Pilih barang' : 'Gudang ini kosong')"></option>
                        <template x-for="item in itemsOf(group)" :key="item.code">
                            <option :value="item.code" :selected="item.code === line.code" x-text="itemLabel(item)"></option>
                        </template>
                    </select>
                    <input x-show="! isSerial(group, line)" :name="`materials[${rowIndex(g, l)}][quantity]`" x-model="line.quantity" type="number" min="1" class="input text-sm" :required="! isSerial(group, line)">
                    <span x-show="isSerial(group, line)" class="input bg-slate-50 text-center text-sm text-slate-500">1 unit</span>
                    <button type="button" @click="removeLine(g, l)" class="btn-secondary px-2 text-xs" title="Hapus barang ini">&times;</button>
                    <template x-if="isSerial(group, line)">
                        <select :name="`materials[${rowIndex(g, l)}][serial]`" x-model="line.serial" class="input col-span-3 font-mono text-sm" required>
                            <option value="" x-text="loading[group.technician_id] ? 'Memuat SN...' : (unitsFor(group, line).length ? 'Pilih SN yang dipasang' : 'Tidak ada SN tersisa')"></option>
                            <template x-for="unit in unitsFor(group, line)" :key="unit.sn">
                                <option :value="unit.sn" :selected="unit.sn === line.serial" x-text="unit.mac ? `${unit.sn} · ${unit.mac}` : unit.sn"></option>
                            </template>
                        </select>
                    </template>
                    <input :name="`materials[${rowIndex(g, l)}][notes]`" x-model="line.notes" class="input col-span-3 text-xs" maxlength="255" placeholder="Keterangan (opsional), mis. modem lama ditarik">
                </div>
            </template>

            <button type="button" @click="addLine(group)" :disabled="! group.technician_id" class="w-full rounded-md border border-dashed border-slate-300 py-1.5 text-xs font-medium text-indigo-700 hover:bg-white disabled:text-slate-400"
                x-text="group.technician_id ? `+ Tambah barang dari Stok ${technicianName(group)}` : '+ Tambah barang'"></button>
        </div>
    </template>

    <button type="button" x-show="groups.length && freeTechnicians().length" x-cloak @click="addGroup()" class="btn-secondary w-full py-1.5 text-xs">
        <i data-lucide="plus" class="h-3.5 w-3.5"></i>Ambil dari stok teknisi lain
    </button>

    <p x-show="! groups.length" class="text-xs text-slate-400">
        {{ empty($crewStock) ? 'Teknisi belum memegang stok. Minta Warehouse mengirim barang dulu.' : 'Material diajukan ke Gudang; stok baru berkurang setelah di-ACC. Tidak ada material? Biarkan kosong.' }}
    </p>
    <x-field-error name="materials" />
</div>
