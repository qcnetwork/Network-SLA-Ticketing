@props(['tickets'])
<div x-data="{ search: '' }">
    @if ($tickets->isEmpty())
        <p class="rounded-lg border border-dashed border-slate-300 py-6 text-center text-sm text-slate-400">Tidak ada tiket terbuka yang bisa ditautkan.</p>
    @else
        <input type="search" x-model="search" class="input mb-2" placeholder="Cari nama, no. tiket, alamat, kendala">
        <div class="max-h-80 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200">
            @foreach ($tickets as $ticket)
                <label class="flex cursor-pointer items-start gap-3 px-3 py-2 hover:bg-slate-50"
                    x-show="! search || $el.dataset.search.includes(search.toLowerCase())"
                    data-search="{{ Str::lower($ticket->ticket_number.' '.$ticket->customer_name.' '.$ticket->customer_address.' '.$ticket->complaint) }}">
                    <input type="checkbox" name="ticket_ids[]" value="{{ $ticket->id }}" @checked(in_array($ticket->id, old('ticket_ids', []))) class="mt-1 rounded border-slate-300 text-indigo-600">
                    <span class="min-w-0 flex-1 text-sm">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-xs font-semibold text-indigo-700">{{ $ticket->ticket_number }}</span>
                            <span class="font-medium text-slate-800">{{ $ticket->customer_name }}</span>
                            <x-status-badge :status="$ticket->status" />
                        </span>
                        <span class="block truncate text-xs text-slate-500">{{ $ticket->customer_address ?: '-' }} · {{ $ticket->category }}</span>
                    </span>
                </label>
            @endforeach
        </div>
    @endif
    <x-field-error name="ticket_ids" />
</div>
