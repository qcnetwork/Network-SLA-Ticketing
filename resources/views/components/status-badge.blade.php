@props(['status'])
<span {{ $attributes->class(['badge', $status->badgeClasses()]) }}>
    <i data-lucide="{{ $status->icon() }}" class="h-3 w-3"></i>{{ $status->label() }}
</span>
