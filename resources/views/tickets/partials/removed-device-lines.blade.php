{{-- Repair resume: was anything taken off the customer? Must be answered. Each device is typed by hand (most
     customers have no record of what they hold yet); SN, MAC and condition are optional. Posts removed_any and
     removed_devices[n][name, serial, mac, quantity, condition, notes]. --}}
@php
    $blank = ['name' => '', 'serial' => '', 'mac' => '', 'quantity' => 1, 'condition' => 'TIDAK_TAHU', 'notes' => ''];
    $oldDevices = array_values(old('removed_devices', [])) ?: [$blank];
@endphp
<div x-data="{ answer: @js((string) old('removed_any', '')), devices: @js($oldDevices), blank: @js($blank) }" class="rounded-lg border border-slate-200 p-3">
    <p class="text-sm font-semibold text-slate-800">Ada alat yang dicabut dari pelanggan? <span class="text-rose-600">*</span></p>
    <p class="mb-2 text-xs text-slate-500">Mis. modem / router lama yang diganti. Alat cabutan wajib diserahkan ke Gudang paling lambat {{ App\Models\RemovedDevice::DAYS_TO_RETURN }} hari.</p>
    <div class="flex gap-2 text-sm">
        <label class="flex flex-1 cursor-pointer items-center justify-center gap-2 rounded-lg border px-3 py-2 font-medium" :class="answer === 'tidak' ? 'border-indigo-600 bg-indigo-50 text-indigo-700' : 'border-slate-200 text-slate-600'">
            <input type="radio" name="removed_any" value="tidak" x-model="answer" class="sr-only" required>Tidak ada
        </label>
        <label class="flex flex-1 cursor-pointer items-center justify-center gap-2 rounded-lg border px-3 py-2 font-medium" :class="answer === 'ya' ? 'border-amber-500 bg-amber-50 text-amber-800' : 'border-slate-200 text-slate-600'">
            <input type="radio" name="removed_any" value="ya" x-model="answer" class="sr-only">Ya, ada
        </label>
    </div>
    <x-field-error name="removed_any" />

    <template x-if="answer === 'ya'">
        <div class="mt-3 space-y-3">
            <template x-for="(device, index) in devices" :key="index">
                <div class="space-y-2 rounded-lg bg-slate-50 p-2.5">
                    <div class="flex items-center gap-2">
                        <input :name="`removed_devices[${index}][name]`" x-model="device.name" list="removed-device-names" class="input flex-1" required maxlength="150" placeholder="Jenis alat, mis. Modem ZTE F609, HTB, Adaptor 12V">
                        <button type="button" x-show="devices.length > 1" @click="devices.splice(index, 1)" class="shrink-0 text-xs font-semibold text-slate-400 hover:text-rose-600">Hapus</button>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <input :name="`removed_devices[${index}][serial]`" x-model="device.serial" class="input font-mono" maxlength="100" placeholder="SN (opsional)">
                        <input :name="`removed_devices[${index}][mac]`" x-model="device.mac" class="input font-mono" maxlength="30" placeholder="MAC (opsional)">
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <select :name="`removed_devices[${index}][condition]`" x-model="device.condition" class="input col-span-2">
                            @foreach (App\Models\RemovedDevice::FIELD_CONDITIONS as $value => $label)
                                <option value="{{ $value }}">Kondisi: {{ $label }}</option>
                            @endforeach
                        </select>
                        <input type="number" min="1" max="1000" :name="`removed_devices[${index}][quantity]`" x-model="device.quantity" class="input" title="Jumlah">
                    </div>
                    <input :name="`removed_devices[${index}][notes]`" x-model="device.notes" class="input" maxlength="255" placeholder="Catatan (opsional), mis. adaptor ikut dicabut">
                </div>
            </template>
            <button type="button" x-show="devices.length < 10" @click="devices.push({ ...blank })" class="btn-secondary w-full text-sm">
                + Tambah alat lain
            </button>
        </div>
    </template>
    <x-field-error name="removed_devices" />
    {{-- Suggestions only: anything can be typed. Modem types first, then common devices, then the item master
         (cable is left out: it is not taken back). --}}
    @php
        $deviceNames = collect($modemSuggestions ?? [])
            ->concat(['Router', 'Access Point', 'HTB', 'Adaptor', 'Switch', 'Splitter', 'ODP'])
            ->concat(App\Models\Item::query()->where('active', true)->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '!=', App\Enums\ItemKind::KABEL->value))->orderBy('name')->pluck('name'))
            ->unique(fn ($name) => mb_strtolower($name))->values();
    @endphp
    <datalist id="removed-device-names">
        @foreach ($deviceNames as $suggestion)
            <option value="{{ $suggestion }}"></option>
        @endforeach
    </datalist>
</div>
