@use('App\Enums\TicketAction', 'A')
@use('App\Enums\TicketStatus', 'S')
<x-layouts.app :title="$ticket->ticket_number">
    @php
        $status = $ticket->status;
        $can = fn (A $action) => auth()->user()->can('perform', [$ticket, $action]);
        // Helpdesk still holds a queued ticket until a technician is assigned, so it stays on their step.
        $stepIndex = array_search(match ($status) { S::CEK_ULANG, S::ANTRIAN_TEKNISI => S::HELPDESK_CEK, S::GAMAS => S::PERBAIKAN, default => $status }, $flow, true);
        $canClaim = $can(A::KLAIM_HELPDESK) && in_array($status, [S::ANTRIAN_HELPDESK, S::CEK_ULANG], true);
        $canResolve = $can(A::SELESAI_REMOTE) && in_array($status, [S::HELPDESK_CEK, S::CEK_ULANG, S::ANTRIAN_TEKNISI], true);
        $canQueue = $can(A::ANTRI_TEKNISI) && in_array($status, [S::HELPDESK_CEK, S::CEK_ULANG], true);
        $canRepair = $can(A::MULAI_PERBAIKAN) && $status === S::PERBAIKAN;
        $canReassign = $can(A::UBAH_TEKNISI) && $status === S::PERBAIKAN;
        $canRequeue = $can(A::ANTRI_TEKNISI) && $status === S::PERBAIKAN;
        $canConfirm = $can(A::KONFIRMASI_NORMAL) && $status === S::MENUNGGU_KONFIRMASI;
        $canUrgency = $can(A::CATATAN_URGENSI_CS) && ! in_array($status, [S::PROSES_CS, S::TUTUP], true);
        $canHelpdeskInfo = $can(A::CATATAN_INFO_HELPDESK) && $status !== S::TUTUP;
        $canDocument = $can(A::CATATAN_DOKUMENTASI) && $status !== S::TUTUP;
        $canRecategorize = $can(A::UBAH_KATEGORI) && $status !== S::TUTUP;
        $canEditCustomer = $can(A::UBAH_DATA_PELANGGAN) && $status !== S::TUTUP;
        $canEditHandling = $can(A::UBAH_PENANGANAN);
        $canForward = $can(A::TERUSKAN_HELPDESK) && $status === S::PROSES_CS;
        $canCsClose = $can(A::SELESAI_EDUKASI_CS) && in_array($status, [S::PROSES_CS, S::ANTRIAN_HELPDESK], true);
        $actionUrl = fn (A $action) => route('tickets.perform', [$ticket, $action->value]);
        $isFieldTechnician = auth()->user()->role === App\Enums\Role::TEKNISI;
        $materialForm = [
            'lines' => array_values(old('materials', [])),
            'technicians' => $stockHolders,
            'stock' => (object) $crewStock,
            'unitsUrl' => route('tickets.material.units', $ticket),
            'defaultTechnician' => $isFieldTechnician && isset($crewStock[auth()->id()]) ? auth()->id() : (count($stockHolders) === 1 ? $stockHolders[0]['id'] : ''),
        ];
        // A new resume would carry its own material, so the resubmit form waits until the repair is reported.
        $canResubmitMaterial = auth()->user()->can('resubmitMaterial', $ticket) && ! ($canRepair && $ticket->repair_started_at);
        // Material approval runs on its own clock: it can still be waiting after the ticket is closed.
        $materialStatus = auth()->user()->hasRole(App\Enums\Role::HELPDESK, App\Enums\Role::GUDANG, App\Enums\Role::SPV) && $ticket->materialRequests->isNotEmpty()
            ? match (true) {
                $ticket->materialRequests->contains->isPending() => App\Enums\MaterialRequestStatus::PENDING,
                $ticket->materialRequests->contains('status', App\Enums\MaterialRequestStatus::APPROVED) => App\Enums\MaterialRequestStatus::APPROVED,
                default => App\Enums\MaterialRequestStatus::REJECTED,
            }
            : null;
        $hasNextStep = $canForward || $canCsClose || $canClaim || $canResolve || $canRepair || $canResubmitMaterial || $canConfirm;
        $hasNotes = $canUrgency || $canHelpdeskInfo || $canDocument;
        $hasMore = $canRecategorize || $canJoinIncident || auth()->user()->can('share', $ticket);
        // Reopen the folded form that failed validation, so its message and input are not hidden.
        $openPanel = $errors->any() ? old('_panel') : null;
        $stepCard = 'card border-t-4 border-t-indigo-600 p-5';
    @endphp

    @if ($myShare)
        <a href="{{ route('archive.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-700">
            <i data-lucide="arrow-left" class="h-4 w-4"></i>Kembali ke arsip
        </a>
        <p class="mb-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
            <i data-lucide="archive" class="mt-0.5 h-4 w-4 shrink-0"></i>
            <span>Arsip dibagikan oleh {{ $myShare->sharedBy?->name ?? 'Helpdesk' }}. Hanya bisa dilihat, sampai {{ $myShare->expires_at->format('d/m/Y H:i') }}.</span>
        </p>
    @else
        <a href="{{ route('complaints.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-700">
            <i data-lucide="arrow-left" class="h-4 w-4"></i>Kembali ke Tiket Komplain
        </a>
    @endif

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="font-mono text-2xl font-bold text-slate-900">{{ $ticket->ticket_number }}</h1>
                <x-status-badge :status="$status" />
                <x-priority-badge :priority="$ticket->priority" />
            </div>
            <p class="mt-1 text-sm text-slate-500">Dibuat {{ $ticket->created_at->format('d/m/Y H:i') }} oleh {{ $ticket->creator?->name ?? '-' }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @can('contactCustomer', $ticket)
                @foreach ($waTypes as $type => $label)
                    <a href="{{ route('tickets.whatsapp', [$ticket, 'type' => $type]) }}" target="_blank" rel="noopener" class="btn-secondary text-xs">
                        <i data-lucide="message-circle" class="h-4 w-4 text-emerald-600"></i>{{ $label }}
                    </a>
                @endforeach
            @endcan
            @can('delete', $ticket)
                <form method="POST" action="{{ route('tickets.destroy', $ticket) }}" data-confirm="{{ 'Hapus tiket '.$ticket->ticket_number.'? Riwayat dan foto/video dokumentasi ikut terhapus permanen.' }}">
                    @csrf
                    @method('DELETE')
                    <button class="btn-danger text-xs"><i data-lucide="trash-2" class="h-4 w-4"></i>Hapus Tiket</button>
                </form>
            @endcan
        </div>
    </div>

    {{-- Workflow stepper --}}
    <div class="card mb-6 overflow-x-auto p-4">
        <ol class="flex min-w-max items-center gap-2">
            @foreach ($flow as $i => $step)
                <li class="flex items-center gap-2">
                    <span @class([
                        'flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold',
                        'border-indigo-600 bg-indigo-600 text-white' => $i === $stepIndex,
                        'border-emerald-300 bg-emerald-50 text-emerald-700' => $i < $stepIndex,
                        'border-slate-200 bg-white text-slate-400' => $i > $stepIndex,
                    ])>
                        <i data-lucide="{{ $i < $stepIndex ? 'check' : $step->icon() }}" class="h-3.5 w-3.5"></i>{{ $step->label() }}
                    </span>
                    @if (! $loop->last || $materialStatus)<i data-lucide="chevron-right" class="h-4 w-4 text-slate-300"></i>@endif
                </li>
            @endforeach
            @if ($materialStatus)
                <li>
                    <span class="flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold {{ $materialStatus->badgeClasses() }}" title="{{ $materialStatus->label() }}">
                        <i data-lucide="{{ match ($materialStatus) { App\Enums\MaterialRequestStatus::PENDING => 'hourglass', App\Enums\MaterialRequestStatus::APPROVED => 'check', default => 'x' } }}" class="h-3.5 w-3.5"></i>Approval Material
                    </span>
                </li>
            @endif
        </ol>
        @if ($materialStatus === App\Enums\MaterialRequestStatus::PENDING)
            <p class="mt-3 flex items-center gap-1 text-xs font-medium text-amber-700"><i data-lucide="package" class="h-3.5 w-3.5"></i>Material dari Resume Perbaikan menunggu ACC Gudang. Stok teknisi belum berkurang.</p>
        @endif
        @if ($status === S::GAMAS)
            <p class="mt-3 flex items-center gap-1 text-xs font-medium text-red-700"><i data-lucide="radio-tower" class="h-3.5 w-3.5"></i>Tiket ini menunggu perbaikan gangguan massal. Tiket otomatis lanjut ke konfirmasi saat gamas selesai.</p>
        @endif
        @if ($status === S::PROSES_CS)
            <p class="mt-3 flex items-center gap-1 text-xs font-medium text-cyan-700"><i data-lucide="headset" class="h-3.5 w-3.5"></i>Tiket masih ditangani CS. Helpdesk baru bisa mengambil tiket setelah CS meneruskannya ke Antrian Helpdesk.</p>
        @endif
        @if ($status === S::CEK_ULANG)
            <p class="mt-3 flex items-center gap-1 text-xs font-medium text-rose-600"><i data-lucide="refresh-cw" class="h-3.5 w-3.5"></i>Pelanggan melaporkan masih terkendala. Tiket kembali ke Helpdesk untuk cek ulang.</p>
        @endif
        @if ($status === S::ANTRIAN_TEKNISI)
            <p class="mt-3 flex items-center gap-1 text-xs font-medium text-yellow-700"><i data-lucide="hourglass" class="h-3.5 w-3.5"></i>Perlu kunjungan teknisi. Menunggu teknisi tersedia untuk ditugaskan.</p>
        @endif
    </div>

    {{-- On phones the actions sit between the ticket details and the long history; on desktop they form the right column. --}}
    <div class="grid gap-6 lg:grid-cols-3 lg:grid-rows-[auto_1fr]">
        <div class="space-y-6 lg:col-span-2">
            {{-- Customer & complaint --}}
            <section class="card p-5" x-data="{ editing: @js($errors->hasAny(['customer', 'customer_name', 'customer_phone', 'customer_id', 'customer_address'])) }">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 class="flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="user" class="h-4 w-4"></i>Data Pelanggan</h2>
                    @if ($canEditCustomer)
                        <button type="button" x-show="! editing" @click="editing = true" class="btn-secondary px-3 py-1.5 text-xs"><i data-lucide="pencil" class="h-3.5 w-3.5"></i>Ubah</button>
                    @endif
                </div>

                @if ($canEditCustomer)
                    <form method="POST" action="{{ route('tickets.customer.update', $ticket) }}" x-show="editing" x-cloak class="space-y-4">
                        @csrf
                        @method('PUT')
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="label" for="edit_customer_name">Nama Pelanggan <span class="text-rose-500">*</span></label>
                                <input id="edit_customer_name" name="customer_name" value="{{ old('customer_name', $ticket->customer_name) }}" class="input" required maxlength="150">
                                <x-field-error name="customer_name" />
                            </div>
                            <div>
                                <label class="label" for="edit_customer_phone">No. WhatsApp <span class="text-rose-500">*</span></label>
                                <input id="edit_customer_phone" name="customer_phone" value="{{ old('customer_phone', $ticket->customer_phone) }}" class="input" required inputmode="tel">
                                <x-field-error name="customer_phone" />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="label" for="edit_customer_id">ID Pelanggan</label>
                                <input id="edit_customer_id" name="customer_id" value="{{ old('customer_id', $ticket->customer_id) }}" class="input" maxlength="50">
                                <x-field-error name="customer_id" />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="label" for="edit_customer_address">Alamat / Shareloc</label>
                                <input id="edit_customer_address" name="customer_address" value="{{ old('customer_address', $ticket->customer_address) }}" class="input" maxlength="255" placeholder="Alamat, link shareloc Google Maps/WhatsApp, atau koordinat (-6.2088, 106.8456)">
                                <p class="mt-1 text-xs text-slate-500">Tempel link shareloc dari pelanggan, titik lokasinya otomatis terbaca.</p>
                                <x-field-error name="customer_address" />
                            </div>
                        </div>
                        <x-field-error name="customer" />
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="editing = false" class="btn-secondary">Batal</button>
                            <button class="btn-primary"><i data-lucide="save" class="h-4 w-4"></i>Simpan</button>
                        </div>
                    </form>
                @endif

                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2" x-show="! editing">
                    <div><dt class="text-xs text-slate-500">Nama</dt><dd class="font-medium">{{ $ticket->customer_name }}</dd></div>
                    <div><dt class="text-xs text-slate-500">ID Pelanggan</dt><dd class="font-medium">{{ $ticket->customer_id ?? '-' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">WhatsApp</dt><dd class="font-medium">{{ $ticket->customer_phone }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Kategori</dt><dd class="font-medium">{{ $ticket->category }}</dd></div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-slate-500">Alamat</dt>
                        <dd class="font-medium break-all">
                            @if (Str::startsWith($ticket->customer_address, ['http://', 'https://']))
                                <a href="{{ $ticket->customer_address }}" target="_blank" rel="noopener noreferrer" class="text-indigo-700 hover:underline">{{ $ticket->customer_address }}</a>
                            @else
                                {{ $ticket->customer_address ?: '-' }}
                            @endif
                        </dd>
                        @if ($canEditCustomer && ! $ticket->hasLocation())
                            <p class="mt-1 text-xs text-slate-400">Belum ada titik lokasi. Klik Ubah untuk menambahkan shareloc.</p>
                        @endif
                    </div>
                    @if ($ticket->hasLocation())
                        @php($coordinates = App\Support\GeoLocation::format($ticket->latitude).', '.App\Support\GeoLocation::format($ticket->longitude))
                        <div class="sm:col-span-2">
                            <dt class="text-xs text-slate-500">Titik Lokasi</dt>
                            <dd class="mt-1 space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono font-medium">{{ $coordinates }}</span>
                                    <a href="{{ $ticket->mapsUrl() }}" target="_blank" rel="noopener noreferrer" class="btn-secondary px-3 py-1.5 text-xs">
                                        <i data-lucide="map-pin" class="h-3.5 w-3.5 text-rose-600"></i>Buka di Google Maps
                                    </a>
                                </div>
                                <iframe src="{{ 'https://maps.google.com/maps?q='.str_replace(' ', '', $coordinates).'&z=16&output=embed' }}" title="Peta lokasi pelanggan"
                                    class="aspect-video w-full rounded-lg border border-slate-200" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                            </dd>
                        </div>
                    @endif
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-slate-500">Kendala</dt>
                        <dd class="mt-1 rounded-lg bg-slate-50 p-3 whitespace-pre-line wrap-break-word">{{ $ticket->complaint }}</dd>
                    </div>
                </dl>
            </section>

            {{-- Handling --}}
            <section class="card p-5" x-data="{ editing: @js($errors->hasAny(['handling', 'remote_resolution_notes', 'technician_notes']) || ($errors->has('modem') && ! $canResolve)) }">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 class="flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="clipboard-list" class="h-4 w-4"></i>Penanganan</h2>
                    @if ($canEditHandling)
                        <button type="button" x-show="! editing" @click="editing = true" class="btn-secondary px-3 py-1.5 text-xs"><i data-lucide="pencil" class="h-3.5 w-3.5"></i>Ubah</button>
                    @endif
                </div>

                @if ($canEditHandling)
                    <form method="POST" action="{{ route('tickets.handling.update', $ticket) }}" x-show="editing" x-cloak class="space-y-4">
                        @csrf
                        @method('PUT')
                        <div>
                            <label class="label" for="edit_modem">Modem / ONT Pelanggan</label>
                            <input id="edit_modem" name="modem" value="{{ old('modem', $ticket->modem) }}" list="edit_modem_suggestions" maxlength="100" class="input" placeholder="Contoh: ZTE F609">
                            <datalist id="edit_modem_suggestions">
                                @foreach ($modemSuggestions as $suggestion)
                                    <option value="{{ $suggestion }}">
                                @endforeach
                            </datalist>
                            <x-field-error name="modem" />
                        </div>
                        @foreach (['remote_resolution_notes' => 'Solusi Remote', 'technician_notes' => 'Laporan Teknisi'] as $field => $label)
                            @if ($ticket->$field)
                                <div>
                                    <label class="label" for="edit_{{ $field }}">{{ $label }}</label>
                                    <textarea id="edit_{{ $field }}" name="{{ $field }}" rows="3" class="input" required maxlength="2000">{{ old($field, $ticket->$field) }}</textarea>
                                    <x-field-error :name="$field" />
                                </div>
                            @endif
                        @endforeach
                        <x-field-error name="handling" />
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="editing = false" class="btn-secondary">Batal</button>
                            <button class="btn-primary"><i data-lucide="save" class="h-4 w-4"></i>Simpan</button>
                        </div>
                    </form>
                @endif

                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2" x-show="! editing">
                    <div><dt class="text-xs text-slate-500">Helpdesk</dt><dd class="font-medium">{{ $ticket->helpdesk?->name ?? 'Belum diambil' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Teknisi</dt><dd class="font-medium">{{ $ticket->technicianNames() ?: '-' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Modem / ONT</dt><dd class="font-medium">{{ $ticket->modem ?? '-' }}</dd></div>
                    @if ($ticket->repair_started_at)
                        <div><dt class="text-xs text-slate-500">Mulai Perbaikan</dt><dd class="font-medium">{{ $ticket->repair_started_at->format('d/m/Y H:i') }}</dd></div>
                    @endif
                    @if ($ticket->resolved_at)
                        <div><dt class="text-xs text-slate-500">Diselesaikan</dt><dd class="font-medium">{{ $ticket->resolved_at->format('d/m/Y H:i') }}</dd></div>
                    @endif
                    @if ($ticket->closed_at)
                        <div><dt class="text-xs text-slate-500">Ditutup</dt><dd class="font-medium">{{ $ticket->closed_at->format('d/m/Y H:i') }}</dd></div>
                    @endif
                    @foreach (['remote_resolution_notes' => 'Solusi Remote', 'technician_notes' => 'Laporan Teknisi', 'confirmation_notes' => 'Konfirmasi Pelanggan'] as $field => $label)
                        @if ($ticket->$field)
                            <div class="sm:col-span-2"><dt class="text-xs text-slate-500">{{ $label }}</dt><dd class="mt-1 rounded-lg bg-slate-50 p-3 whitespace-pre-line wrap-break-word">{{ $ticket->$field }}</dd></div>
                        @endif
                    @endforeach
                    @if ($ticket->materialRequests->isNotEmpty())
                        <div class="sm:col-span-2">
                            <dt class="text-xs text-slate-500">Material Terpakai</dt>
                            <dd class="mt-1 space-y-2">
                                @foreach ($ticket->materialRequests as $materialRequest)
                                    @include('warehouses.partials.material-request', ['showTicket' => false])
                                @endforeach
                            </dd>
                        </div>
                    @endif
                </dl>
            </section>

        </div>

        {{-- History --}}
        <div class="order-last lg:order-none lg:col-span-2">
            <section class="card p-5">
                <h2 class="mb-4 flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="history" class="h-4 w-4"></i>Riwayat Tiket</h2>
                @include('tickets.partials.history')
            </section>
        </div>

        {{-- Actions: the step that moves the ticket on comes first, notes that leave the status alone are folded below it. --}}
        <aside class="space-y-4 lg:col-start-3 lg:row-span-2 lg:row-start-1">
            <div class="flex items-center justify-between gap-2 px-1">
                <h2 class="flex items-center gap-2 text-xs font-bold tracking-wider text-indigo-700 uppercase">
                    <span class="flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-white"><i data-lucide="arrow-right" class="h-3 w-3"></i></span>Langkah Berikutnya
                </h2>
                <span class="text-xs text-slate-500">Tahap: <span class="font-semibold text-slate-700">{{ $status->label() }}</span></span>
            </div>

            @if ($canForward)
                <form method="POST" action="{{ $actionUrl(A::TERUSKAN_HELPDESK) }}" class="{{ $stepCard }} space-y-3">
                    @csrf
                    <h3 class="flex items-center gap-2 font-semibold"><i data-lucide="send" class="h-4 w-4 text-cyan-600"></i>Teruskan ke Helpdesk</h3>
                    <p class="text-sm text-slate-500">Koordinasi dengan pelanggan sudah selesai dan kendala perlu dicek tim teknis? Teruskan tiket ke Antrian Helpdesk.</p>
                    <textarea name="notes" rows="3" class="input" placeholder="Hasil koordinasi untuk Helpdesk (opsional). Contoh: Lampu LOS merah, pelanggan sudah restart modem"></textarea>
                    <button class="btn-primary w-full"><i data-lucide="send" class="h-4 w-4"></i>Teruskan ke Antrian Helpdesk</button>
                </form>
            @endif

            @if ($canCsClose)
                @if ($canForward)
                    <p class="px-1 text-center text-xs font-semibold tracking-wider text-slate-400 uppercase">atau</p>
                @endif
                <form method="POST" action="{{ $actionUrl(A::SELESAI_EDUKASI_CS) }}" class="{{ $stepCard }} space-y-3"
                    data-confirm="Tutup tiket ini? Pastikan pelanggan sudah konfirmasi koneksi normal.">
                    @csrf
                    <h3 class="flex items-center gap-2 font-semibold"><i data-lucide="plug-zap" class="h-4 w-4 text-emerald-600"></i>Selesai dengan Edukasi</h3>
                    <p class="text-sm text-slate-500">Kendala sudah beres setelah pelanggan diarahkan (cabut-tancap adaptor, restart modem, dll)? Tutup tiket tanpa perlu ke Helpdesk.</p>
                    <textarea name="notes" rows="3" class="input" required placeholder="Contoh: Pelanggan diarahkan cabut-tancap adaptor ONT, lampu PON hijau, koneksi normal"></textarea>
                    <x-field-error name="notes" />
                    <button class="btn-success w-full"><i data-lucide="check-circle-2" class="h-4 w-4"></i>Tutup Tiket</button>
                </form>
            @endif

            @if ($canClaim)
                <form method="POST" action="{{ $actionUrl(A::KLAIM_HELPDESK) }}" class="{{ $stepCard }}">
                    @csrf
                    <h3 class="mb-1 font-semibold">Ambil Tiket</h3>
                    <p class="mb-3 text-sm text-slate-500">Tiket sedang menunggu tim Helpdesk. Ambil untuk mulai pengecekan.</p>
                    <button class="btn-primary w-full"><i data-lucide="hand" class="h-4 w-4"></i>Klaim & Mulai Cek</button>
                </form>
            @endif

            @if ($canResolve)
                @php($queued = $status === S::ANTRIAN_TEKNISI ? $ticket->histories->where('action', A::ANTRI_TEKNISI)->last() : null)
                <div class="{{ $stepCard }}" x-data="{ mode: @js($queued ? 'teknisi' : 'remote'), modem: @js(old('modem', $ticket->modem ?? '')) }">
                    <h3 class="mb-3 font-semibold">{{ $queued ? 'Antrian Teknisi' : 'Hasil Pengecekan Helpdesk' }}</h3>
                    @if ($queued)
                        <p class="mb-4 flex items-start gap-2 rounded-lg bg-yellow-50 p-3 text-xs text-yellow-800">
                            <i data-lucide="hourglass" class="mt-0.5 h-3.5 w-3.5 shrink-0"></i>
                            <span>Menunggu teknisi sejak {{ $queued->created_at->format('d/m/Y H:i') }} ({{ $queued->created_at->diffForHumans() }}). {{ $queued->notes }}</span>
                        </p>
                    @endif
                    <div class="mb-4">
                        <label class="label" for="modem">Modem / ONT Pelanggan</label>
                        <input id="modem" x-model="modem" list="modem_suggestions" maxlength="100" class="input" placeholder="Contoh: ZTE F609">
                        <datalist id="modem_suggestions">
                            @foreach ($modemSuggestions as $suggestion)
                                <option value="{{ $suggestion }}">
                            @endforeach
                        </datalist>
                        <x-field-error name="modem" />
                    </div>
                    <span class="label">Pilih hasil pengecekan</span>
                    <div @class(['mb-4 grid gap-2', 'grid-cols-3' => $canQueue, 'grid-cols-2' => ! $canQueue])>
                        <button type="button" @click="mode = 'remote'" :class="mode === 'remote' ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-slate-200 text-slate-600'" class="rounded-lg border-2 px-2 py-2 text-xs font-semibold">
                            Bisa Selesai Remote
                        </button>
                        <button type="button" @click="mode = 'teknisi'" :class="mode === 'teknisi' ? 'border-orange-500 bg-orange-50 text-orange-700' : 'border-slate-200 text-slate-600'" class="rounded-lg border-2 px-2 py-2 text-xs font-semibold">
                            Perlu Teknisi
                        </button>
                        @if ($canQueue)
                            <button type="button" @click="mode = 'pending'" :class="mode === 'pending' ? 'border-yellow-500 bg-yellow-50 text-yellow-700' : 'border-slate-200 text-slate-600'" class="rounded-lg border-2 px-2 py-2 text-xs font-semibold">
                                Pending Kunjungan
                            </button>
                        @endif
                    </div>

                    <form method="POST" action="{{ $actionUrl(A::SELESAI_REMOTE) }}" x-show="mode === 'remote'" class="space-y-3"
                        data-confirm="Tandai selesai remote? Tiket lanjut ke Menunggu Konfirmasi pelanggan.">
                        @csrf
                        <input type="hidden" name="modem" :value="modem">
                        <label class="label" for="remote_notes">Tindakan yang dilakukan</label>
                        <textarea id="remote_notes" name="notes" rows="3" class="input" placeholder="Reboot ONT, reset modem, clear MAC, dll"></textarea>
                        <button class="btn-success w-full"><i data-lucide="zap" class="h-4 w-4"></i>Selesai Remote</button>
                    </form>

                    <form method="POST" action="{{ $actionUrl(A::PERLU_TEKNISI) }}" x-show="mode === 'teknisi'" x-cloak class="space-y-3">
                        @csrf
                        <input type="hidden" name="modem" :value="modem">
                        <div>
                            <span class="label">Teknisi</span>
                            <x-technician-picker :technicians="$technicians" :selected="$ticket->technicians->modelKeys()" />
                        </div>
                        <div>
                            <label class="label" for="tech_notes">Instruksi untuk teknisi</label>
                            <textarea id="tech_notes" name="notes" rows="3" class="input" placeholder="Cek redaman di ODP, ganti konektor, dll"></textarea>
                        </div>
                        <button class="btn-warning w-full"><i data-lucide="wrench" class="h-4 w-4"></i>Tugaskan Teknisi</button>
                    </form>

                    @if ($canQueue)
                        {{-- For when a visit is needed but every technician's route is already full today. --}}
                        <form method="POST" action="{{ $actionUrl(A::ANTRI_TEKNISI) }}" x-show="mode === 'pending'" x-cloak class="space-y-3">
                            @csrf
                            <input type="hidden" name="modem" :value="modem">
                            <p class="text-xs text-slate-500">Perlu kunjungan teknisi, tapi belum ada teknisi yang tersedia. Tiket masuk Antrian Teknisi dan bisa ditugaskan nanti dari tiket ini.</p>
                            <div>
                                <label class="label" for="queue_notes">Kendala yang perlu dicek di lokasi</label>
                                <textarea id="queue_notes" name="notes" rows="3" class="input" required placeholder="Contoh: LOS merah, perlu cek ODP. Pelanggan bisa dikunjungi besok pagi"></textarea>
                            </div>
                            <button class="btn-warning w-full"><i data-lucide="hourglass" class="h-4 w-4"></i>Masukkan Antrian Teknisi</button>
                        </form>
                    @endif
                </div>
            @endif

            @if ($canRepair)
                @if (! $ticket->repair_started_at)
                    <form method="POST" action="{{ $actionUrl(A::MULAI_PERBAIKAN) }}" class="{{ $stepCard }}">
                        @csrf
                        <h3 class="mb-1 font-semibold">Mulai Perbaikan</h3>
                        @if ($isFieldTechnician)
                            <p class="mb-3 text-sm text-slate-500">Tekan saat Anda sudah tiba di lokasi pelanggan.</p>
                            <button class="btn-warning w-full"><i data-lucide="play" class="h-4 w-4"></i>Saya Sudah di Lokasi</button>
                        @else
                            <p class="mb-3 text-sm text-slate-500">Catat saat teknisi <span class="font-medium text-slate-700">{{ $ticket->technicianNames() ?: '-' }}</span> mengabarkan sudah tiba di lokasi (mis. lewat Telegram).</p>
                            <button class="btn-warning w-full"><i data-lucide="play" class="h-4 w-4"></i>Teknisi Sudah di Lokasi</button>
                        @endif
                    </form>
                @else
                    <form method="POST" action="{{ $actionUrl(A::LAPOR_PERBAIKAN_SELESAI) }}" enctype="multipart/form-data" class="{{ $stepCard }} space-y-3"
                        x-data="repairMaterials(@js($materialForm))" data-confirm="Kirim laporan perbaikan selesai? Tiket lanjut ke Menunggu Konfirmasi pelanggan.">
                        @csrf
                        <h3 class="font-semibold">Resume Perbaikan</h3>
                        <p class="text-sm text-slate-500">
                            Dimulai {{ $ticket->repair_started_at->diffForHumans() }}.
                            @unless ($isFieldTechnician)
                                Isi hasil pekerjaan teknisi <span class="font-medium text-slate-700">{{ $ticket->technicianNames() ?: '-' }}</span> dan lampirkan foto/video dari mereka.
                            @endunless
                        </p>
                        <textarea name="notes" rows="4" class="input" required placeholder="Tindakan fisik: splicing FO, ganti konektor, redaman akhir, dll">{{ old('notes') }}</textarea>

                        @include('tickets.partials.material-lines')

                        <x-photo-input id="repair_photos" />
                        <button class="btn-success w-full"><i data-lucide="check-circle-2" class="h-4 w-4"></i>Kirim Laporan Selesai</button>
                    </form>
                @endif
            @endif

            @if ($canResubmitMaterial)
                @php($rejected = $ticket->materialRequests->last())
                <form method="POST" action="{{ route('tickets.material.resubmit', $ticket) }}" class="{{ $stepCard }} space-y-3" x-data="repairMaterials(@js($materialForm))">
                    @csrf
                    <h3 class="flex items-center gap-2 font-semibold"><i data-lucide="refresh-cw" class="h-4 w-4 text-rose-600"></i>Ajukan Ulang Material</h3>
                    <p class="rounded-lg bg-rose-50 p-2 text-sm text-rose-800">
                        Ditolak {{ $rejected->reviewer?->name ?? 'Gudang' }}: {{ $rejected->review_notes }}
                    </p>
                    <p class="text-sm text-slate-500">Isi material yang benar-benar terpakai. Stok baru berkurang setelah di-ACC Gudang.</p>
                    @include('tickets.partials.material-lines')
                    <button class="btn-primary w-full"><i data-lucide="send" class="h-4 w-4"></i>Ajukan ke Gudang</button>
                </form>
            @endif

            @if ($canConfirm)
                <div class="{{ $stepCard }} space-y-3" x-data="{ notes: '' }">
                    <h3 class="font-semibold">Konfirmasi ke Pelanggan</h3>
                    <ol class="list-inside list-decimal space-y-1 text-sm text-slate-500">
                        <li>Kirim pesan konfirmasi via WhatsApp.</li>
                        <li>Catat jawaban pelanggan, lalu pilih hasilnya.</li>
                    </ol>
                    @if ($ticket->autoCloseAt())
                        <p class="flex items-start gap-1 rounded-lg bg-amber-50 p-2 text-xs text-amber-800">
                            <i data-lucide="timer" class="mt-0.5 h-3.5 w-3.5 shrink-0"></i>
                            Jika pelanggan tidak merespon, tiket ditutup otomatis {{ $ticket->autoCloseAt()->format('d/m/Y H:i') }} ({{ $ticket->autoCloseAt()->diffForHumans() }}).
                        </p>
                    @endif
                    <a href="{{ route('tickets.whatsapp', [$ticket, 'type' => 'konfirmasi']) }}" target="_blank" rel="noopener" class="btn-secondary w-full">
                        <i data-lucide="message-circle" class="h-4 w-4 text-emerald-600"></i>Kirim Pesan Konfirmasi
                    </a>
                    <textarea x-model="notes" rows="3" class="input" placeholder="Catatan jawaban pelanggan (opsional)"></textarea>
                    <span class="label">Hasil konfirmasi pelanggan</span>
                    <div class="grid grid-cols-2 gap-3">
                        <form method="POST" action="{{ $actionUrl(A::KONFIRMASI_NORMAL) }}" data-confirm="Pelanggan menyatakan NORMAL? Tiket akan ditutup.">
                            @csrf
                            <input type="hidden" name="notes" :value="notes">
                            <button class="btn-success w-full"><i data-lucide="check" class="h-4 w-4"></i>Normal</button>
                            <p class="mt-1 text-center text-xs text-slate-500">Tiket ditutup</p>
                        </form>
                        <form method="POST" action="{{ $actionUrl(A::KONFIRMASI_TERKENDALA) }}" data-confirm="Pelanggan masih TERKENDALA? Tiket dikembalikan ke Helpdesk untuk cek ulang.">
                            @csrf
                            <input type="hidden" name="notes" :value="notes">
                            <button class="btn-danger w-full"><i data-lucide="x" class="h-4 w-4"></i>Terkendala</button>
                            <p class="mt-1 text-center text-xs text-slate-500">Kembali ke Helpdesk</p>
                        </form>
                    </div>
                </div>
            @endif

            @unless ($hasNextStep)
                <div class="card p-5 text-sm text-slate-500">
                    @if ($status === S::TUTUP)
                        <p class="flex items-center gap-2 font-medium text-emerald-700"><i data-lucide="check-circle-2" class="h-4 w-4"></i>Tiket sudah selesai dan ditutup.</p>
                    @elseif ($status === S::GAMAS)
                        <p class="flex items-start gap-2"><i data-lucide="radio-tower" class="mt-0.5 h-4 w-4 shrink-0 text-red-600"></i>Menunggu gangguan massal selesai. Tiket otomatis lanjut ke konfirmasi.</p>
                    @else
                        <p class="flex items-start gap-2"><i data-lucide="info" class="mt-0.5 h-4 w-4 shrink-0"></i>Belum ada langkah untuk peran Anda di tahap ini.</p>
                    @endif
                </div>
            @endunless

            @if ($canReassign || $canRequeue)
                {{-- Changes to a visit already handed out: kept apart from the report so they are not pressed by mistake. --}}
                <section class="card divide-y divide-slate-100" x-data="{ open: @js(in_array($openPanel, ['reassign', 'requeue'], true) ? $openPanel : null) }">
                    <div class="px-5 py-3">
                        <h3 class="flex items-center gap-2 text-sm font-semibold text-slate-700"><i data-lucide="wrench" class="h-4 w-4 text-orange-500"></i>Kunjungan Teknisi</h3>
                        <p class="mt-1 text-sm text-slate-600">{{ $ticket->technicianNames() ?: '-' }}</p>
                        <p class="text-xs text-slate-500">{{ $ticket->repair_started_at ? 'Pengerjaan dimulai '.$ticket->repair_started_at->diffForHumans() : 'Belum mulai pengerjaan' }}</p>
                    </div>
                    @if ($canReassign)
                        <x-action-panel name="reassign" icon="users" color="text-orange-500" title="Ganti / Tambah Teknisi" hint="Teknisi berhalangan atau butuh tenaga tambahan">
                            <form method="POST" action="{{ $actionUrl(A::UBAH_TEKNISI) }}" class="space-y-3">
                                @csrf
                                <input type="hidden" name="_panel" value="reassign">
                                <div>
                                    <span class="label">Teknisi baru</span>
                                    <x-technician-picker :technicians="$technicians" :selected="$ticket->technicians->modelKeys()" />
                                </div>
                                <div>
                                    <label class="label" for="reassign_notes">Alasan perubahan</label>
                                    <textarea id="reassign_notes" name="notes" rows="2" class="input" required placeholder="Contoh: Teknisi sebelumnya sakit / butuh tenaga tambahan untuk tarik kabel">{{ old('_panel') === 'reassign' ? old('notes') : '' }}</textarea>
                                    <x-field-error name="notes" />
                                </div>
                                <p class="text-xs text-slate-500">Jika semua teknisi diganti, pengerjaan harus dimulai ulang oleh teknisi baru.</p>
                                <button class="btn-warning w-full"><i data-lucide="save" class="h-4 w-4"></i>Simpan Teknisi</button>
                            </form>
                        </x-action-panel>
                    @endif
                    @if ($canRequeue)
                        {{-- For a visit that stalls after it was handed out: no material yet, customer away, bad weather. --}}
                        <x-action-panel name="requeue" icon="hourglass" color="text-yellow-600" title="Pending Kunjungan" hint="Kembalikan ke Antrian Teknisi jika pengerjaan tertunda">
                            <form method="POST" action="{{ $actionUrl(A::ANTRI_TEKNISI) }}" class="space-y-3"
                                data-confirm="Pending kunjungan? Penugasan teknisi dilepas dan tiket kembali ke Antrian Teknisi.">
                                @csrf
                                <input type="hidden" name="requeue" value="1">
                                <input type="hidden" name="_panel" value="requeue">
                                <div>
                                    <label class="label" for="requeue_notes">Alasan pending</label>
                                    <textarea id="requeue_notes" name="notes" rows="2" class="input" required placeholder="Contoh: Material kabel belum tersedia / pelanggan tidak di rumah sampai besok">{{ old('requeue') ? old('notes') : '' }}</textarea>
                                </div>
                                <p class="text-xs text-slate-500">Penugasan teknisi <span class="font-medium text-slate-700">{{ $ticket->technicianNames() ?: '-' }}</span> dilepas. Teknisi berikutnya memulai kunjungan dari awal.</p>
                                <button class="btn-warning w-full"><i data-lucide="hourglass" class="h-4 w-4"></i>Masukkan ke Antrian</button>
                            </form>
                        </x-action-panel>
                    @endif
                </section>
            @endif

            @if ($ticket->incident)
                <div class="card space-y-3 border-l-4 border-l-red-600 p-5">
                    <h3 class="flex items-center gap-2 font-semibold"><i data-lucide="radio-tower" class="h-4 w-4 text-red-600"></i>Bagian dari Gamas</h3>
                    <div>
                        <a href="{{ route('incidents.show', $ticket->incident) }}" class="font-mono text-sm font-semibold text-indigo-700 hover:underline">{{ $ticket->incident->incident_number }}</a>
                        <span class="badge ml-1 {{ $ticket->incident->status->badgeClasses() }}">{{ $ticket->incident->status->label() }}</span>
                    </div>
                    <p class="text-sm text-slate-600">{{ $ticket->incident->title }} · {{ $ticket->incident->area }}</p>
                    @if ($ticket->incident->estimated_resolved_at && $ticket->incident->isOpen())
                        <p class="text-xs text-slate-500">Estimasi selesai {{ $ticket->incident->estimated_resolved_at->format('d/m/Y H:i') }}</p>
                    @endif
                    @if ($status === S::GAMAS && $ticket->incident->isOpen() && auth()->user()->can('perform', [$ticket->incident, App\Enums\IncidentAction::TIKET_DILEPAS]))
                        <form method="POST" action="{{ route('incidents.tickets.detach', [$ticket->incident, $ticket]) }}" data-confirm="Lepas tiket ini dari gamas? Tiket akan kembali ke Antrian Helpdesk.">
                            @csrf
                            @method('DELETE')
                            <button class="btn-secondary w-full text-xs"><i data-lucide="unlink" class="h-4 w-4"></i>Bukan karena gamas, lepas tiket</button>
                        </form>
                    @endif
                </div>
            @endif

            @if ($hasNotes)
                @include('tickets.partials.notes-form')
            @endif

            @if ($hasMore)
                <div x-data="{ open: @js($openPanel) }">
                        <section class="card divide-y divide-slate-100 overflow-hidden">
                            <div class="px-5 py-3">
                                <h3 class="flex items-center gap-2 text-sm font-semibold text-slate-700"><i data-lucide="settings-2" class="h-4 w-4 text-slate-500"></i>Lainnya</h3>
                            </div>

                            @if ($canRecategorize)
                                <x-action-panel name="category" icon="tag" title="Ubah Kategori Kendala" :hint="'Sekarang: '.$ticket->category">
                                    <form method="POST" action="{{ $actionUrl(A::UBAH_KATEGORI) }}" class="space-y-3">
                                        @csrf
                                        <input type="hidden" name="_panel" value="category">
                                        <p class="text-sm text-slate-500">Sesuaikan kategori jika hasil pengecekan berbeda dari laporan awal CS.</p>
                                        <div>
                                            <label class="label" for="recategorize_category">Kategori sebenarnya</label>
                                            <x-option-search name="category" id="recategorize_category" :options="$categories" :selected="old('category', $ticket->category)"
                                                placeholder="Ketik untuk mencari kategori" message="Pilih kategori yang ada di daftar" />
                                            <x-field-error name="category" />
                                        </div>
                                        <textarea name="notes" rows="2" class="input" maxlength="2000" placeholder="Alasan (opsional), contoh: Dicek di lokasi ternyata LOS merah, kabel drop putus"></textarea>
                                        <button class="btn-primary w-full"><i data-lucide="save" class="h-4 w-4"></i>Simpan Kategori</button>
                                    </form>
                                </x-action-panel>
                            @endif

                            @if ($canJoinIncident)
                                <x-action-panel name="incident" icon="radio-tower" color="text-red-600" title="Masukkan ke Gamas" hint="Kendala ini bagian dari gangguan massal?">
                                    @if ($openIncidents->isEmpty())
                                        <p class="mb-3 text-sm text-slate-500">Belum ada gamas yang aktif.</p>
                                        @can('create', App\Models\Incident::class)
                                            <a href="{{ route('incidents.create') }}" class="btn-secondary w-full text-xs"><i data-lucide="plus" class="h-4 w-4"></i>Buat Gamas Baru</a>
                                        @endcan
                                    @else
                                        <form method="POST" x-data="{ url: '' }" :action="url" class="space-y-3" data-confirm="Masukkan tiket ini ke gamas yang dipilih?">
                                            @csrf
                                            <input type="hidden" name="ticket_ids[]" value="{{ $ticket->id }}">
                                            <p class="text-sm text-slate-500">Tautkan supaya ditangani bersama.</p>
                                            <select x-model="url" class="input" required aria-label="Pilih gamas">
                                                <option value="">Pilih gamas</option>
                                                @foreach ($openIncidents as $incident)
                                                    <option value="{{ route('incidents.tickets.link', $incident) }}">{{ $incident->incident_number }} · {{ $incident->title }} ({{ $incident->area }})</option>
                                                @endforeach
                                            </select>
                                            <button class="btn-danger w-full" :disabled="! url"><i data-lucide="link" class="h-4 w-4"></i>Masukkan ke Gamas</button>
                                        </form>
                                    @endif
                                </x-action-panel>
                            @endif

                            @can('share', $ticket)
                                {{-- Read-only access to this one ticket for a technician who is not its crew, e.g. to look up a past visit. --}}
                                <x-action-panel name="share" icon="share-2" title="Bagikan ke Teknisi"
                                    :hint="$shares->isNotEmpty() ? $shares->count().' akses aktif · lihat saja, berakhir otomatis' : 'Akses lihat saja ke tiket ini, berakhir otomatis'">
                                    <div class="space-y-3" x-data="{ copied: false }">
                                        @if ($shares->isNotEmpty())
                                            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white text-sm">
                                                @foreach ($shares as $share)
                                                    <li class="flex items-center justify-between gap-2 px-3 py-2">
                                                        <div class="min-w-0">
                                                            <div class="truncate font-medium text-slate-800">{{ $share->user?->name }}</div>
                                                            <div class="text-xs text-slate-500">Sampai {{ $share->expires_at->format('d/m/Y H:i') }} · oleh {{ $share->sharedBy?->name ?? '-' }}</div>
                                                        </div>
                                                        <form method="POST" action="{{ route('tickets.shares.destroy', [$ticket, $share]) }}" data-confirm="{{ 'Cabut akses '.$share->user?->name.' ke tiket ini?' }}">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button class="text-xs font-semibold text-rose-600 hover:underline">Cabut</button>
                                                        </form>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif

                                        <form method="POST" action="{{ route('tickets.shares.store', $ticket) }}" class="space-y-3">
                                            @csrf
                                            <input type="hidden" name="_panel" value="share">
                                            <div>
                                                <label class="label" for="share_user">Teknisi</label>
                                                <select id="share_user" name="user_id" class="input" required>
                                                    <option value="">Pilih teknisi</option>
                                                    @foreach ($technicians as $technician)
                                                        <option value="{{ $technician->id }}" @selected(old('user_id') == $technician->id)>{{ $technician->name }}</option>
                                                    @endforeach
                                                </select>
                                                <x-field-error name="user_id" />
                                            </div>
                                            <div>
                                                <label class="label" for="share_days">Lama akses</label>
                                                <select id="share_days" name="days" class="input">
                                                    @foreach ($shareDurations as $days)
                                                        <option value="{{ $days }}" @selected($days === 1)>{{ $days }} hari</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <p class="text-xs text-slate-500">Teknisi mendapat notifikasi berisi link. Link tetap butuh login, jadi aman dikirim lewat WhatsApp/Telegram.</p>
                                            <div class="grid grid-cols-2 gap-2">
                                                <button type="button" @click="navigator.clipboard?.writeText(@js(route('tickets.show', $ticket))).then(() => copied = true)" class="btn-secondary">
                                                    <i data-lucide="link" class="h-4 w-4"></i><span x-text="copied ? 'Tersalin' : 'Salin Link'"></span>
                                                </button>
                                                <button class="btn-primary"><i data-lucide="share-2" class="h-4 w-4"></i>Bagikan</button>
                                            </div>
                                        </form>
                                    </div>
                                </x-action-panel>
                            @endcan
                        </section>
                </div>
            @endif
        </aside>
    </div>
</x-layouts.app>
