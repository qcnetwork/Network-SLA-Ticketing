{{-- Repair resumes whose material still waits for the warehouse; the technician's stock is unchanged until then. --}}
@if ($materialRequests->isNotEmpty())
    <section class="card p-5">
        <h2 class="mb-1 flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="hourglass" class="h-4 w-4 text-amber-600"></i>Pengajuan Material ({{ $materialRequests->count() }})</h2>
        <p class="mb-3 text-sm text-slate-500">Material dari Resume Perbaikan. Stok teknisi baru berkurang setelah di-ACC.</p>
        <div class="space-y-2">
            @foreach ($materialRequests as $materialRequest)
                @include('warehouses.partials.material-request', ['showTicket' => true])
            @endforeach
        </div>
    </section>
@endif
