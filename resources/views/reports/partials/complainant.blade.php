{{-- One customer in an area of the complaint map: name, how often they complained, their tickets. --}}
<li class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-xs">
    <span @class([
        'inline-flex min-w-[2.25rem] justify-center rounded-full px-1.5 py-0.5 font-bold',
        'bg-rose-100 text-rose-700' => $person['count'] >= 4,
        'bg-orange-100 text-orange-700' => $person['count'] >= 2 && $person['count'] < 4,
        'bg-amber-100 text-amber-700' => $person['count'] < 2,
    ])>{{ $person['count'] }}×</span>
    <button type="button" data-hotspot data-lat="{{ $person['latitude'] }}" data-lng="{{ $person['longitude'] }}"
            class="font-medium text-slate-700 hover:text-indigo-700 hover:underline">{{ $person['name'] }}</button>
    @if ($person['customer_id'])
        <span class="text-slate-400">{{ $person['customer_id'] }}</span>
    @endif
    <span class="flex flex-wrap gap-x-2">
        @foreach ($person['tickets'] as $ticket)
            <a href="{{ route('tickets.show', $ticket['number']) }}" class="font-mono text-[11px] text-indigo-700 hover:underline">{{ $ticket['number'] }}</a>
        @endforeach
    </span>
</li>
