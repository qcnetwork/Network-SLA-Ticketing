{{-- Who receives the stock: search a technician or helpdesk by name (or role), pick with the mouse or arrows + Enter. --}}
@php($people = $technicians->sortBy(fn ($user) => [$user->role === App\Enums\Role::TEKNISI ? 0 : 1, $user->name])->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role->label()])->values())
<div x-data="personPicker({ people: @js($people), selected: @js((string) $selected) })" class="relative" @click.outside="open = false">
    <label class="label">Penerima (teknisi / helpdesk)</label>
    <input type="hidden" name="technician_id" :value="picked ? picked.id : ''">

    <div x-show="picked" x-cloak class="flex items-center gap-3 rounded-lg border border-indigo-200 bg-indigo-50/60 p-2.5">
        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-indigo-100 text-xs font-bold text-indigo-700" x-text="picked ? picked.name.slice(0, 2).toUpperCase() : ''"></span>
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-slate-800" x-text="picked?.name"></p>
            <p class="text-xs text-slate-500" x-text="picked?.role"></p>
        </div>
        <button type="button" @click="clear()" class="text-xs font-semibold text-indigo-700 hover:underline">Ganti</button>
    </div>

    <div x-show="! picked" class="relative">
        <i data-lucide="search" class="pointer-events-none absolute top-2.5 left-3 h-4 w-4 text-slate-400"></i>
        <input x-ref="input" x-model="query" @input="onInput()" @focus="open = true" @click="open = true" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)"
            @keydown.enter.prevent="pickActive()" @keydown.escape="open = false" @keydown.tab="open = false"
            class="input pl-9" autocomplete="off" spellcheck="false" placeholder="Cari nama teknisi / helpdesk">
        <div x-show="open" x-cloak x-ref="list" class="absolute z-30 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
            <template x-for="(person, index) in matches" :key="person.id">
                <div>
                    <p x-show="startsGroup(index)" class="sticky top-0 bg-slate-50 px-3 py-1 text-[11px] font-semibold tracking-wide text-slate-500 uppercase" x-text="person.role"></p>
                    <button type="button" :data-index="index" @click="choose(person)" @mouseenter="active = index"
                        :class="index === active ? 'bg-indigo-50 text-indigo-800' : 'text-slate-800'" class="block w-full px-3 py-2 text-left text-sm" x-text="person.name"></button>
                </div>
            </template>
            <p x-show="! matches.length" class="px-3 py-3 text-center text-sm text-slate-400">Tidak ada yang cocok</p>
        </div>
    </div>
    <p x-show="error" x-cloak x-text="error" class="mt-1 text-xs font-medium text-rose-600"></p>
</div>
