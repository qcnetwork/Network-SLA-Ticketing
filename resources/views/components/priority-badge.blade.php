@props(['priority'])
<span {{ $attributes->class(['badge', $priority->badgeClasses()]) }}>{{ $priority->label() }}</span>
