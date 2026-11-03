{{-- One repair resume's material and the warehouse's decision on it. Stock only moves once it is approved. --}}
@php($canReview = $materialRequest->isPending() && auth()->user()->hasRole(App\Enums\Role::GUDANG, App\Enums\Role::SPV))
<div class="rounded-lg bg-slate-50 px-3 py-2">
    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
        <span>
            @if ($showTicket)
                <a href="{{ route('tickets.show', $materialRequest->ticket) }}" class="font-mono font-semibold text-indigo-700 hover:underline">{{ $materialRequest->ticket->ticket_number }}</a>
                · {{ $materialRequest->ticket->customer_name }} ·
            @endif
            Diajukan {{ $materialRequest->requester?->name ?? '-' }} · {{ $materialRequest->created_at->format('d/m/Y H:i') }}
        </span>
        <span class="badge {{ $materialRequest->status->badgeClasses() }}">{{ $materialRequest->status->label() }}</span>
    </div>
    <div class="divide-y divide-slate-100">
        @foreach ($materialRequest->lines as $line)
            <div class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                <span><span class="font-mono text-xs text-slate-600">{{ $line->item->code }}</span> {{ $line->item->name }}
                    @if ($line->serialUnit)
                        <span class="block font-mono text-xs text-slate-600">SN {{ $line->serialUnit->serial_number }}{{ $line->serialUnit->mac ? ' · '.$line->serialUnit->formattedMac() : '' }}</span>
                    @endif
                    @if ($line->notes)<span class="text-xs text-slate-500">({{ $line->notes }})</span>@endif
                </span>
                <span class="text-xs text-slate-500"><span class="font-semibold text-slate-800">× {{ $line->quantity }} {{ $line->item->unit }}</span> · {{ $line->warehouse->name }}</span>
            </div>
        @endforeach
    </div>
    @if ($materialRequest->reviewed_at)
        <p class="border-t border-slate-100 pt-2 text-xs text-slate-500">
            {{ $materialRequest->status->label() }} oleh {{ $materialRequest->reviewer?->name ?? '-' }} · {{ $materialRequest->reviewed_at->format('d/m/Y H:i') }}
            @if ($materialRequest->review_notes)
                · {{ $materialRequest->review_notes }}
            @endif
        </p>
    @elseif ($canReview)
        <form method="POST" action="{{ route('material-requests.approve', $materialRequest) }}" class="flex flex-col gap-2 border-t border-slate-100 pt-2 sm:flex-row">
            @csrf
            <input type="hidden" name="material_request_id" value="{{ $materialRequest->id }}">
            <input name="review_notes" class="input flex-1 text-sm" maxlength="255" placeholder="Catatan (wajib jika ditolak)"
                value="{{ old('material_request_id') == $materialRequest->id ? old('review_notes') : '' }}">
            <div class="flex gap-2">
                <button class="btn-success"><i data-lucide="check" class="h-4 w-4"></i>ACC</button>
                <button formaction="{{ route('material-requests.reject', $materialRequest) }}" class="btn-danger"><i data-lucide="x" class="h-4 w-4"></i>Tolak</button>
            </div>
        </form>
        @if (old('material_request_id') == $materialRequest->id)
            <x-field-error name="material_request" />
            <x-field-error name="review_notes" />
        @endif
    @endif
</div>
