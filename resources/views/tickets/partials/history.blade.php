{{-- The ticket's timeline, newest last, with the photos and videos of each entry. --}}
<ol class="relative space-y-5 border-l-2 border-slate-200 pl-6">
    @foreach ($ticket->histories as $history)
        <li class="relative">
            <span class="absolute top-1 -left-[31px] h-3 w-3 rounded-full border-2 border-white bg-indigo-500 ring-2 ring-indigo-100"></span>
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="font-semibold text-slate-800">{{ $history->action->label() }}</span>
                @if ($history->urgency_level)
                    <span class="badge border-rose-300 bg-rose-50 text-rose-700">{{ $history->urgency_level }}</span>
                @endif
                @if ($history->from_status && $history->from_status !== $history->to_status)
                    <span class="text-xs text-slate-400">{{ $history->from_status->label() }} &rarr; {{ $history->to_status->label() }}</span>
                @endif
            </div>
            <div class="text-xs text-slate-500">{{ $history->user?->name ?? $history->actor_name ?? 'Sistem' }} · {{ $history->created_at->format('d/m/Y H:i') }}</div>
            @if ($history->notes)
                <p class="mt-1 text-sm whitespace-pre-line wrap-break-word text-slate-600">{{ $history->notes }}</p>
            @endif
            @if ($history->attachments->isNotEmpty())
                <x-attachment-grid :attachments="$history->attachments" route="tickets.attachment" :parent="$ticket" />
            @endif
        </li>
    @endforeach
</ol>
