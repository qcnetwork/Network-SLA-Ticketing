@props(['technicians', 'selected' => []])
@php
    $initial = collect(old('technician_ids', $selected))->filter()->map(fn ($id) => (int) $id)->values()->all();
    $options = $technicians->map(fn ($technician) => ['id' => $technician->id, 'name' => $technician->name])->values();
@endphp
<div>
    @if ($technicians->isEmpty())
        <p class="text-xs text-rose-600">Belum ada akun teknisi aktif. Minta supervisor menambahkannya.</p>
    @else
        {{-- Type to search, or open the list and click; each pick becomes a chip. Tab takes the highlighted name and
             stays in the box for the next one (once something was typed or the arrows were used, so tabbing through an
             untouched form still moves on). Enter does nothing here, so it can neither pick nor send the form by accident. --}}
        <div x-data="{
                options: @js($options),
                selected: @js($initial),
                query: '',
                open: false,
                active: 0,
                navigated: false,
                get matches() {
                    const term = this.query.trim().toLowerCase();
                    return this.options.filter((option) => ! this.selected.includes(option.id) && option.name.toLowerCase().includes(term));
                },
                nameOf(id) {
                    return this.options.find((option) => option.id === id)?.name ?? '';
                },
                pick(option) {
                    if (! option) return;
                    this.selected.push(option.id);
                    this.query = '';
                    this.active = 0;
                    this.navigated = false;
                    this.$nextTick(() => window.renderIcons?.());
                },
                remove(id) {
                    this.selected = this.selected.filter((other) => other !== id);
                },
                move(step) {
                    this.open = true;
                    this.navigated = true;
                    const count = this.matches.length;
                    if (count) this.active = (this.active + step + count) % count;
                    this.$nextTick(() => this.$refs.list?.children[this.active]?.scrollIntoView({ block: 'nearest' }));
                },
                tab(event) {
                    if (event.shiftKey || ! this.open || ! this.matches.length || (this.query.trim() === '' && ! this.navigated)) {
                        this.open = false;
                        return;
                    }
                    event.preventDefault();
                    this.pick(this.matches[this.active]);
                },
            }" x-init="$nextTick(() => window.renderIcons?.())" class="space-y-2" @click.outside="open = false">
            <template x-for="id in selected" :key="id">
                <input type="hidden" name="technician_ids[]" :value="id">
            </template>

            <div x-show="selected.length" class="flex flex-wrap gap-1.5">
                <template x-for="id in selected" :key="id">
                    <span class="inline-flex items-center gap-1 rounded-full border border-orange-200 bg-orange-50 py-0.5 pr-1 pl-2.5 text-xs font-semibold text-orange-800">
                        <span x-text="nameOf(id)"></span>
                        <button type="button" @click="remove(id)" class="rounded-full p-0.5 hover:bg-orange-200" :aria-label="'Hapus ' + nameOf(id)">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="h-3 w-3"><path d="M18 6 6 18M6 6l12 12" /></svg>
                        </button>
                    </span>
                </template>
            </div>

            <div class="relative" x-show="selected.length < options.length">
                <div class="relative">
                    <input type="text" x-model="query" autocomplete="off" class="input pr-9"
                        :placeholder="selected.length ? 'Tambah teknisi lain (opsional)' : 'Ketik nama teknisi atau pilih dari daftar'"
                        :required="selected.length === 0" role="combobox" aria-autocomplete="list" :aria-expanded="open"
                        @focus="open = true" @click="open = true" @input="open = true; active = 0"
                        @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
                        @keydown.enter.prevent @keydown.escape="open = false" @keydown.tab="tab($event)">
                    <button type="button" @click="open = ! open" tabindex="-1" class="absolute top-1/2 right-2 -translate-y-1/2 p-1 text-slate-400 hover:text-slate-600" aria-label="Tampilkan daftar teknisi">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4 transition" :class="open && 'rotate-180'"><path d="m6 9 6 6 6-6" /></svg>
                    </button>
                </div>
                <ul x-ref="list" x-show="open" x-cloak role="listbox"
                    class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg">
                    <template x-for="(option, index) in matches" :key="option.id">
                        {{-- mousedown so the pick lands before the input loses focus. --}}
                        <li role="option" :aria-selected="index === active" @mousedown.prevent="pick(option)" @mouseenter="active = index"
                            :class="index === active ? 'bg-indigo-50 text-indigo-700' : 'text-slate-700'"
                            class="flex cursor-pointer items-center justify-between gap-2 px-3 py-2">
                            <span x-text="option.name"></span>
                            <span x-show="index === active" class="shrink-0 text-xs text-slate-400">Tab</span>
                        </li>
                    </template>
                    <li x-show="! matches.length" class="px-3 py-2 text-xs text-slate-400">Tidak ada teknisi yang cocok</li>
                </ul>
            </div>
        </div>
    @endif
    <x-field-error name="technician_ids" />
</div>
