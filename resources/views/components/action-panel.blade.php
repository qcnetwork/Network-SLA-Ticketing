{{-- One folded row in a ticket's side menu. Needs an `open` Alpine variable on an ancestor; only one row is open at a time. --}}
@props(['name', 'icon', 'title', 'hint' => null, 'color' => 'text-indigo-600'])
<div>
    <button type="button" @click="open = open === '{{ $name }}' ? null : '{{ $name }}'" :aria-expanded="open === '{{ $name }}'"
        :class="open === '{{ $name }}' && 'bg-slate-50'" class="flex w-full items-center gap-3 px-5 py-3 text-left transition hover:bg-slate-50">
        <i data-lucide="{{ $icon }}" class="h-4 w-4 shrink-0 {{ $color }}"></i>
        <span class="min-w-0 flex-1">
            <span class="block text-sm font-semibold text-slate-800">{{ $title }}</span>
            @if ($hint)
                <span class="block text-xs text-slate-500">{{ $hint }}</span>
            @endif
        </span>
        <span class="shrink-0 text-slate-400 transition" :class="open === '{{ $name }}' && 'rotate-180'"><i data-lucide="chevron-down" class="h-4 w-4"></i></span>
    </button>
    <div x-show="open === '{{ $name }}'" x-cloak class="bg-slate-50 px-5 pt-1 pb-5">
        {{ $slot }}
    </div>
</div>
