{{-- The places a unit went through, as nodes joined by arrows: Gudang Utama → technician → customer...
     Needs $nodes (from SerialUnit::journey()). $compact draws small chips for lists. The last node is where it is now. --}}
@php
    $compact = $compact ?? false;
    $styles = [
        'gudang' => ['warehouse', 'border-indigo-300 bg-indigo-50 text-indigo-700', 'bg-indigo-600'],
        'teknisi' => ['hard-hat', 'border-amber-300 bg-amber-50 text-amber-800', 'bg-amber-500'],
        'pelanggan' => ['house', 'border-emerald-300 bg-emerald-50 text-emerald-700', 'bg-emerald-600'],
        'mitra' => ['handshake', 'border-sky-300 bg-sky-50 text-sky-700', 'bg-sky-600'],
        'lepas' => ['unplug', 'border-rose-300 bg-rose-50 text-rose-700', 'bg-rose-600'],
    ];
@endphp
@if ($nodes === [])
    <p class="text-xs text-slate-400">Belum ada perjalanan tercatat</p>
@elseif ($compact)
    <ol class="flex flex-wrap items-center gap-1 text-[11px]">
        @foreach ($nodes as $node)
            @php([$icon, $chip] = $styles[$node['kind']] ?? $styles['lepas'])
            @if (! $loop->first)
                <i data-lucide="chevron-right" class="h-3 w-3 text-slate-300"></i>
            @endif
            <li class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 font-medium {{ $chip }} {{ $loop->last ? 'ring-2 ring-offset-1 ring-current/30' : '' }}"
                title="{{ collect([$node['sub'], $node['date']?->format('d/m/Y')])->filter()->implode(' · ') }}">
                <i data-lucide="{{ $icon }}" class="h-3 w-3"></i>{{ Str::limit($node['label'], 28) }}
            </li>
        @endforeach
    </ol>
@else
    <ol class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-stretch">
        @foreach ($nodes as $node)
            @php([$icon, $chip, $dot] = $styles[$node['kind']] ?? $styles['lepas'])
            @if (! $loop->first)
                <li class="flex items-center justify-center text-slate-300" aria-hidden="true">
                    <i data-lucide="arrow-down" class="h-4 w-4 sm:hidden"></i>
                    <i data-lucide="arrow-right" class="hidden h-5 w-5 sm:block"></i>
                </li>
            @endif
            <li class="relative min-w-[9rem] rounded-xl border p-3 {{ $chip }} {{ $loop->last ? 'shadow-md ring-2 ring-current/20' : '' }}">
                <span class="mb-1 flex items-center gap-1.5 text-[11px] font-semibold tracking-wide uppercase opacity-80">
                    <span class="grid h-5 w-5 place-items-center rounded-full text-white {{ $dot }}"><i data-lucide="{{ $icon }}" class="h-3 w-3"></i></span>
                    {{ ['gudang' => 'Gudang', 'teknisi' => 'Teknisi', 'pelanggan' => 'Pelanggan', 'mitra' => 'Mitra', 'lepas' => 'Dilepas'][$node['kind']] ?? $node['kind'] }}
                    @if ($loop->last)
                        <span class="ml-auto rounded-full bg-white/70 px-1.5 text-[10px] normal-case">sekarang</span>
                    @endif
                </span>
                @if ($node['url'])
                    <a href="{{ $node['url'] }}" class="block font-semibold text-slate-900 hover:underline">{{ $node['label'] }}</a>
                @else
                    <span class="block font-semibold text-slate-900">{{ $node['label'] }}</span>
                @endif
                @if ($node['sub'] || $node['date'])
                    <span class="block text-xs text-slate-600">{{ collect([$node['sub'], $node['date']?->format('d/m/Y')])->filter()->implode(' · ') }}</span>
                @endif
            </li>
        @endforeach
    </ol>
@endif
