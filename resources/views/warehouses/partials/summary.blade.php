{{-- Headline numbers of a warehouse. Each tile: [label, value, icon, tone, hint]; tone is a colour name used when the value needs attention. --}}
@php
    $tones = [
        'slate' => 'bg-slate-100 text-slate-600',
        'indigo' => 'bg-indigo-100 text-indigo-700',
        'sky' => 'bg-sky-100 text-sky-700',
        'violet' => 'bg-violet-100 text-violet-700',
        'emerald' => 'bg-emerald-100 text-emerald-700',
        'amber' => 'bg-amber-100 text-amber-700',
        'rose' => 'bg-rose-100 text-rose-700',
    ];
    // Written out in full so Tailwind sees the class names.
    $columns = [3 => 'md:grid-cols-3', 4 => 'md:grid-cols-4', 5 => 'md:grid-cols-5'][count($tiles)] ?? 'md:grid-cols-4';
@endphp
<div class="mb-6 grid grid-cols-2 gap-3 {{ $columns }}">
    @foreach ($tiles as [$label, $value, $icon, $tone, $hint])
        <div class="card flex items-center gap-3 p-4">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl {{ $tones[$tone] }}"><i data-lucide="{{ $icon }}" class="h-5 w-5"></i></span>
            <div class="min-w-0">
                <p class="text-xl leading-tight font-bold text-slate-900">{{ is_numeric($value) ? number_format($value, 0, ',', '.') : $value }}</p>
                <p class="truncate text-xs font-medium text-slate-500">{{ $label }}</p>
                @if ($hint)
                    <p class="truncate text-[11px] text-slate-400">{{ $hint }}</p>
                @endif
            </div>
        </div>
    @endforeach
</div>
