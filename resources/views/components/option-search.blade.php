@props(['name', 'id', 'options', 'selected' => '', 'placeholder' => 'Ketik untuk mencari…', 'message' => 'Pilih dari daftar yang tersedia'])
<div x-data="optionSearch(@js(array_values($options)), @js((string) $selected), @js($message))" class="relative">
    <input type="hidden" name="{{ $name }}" :value="value">
    <div class="relative">
        <input id="{{ $id }}" x-ref="input" x-model="query" type="text" autocomplete="off" class="input pr-9" placeholder="{{ $placeholder }}"
            role="combobox" aria-autocomplete="list" :aria-expanded="open" aria-controls="{{ $id }}_list"
            @focus="open = true" @input="onInput()" @blur="leave()"
            @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
            @keydown.enter="accept($event)" @keydown.tab="accept($event)" @keydown.escape="close()">
        <i data-lucide="search" class="pointer-events-none absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 text-slate-400"></i>
    </div>

    <ul id="{{ $id }}_list" x-ref="list" x-show="open && matches.length" x-cloak role="listbox"
        class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg">
        <template x-for="(option, index) in matches" :key="option">
            {{-- mousedown so the choice lands before the input's blur resets it. --}}
            <li role="option" :aria-selected="index === active" @mousedown.prevent="choose(option)" @mouseenter="active = index"
                :class="index === active ? 'bg-indigo-50 text-indigo-700' : 'text-slate-700'"
                class="flex cursor-pointer items-center justify-between gap-2 px-3 py-2">
                <span x-text="option"></span>
                <span x-show="index === active" class="shrink-0 text-xs text-slate-400">Tab / Enter</span>
            </li>
        </template>
    </ul>
    <p x-show="open && query && query !== value && !matches.length" x-cloak class="mt-1 text-xs text-rose-600">Tidak ada yang cocok. {{ $message }}.</p>
    <p x-show="error" x-text="error" x-cloak class="mt-1 text-xs text-rose-600"></p>
</div>
