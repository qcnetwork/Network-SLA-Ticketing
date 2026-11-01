@props(['attachments', 'route', 'parent'])
@php
    $items = $attachments->map(fn ($attachment) => [
        'url' => route($route, [$parent, $attachment]),
        'name' => $attachment->original_name,
        'video' => $attachment->isVideo(),
    ])->values();
@endphp
{{-- Thumbnails only; tapping one opens the shared <x-media-viewer> popup. --}}
<div class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-4" x-data="{ items: @js($items) }">
    @foreach ($items as $i => $item)
        <button type="button" @click="$dispatch('media-viewer', { items, index: {{ $i }} })" title="{{ $item['name'] }}"
                class="group relative block aspect-square w-full overflow-hidden rounded-lg border border-slate-200 bg-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
            @if ($item['video'])
                <video src="{{ $item['url'] }}" preload="metadata" muted playsinline class="pointer-events-none h-full w-full object-cover"></video>
                <span class="pointer-events-none absolute inset-0 flex items-center justify-center">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-black/55 text-white"><i data-lucide="play" class="ml-0.5 h-5 w-5 fill-current"></i></span>
                </span>
            @else
                <img src="{{ $item['url'] }}" alt="{{ $item['name'] }}" loading="lazy" class="h-full w-full object-cover group-hover:opacity-90">
            @endif
        </button>
    @endforeach
</div>
