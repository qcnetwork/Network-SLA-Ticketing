{{-- The ODP an installation hangs from: its name, where it stands, and the cable the crew expects to pull.
     $prefix keeps the ids unique when several forms on the page carry these fields. --}}
<fieldset class="space-y-3 rounded-lg border border-slate-200 bg-slate-50/60 p-3">
    <legend class="flex items-center gap-1 px-1 text-xs font-semibold text-slate-600"><i data-lucide="git-fork" class="h-3.5 w-3.5"></i>ODP</legend>
    <div>
        <label class="label" for="{{ $prefix }}_odp">Nama ODP</label>
        <input id="{{ $prefix }}_odp" name="odp" value="{{ old('odp', $ticket->odp) }}" class="input" maxlength="100" placeholder="Contoh: ODP Tambakroto 03">
    </div>
    <div>
        <label class="label" for="{{ $prefix }}_odp_location">Shareloc ODP</label>
        <input id="{{ $prefix }}_odp_location" name="odp_location" value="{{ old('odp_location', $ticket->odp_location) }}" class="input" maxlength="255"
            placeholder="Link shareloc atau koordinat ODP">
        <x-field-error name="odp_location" />
    </div>
    @if (App\Services\TicketWorkflowService::measuresOdpDistance(auth()->user()))
        <div>
            <label class="label" for="{{ $prefix }}_odp_distance">Jarak Kabel ODP ke Rumah (sesuai jalan)</label>
            <div class="relative">
                <input id="{{ $prefix }}_odp_distance" name="odp_distance" type="number" min="1" max="100000" inputmode="numeric"
                    value="{{ old('odp_distance', $ticket->odp_distance) }}" class="input pr-16" placeholder="Contoh: 150">
                <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-slate-400">meter</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">
                Ukur panjang jalur kabel mengikuti jalan/tiang.
                @if ($ticket->odpRouteUrl())
                    <a href="{{ $ticket->odpRouteUrl() }}" target="_blank" rel="noopener noreferrer" class="font-semibold text-indigo-700 hover:underline">Buka rute di Google Maps</a> untuk melihat jaraknya.
                @else
                    Isi shareloc ODP dulu supaya rutenya bisa dibuka di Google Maps.
                @endif
            </p>
            <x-field-error name="odp_distance" />
        </div>
    @endif
</fieldset>
