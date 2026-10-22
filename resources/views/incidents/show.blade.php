@use('App\Enums\IncidentAction', 'IA')
@use('App\Enums\IncidentStatus', 'IS')
@use('App\Enums\Role')
@use('App\Enums\TicketStatus', 'S')
<x-layouts.app :title="$incident->incident_number">
    @php
        $user = auth()->user();
        $open = $incident->isOpen();
        $can = fn (IA $action) => $open && $user->can('perform', [$incident, $action]);
        $canAssign = $can(IA::TUGASKAN_TEKNISI);
        $canStart = $can(IA::MULAI_PERBAIKAN) && $incident->status === IS::PERBAIKAN && ! $incident->repair_started_at;
        $canResolve = $can(IA::GAMAS_SELESAI) && ($user->role !== Role::TEKNISI || $incident->repair_started_at);
        $canUpdate = $can(IA::UPDATE_PROGRES);
        $canLink = $can(IA::TIKET_DITAUTKAN);
        $canDetach = $can(IA::TIKET_DILEPAS);
        $actionUrl = fn (IA $action) => route('incidents.perform', [$incident, $action->value]);
        $waiting = $incident->tickets->where('status', S::GAMAS)->count();
    @endphp

    <a href="{{ route('incidents.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-700">
        <i data-lucide="arrow-left" class="h-4 w-4"></i>Kembali ke daftar gamas
    </a>

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="font-mono text-2xl font-bold text-slate-900">{{ $incident->incident_number }}</h1>
                <span class="badge {{ $incident->status->badgeClasses() }}">{{ $incident->status->label() }}</span>
            </div>
            <p class="mt-1 text-lg font-semibold text-slate-800">{{ $incident->title }}</p>
            <p class="text-sm text-slate-500">Dibuka {{ $incident->created_at->format('d/m/Y H:i') }} oleh {{ $incident->creator?->name ?? '-' }}</p>
        </div>
        @if ($open && $user->can('create', App\Models\Ticket::class))
            <a href="{{ route('tickets.create', ['incident' => $incident->id]) }}" class="btn-secondary"><i data-lucide="plus" class="h-4 w-4"></i>Tiket Pelanggan Baru</a>
        @endif
    </div>

    {{-- On phones the actions sit right after the gamas details; on desktop they form the right column. --}}
    <div class="grid gap-6 lg:grid-cols-3 lg:grid-rows-[auto_1fr]">
        <div class="space-y-6 lg:col-span-2">
            <section class="card p-5">
                <h2 class="mb-4 flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="radio-tower" class="h-4 w-4"></i>Detail Gangguan</h2>
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs text-slate-500">Area Terdampak</dt><dd class="font-medium">{{ $incident->area }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Teknisi</dt><dd class="font-medium">{{ $incident->technicianNames() ?: 'Belum ditugaskan' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Pelanggan Terdampak</dt><dd class="font-medium">{{ $incident->tickets->count() }} tiket @if ($open)({{ $waiting }} menunggu)@endif</dd></div>
                    @if ($incident->estimated_resolved_at)
                        <div><dt class="text-xs text-slate-500">Estimasi Selesai</dt><dd class="font-medium">{{ $incident->estimated_resolved_at->format('d/m/Y H:i') }}</dd></div>
                    @endif
                    @if ($incident->repair_started_at)
                        <div><dt class="text-xs text-slate-500">Mulai Perbaikan</dt><dd class="font-medium">{{ $incident->repair_started_at->format('d/m/Y H:i') }}</dd></div>
                    @endif
                    @if ($incident->resolved_at)
                        <div><dt class="text-xs text-slate-500">Selesai</dt><dd class="font-medium">{{ $incident->resolved_at->format('d/m/Y H:i') }}</dd></div>
                    @endif
                    @if ($incident->cause)
                        <div class="sm:col-span-2"><dt class="text-xs text-slate-500">Dugaan Penyebab</dt><dd class="mt-1 rounded-lg bg-slate-50 p-3 whitespace-pre-line wrap-break-word">{{ $incident->cause }}</dd></div>
                    @endif
                    @if ($incident->resolution_notes)
                        <div class="sm:col-span-2"><dt class="text-xs text-slate-500">Tindakan Perbaikan</dt><dd class="mt-1 rounded-lg bg-emerald-50 p-3 whitespace-pre-line wrap-break-word">{{ $incident->resolution_notes }}</dd></div>
                    @endif
                </dl>
            </section>

        </div>

        <div class="order-last space-y-6 lg:order-none lg:col-span-2">
            {{-- Affected customers --}}
            <section class="card p-5" x-data="{ linking: false }">
                <div class="mb-4 flex items-center justify-between gap-2">
                    <h2 class="flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="users" class="h-4 w-4"></i>Pelanggan Terdampak</h2>
                    @if ($canLink)
                        <button type="button" @click="linking = ! linking" class="btn-secondary text-xs"><i data-lucide="link" class="h-4 w-4"></i>Tautkan Tiket</button>
                    @endif
                </div>

                @if ($canLink)
                    <form method="POST" action="{{ route('incidents.tickets.link', $incident) }}" x-show="linking" x-cloak class="mb-4 space-y-3 rounded-lg border border-indigo-200 bg-indigo-50/40 p-3">
                        @csrf
                        <x-ticket-picker :tickets="$linkableTickets" />
                        @if ($linkableTickets->isNotEmpty())
                            <button class="btn-primary w-full"><i data-lucide="link" class="h-4 w-4"></i>Tautkan Tiket Terpilih</button>
                        @endif
                    </form>
                @endif

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="py-2 pr-3">No. Tiket</th>
                                <th class="py-2 pr-3">Pelanggan</th>
                                <th class="py-2 pr-3">Status</th>
                                @if ($canDetach)<th class="py-2"></th>@endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($incident->tickets as $ticket)
                                <tr>
                                    <td class="py-2 pr-3"><a href="{{ route('tickets.show', $ticket) }}" class="font-mono text-xs font-semibold text-indigo-700 hover:underline">{{ $ticket->ticket_number }}</a></td>
                                    <td class="py-2 pr-3">
                                        <div class="font-medium">{{ $ticket->customer_name }}</div>
                                        <div class="text-xs text-slate-500">{{ $ticket->customer_address ?: '-' }}</div>
                                    </td>
                                    <td class="py-2 pr-3"><x-status-badge :status="$ticket->status" /></td>
                                    @if ($canDetach)
                                        <td class="py-2 text-right">
                                            @if ($ticket->status === S::GAMAS)
                                                <form method="POST" action="{{ route('incidents.tickets.detach', [$incident, $ticket]) }}" onsubmit="return confirm('Lepas tiket {{ $ticket->ticket_number }} dari gamas? Tiket akan kembali ke Antrian Helpdesk.')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="text-xs font-semibold text-rose-600 hover:underline">Lepas</button>
                                                </form>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-6 text-center text-slate-400">Belum ada tiket pelanggan yang ditautkan</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Timeline --}}
            <section class="card p-5">
                <h2 class="mb-4 flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="history" class="h-4 w-4"></i>Riwayat Gamas</h2>
                <ol class="relative space-y-5 border-l-2 border-slate-200 pl-6">
                    @foreach ($incident->updates as $update)
                        <li class="relative">
                            <span class="absolute top-1 -left-[31px] h-3 w-3 rounded-full border-2 border-white bg-red-500 ring-2 ring-red-100"></span>
                            <div class="text-sm font-semibold text-slate-800">{{ $update->action->label() }}</div>
                            <div class="text-xs text-slate-500">{{ $update->user?->name ?? $update->actor_name ?? 'Sistem' }} · {{ $update->created_at->format('d/m/Y H:i') }}</div>
                            @if ($update->notes)
                                <p class="mt-1 text-sm whitespace-pre-line wrap-break-word text-slate-600">{{ $update->notes }}</p>
                            @endif
                            @if ($update->attachments->isNotEmpty())
                                <x-attachment-grid :attachments="$update->attachments" route="incidents.attachment" :parent="$incident" />
                            @endif
                        </li>
                    @endforeach
                </ol>
            </section>
        </div>

        {{-- Actions --}}
        <aside class="space-y-4 lg:col-start-3 lg:row-span-2 lg:row-start-1">
            @if ($canAssign)
                <form method="POST" action="{{ $actionUrl(IA::TUGASKAN_TEKNISI) }}" class="card space-y-3 p-5">
                    @csrf
                    <h3 class="font-semibold">{{ $incident->technicians->isNotEmpty() ? 'Ubah Teknisi' : 'Tugaskan Teknisi' }}</h3>
                    <x-technician-picker :technicians="$technicians" :selected="$incident->technicians->modelKeys()" />
                    <textarea name="notes" rows="2" class="input" placeholder="Instruksi untuk teknisi (opsional)"></textarea>
                    <button class="btn-warning w-full"><i data-lucide="wrench" class="h-4 w-4"></i>Tugaskan</button>
                </form>
            @endif

            @if ($canStart)
                <form method="POST" action="{{ $actionUrl(IA::MULAI_PERBAIKAN) }}" class="card p-5">
                    @csrf
                    <h3 class="mb-1 font-semibold">Mulai Perbaikan</h3>
                    <p class="mb-3 text-sm text-slate-500">Tekan saat Anda sudah tiba di lokasi gangguan.</p>
                    <button class="btn-warning w-full"><i data-lucide="play" class="h-4 w-4"></i>Saya Sudah di Lokasi</button>
                </form>
            @endif

            @if ($canUpdate)
                <form method="POST" action="{{ $actionUrl(IA::UPDATE_PROGRES) }}" enctype="multipart/form-data" class="card space-y-3 p-5">
                    @csrf
                    <h3 class="flex items-center gap-2 font-semibold"><i data-lucide="camera" class="h-4 w-4 text-indigo-600"></i>Update Progres</h3>
                    <textarea name="notes" rows="3" class="input" placeholder="Contoh: Penarikan kabel baru 60% selesai"></textarea>
                    <div>
                        <label class="label" for="update_estimate">Perbarui estimasi selesai</label>
                        <input id="update_estimate" type="datetime-local" name="estimated_resolved_at" class="input">
                    </div>
                    <x-photo-input id="incident_photos" />
                    <button class="btn-primary w-full"><i data-lucide="upload" class="h-4 w-4"></i>Simpan Update</button>
                </form>
            @endif

            @if ($canResolve)
                <form method="POST" action="{{ $actionUrl(IA::GAMAS_SELESAI) }}" enctype="multipart/form-data" class="card space-y-3 p-5"
                    onsubmit="return confirm('Selesaikan gamas? {{ $waiting }} tiket pelanggan akan pindah ke Menunggu Konfirmasi.')">
                    @csrf
                    <h3 class="font-semibold">Gamas Selesai</h3>
                    <p class="text-sm text-slate-500">{{ $waiting }} tiket pelanggan akan pindah ke <b>Menunggu Konfirmasi</b> untuk dicek CS satu per satu.</p>
                    <textarea name="notes" rows="3" class="input" required placeholder="Tindakan: sambung ulang feeder, redaman normal -19 dBm"></textarea>
                    <x-photo-input id="resolve_photos" />
                    <button class="btn-success w-full"><i data-lucide="check-circle-2" class="h-4 w-4"></i>Selesaikan Gamas</button>
                </form>
            @endif

            @if (! $open)
                <div class="card p-5 text-sm">
                    <p class="flex items-center gap-2 font-medium text-emerald-700"><i data-lucide="check-circle-2" class="h-4 w-4"></i>Gamas sudah selesai.</p>
                    <p class="mt-1 text-slate-500">Konfirmasi pelanggan dilanjutkan di masing-masing tiket.</p>
                </div>
            @elseif (! ($canAssign || $canStart || $canUpdate || $canResolve))
                <div class="card p-5 text-sm text-slate-500">Tidak ada aksi untuk peran Anda pada tahap ini.</div>
            @endif
        </aside>
    </div>
</x-layouts.app>
