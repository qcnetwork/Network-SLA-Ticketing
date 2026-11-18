@use('App\Enums\TicketAction', 'A')
@use('App\Enums\TicketStatus', 'S')
<x-layouts.app :title="$ticket->ticket_number">
    @php
        $status = $ticket->status;
        $user = auth()->user();
        $can = fn (A $action) => $user->can('perform', [$ticket, $action]);
        $actionUrl = fn (A $action) => route('tickets.perform', [$ticket, $action->value]);
        $isFieldTechnician = $user->role === App\Enums\Role::TEKNISI;
        $open = ! $status->isFinal();

        $canDecide = $can(A::PSB_LANGSUNG_PASANG) && $status === S::PSB_CEK_LOKASI;
        $canAssignSurvey = $can(A::PSB_TUGAS_SURVEY) && $status === S::PSB_ANTRIAN_SURVEY;
        $canSurveyResult = $can(A::PSB_SURVEY_LAYAK) && $status === S::PSB_SURVEY;
        $canAssignInstall = $can(A::PSB_TUGAS_PASANG) && $status === S::PSB_ANTRIAN_PASANG;
        $canInstall = $can(A::PSB_MULAI_PASANG) && $status === S::PSB_PEMASANGAN;
        $canActivate = $can(A::PSB_AKTIF) && $status === S::PSB_KONFIRMASI;
        $canReassign = $can(A::UBAH_TEKNISI) && $status->isFieldWork();
        $canRequeue = $can(A::PSB_KEMBALI_ANTRIAN) && $status->isFieldWork();
        $canCancel = $can(A::PSB_BATAL) && $open;
        $canEditData = $can(A::PSB_UBAH_DATA);
        $canEditCustomer = $can(A::UBAH_DATA_PELANGGAN) && $open;
        // Read by the shared notes form.
        $canUrgency = $can(A::CATATAN_URGENSI_CS) && $open;
        $canHelpdeskInfo = $can(A::CATATAN_INFO_HELPDESK) && $open;
        $canDocument = $can(A::CATATAN_DOKUMENTASI) && $open;
        $canResubmitMaterial = $user->can('resubmitMaterial', $ticket) && ! ($canInstall && $ticket->repair_started_at);

        $hasNextStep = $canDecide || $canAssignSurvey || $canSurveyResult || $canAssignInstall || $canInstall || $canActivate || $canResubmitMaterial;
        $hasNotes = $canUrgency || $canHelpdeskInfo || $canDocument;
        $openPanel = $errors->any() ? old('_panel') : null;
        $stepCard = 'card border-t-4 border-t-indigo-600 p-5';

        $materialForm = [
            'lines' => array_values(old('materials', [])),
            'technicians' => $stockHolders,
            'stock' => (object) $crewStock,
            'unitsUrl' => route('tickets.material.units', $ticket),
            'defaultTechnician' => $isFieldTechnician && isset($crewStock[$user->id]) ? $user->id : (count($stockHolders) === 1 ? $stockHolders[0]['id'] : ''),
        ];

        // The survey step only shows when the order went through one (or could still).
        $surveyed = $ticket->histories->contains('action', A::PSB_PERLU_SURVEY);
        if (! $surveyed && $status !== S::PSB_CEK_LOKASI) {
            $flow = array_values(array_filter($flow, fn (S $step) => $step !== S::PSB_SURVEY));
        }
        $stepIndex = array_search(match ($status) {
            S::PSB_ANTRIAN_SURVEY => S::PSB_SURVEY,
            S::PSB_ANTRIAN_PASANG => S::PSB_PEMASANGAN,
            default => $status,
        }, $flow, true);
        $housePhotos = $ticket->histories->firstWhere('action', A::PSB_DIBUAT)?->attachments ?? collect();
        $waLink = 'https://wa.me/'.preg_replace('/\D/', '', $ticket->customer_phone);
    @endphp

    <a href="{{ route('installations.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-700">
        <i data-lucide="arrow-left" class="h-4 w-4"></i>Kembali ke daftar PSB
    </a>

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="font-mono text-2xl font-bold text-slate-900">{{ $ticket->ticket_number }}</h1>
                <span class="badge border-indigo-300 bg-indigo-50 text-indigo-700"><i data-lucide="house-plus" class="h-3.5 w-3.5"></i>Pasang Baru</span>
                <x-status-badge :status="$status" />
            </div>
            <p class="mt-1 text-sm text-slate-500">Dibuat {{ $ticket->created_at->format('d/m/Y H:i') }} oleh {{ $ticket->creator?->name ?? '-' }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @can('contactCustomer', $ticket)
                <a href="{{ $waLink }}" target="_blank" rel="noopener" class="btn-secondary text-xs">
                    <i data-lucide="message-circle" class="h-4 w-4 text-emerald-600"></i>Chat WhatsApp
                </a>
            @endcan
            @can('delete', $ticket)
                <form method="POST" action="{{ route('tickets.destroy', $ticket) }}" data-confirm="{{ 'Hapus PSB '.$ticket->ticket_number.'? Riwayat dan foto ikut terhapus permanen.' }}">
                    @csrf
                    @method('DELETE')
                    <button class="btn-danger text-xs"><i data-lucide="trash-2" class="h-4 w-4"></i>Hapus PSB</button>
                </form>
            @endcan
        </div>
    </div>

    {{-- Workflow stepper --}}
    <div class="card mb-6 overflow-x-auto p-4">
        @if ($status === S::PSB_BATAL)
            <p class="flex items-start gap-2 text-sm font-medium text-slate-700"><i data-lucide="x-circle" class="mt-0.5 h-4 w-4 shrink-0 text-slate-500"></i>PSB dibatalkan {{ $ticket->closed_at?->format('d/m/Y H:i') }}. {{ $ticket->confirmation_notes }}</p>
        @else
            <ol class="flex min-w-max items-center gap-2">
                @foreach ($flow as $i => $step)
                    <li class="flex items-center gap-2">
                        <span @class([
                            'flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold',
                            'border-indigo-600 bg-indigo-600 text-white' => $i === $stepIndex,
                            'border-emerald-300 bg-emerald-50 text-emerald-700' => $i < $stepIndex,
                            'border-slate-200 bg-white text-slate-400' => $i > $stepIndex,
                        ])>
                            <i data-lucide="{{ $i < $stepIndex ? 'check' : $step->icon() }}" class="h-3.5 w-3.5"></i>{{ $step === S::PSB_SELESAI ? 'Aktif' : preg_replace('/ \(Teknisi\)$/', '', $step->label()) }}
                        </span>
                        @unless ($loop->last)<i data-lucide="chevron-right" class="h-4 w-4 text-slate-300"></i>@endunless
                    </li>
                @endforeach
            </ol>
            @if (in_array($status, [S::PSB_ANTRIAN_SURVEY, S::PSB_ANTRIAN_PASANG], true))
                <p class="mt-3 flex items-center gap-1 text-xs font-medium text-yellow-700"><i data-lucide="hourglass" class="h-3.5 w-3.5"></i>Menunggu jadwal teknisi {{ $status === S::PSB_ANTRIAN_SURVEY ? 'survey' : 'pasang' }}.</p>
            @endif
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-3 lg:grid-rows-[auto_1fr]">
        <div class="space-y-6 lg:col-span-2">
            {{-- Prospective customer --}}
            <section class="card p-5" x-data="{ editing: @js($errors->hasAny(['customer', 'customer_name', 'customer_phone', 'customer_address'])) }">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 class="flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="user" class="h-4 w-4"></i>Calon Pelanggan</h2>
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
                                <label class="label" for="edit_customer_name">Nama Sesuai KTP <span class="text-rose-500">*</span></label>
                                <input id="edit_customer_name" name="customer_name" value="{{ old('customer_name', $ticket->customer_name) }}" class="input" required maxlength="150">
                                <x-field-error name="customer_name" />
                            </div>
                            <div>
                                <label class="label" for="edit_customer_phone">No. WhatsApp Aktif <span class="text-rose-500">*</span></label>
                                <input id="edit_customer_phone" name="customer_phone" value="{{ old('customer_phone', $ticket->customer_phone) }}" class="input" required inputmode="tel">
                                <x-field-error name="customer_phone" />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="label" for="edit_customer_address">Shareloc / Alamat</label>
                                <input id="edit_customer_address" name="customer_address" value="{{ old('customer_address', $ticket->customer_address) }}" class="input" maxlength="255">
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
                    <div><dt class="text-xs text-slate-500">Nama (KTP)</dt><dd class="font-medium">{{ $ticket->customer_name }}</dd></div>
                    <div><dt class="text-xs text-slate-500">WhatsApp</dt><dd class="font-medium">{{ $ticket->customer_phone }}</dd></div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-slate-500">Shareloc / Alamat</dt>
                        <dd class="font-medium break-all">
                            @if (Str::startsWith($ticket->customer_address, ['http://', 'https://']))
                                <a href="{{ $ticket->customer_address }}" target="_blank" rel="noopener noreferrer" class="text-indigo-700 hover:underline">{{ $ticket->customer_address }}</a>
                            @else
                                {{ $ticket->customer_address ?: '-' }}
                            @endif
                        </dd>
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
                                <iframe src="{{ 'https://maps.google.com/maps?q='.str_replace(' ', '', $coordinates).'&z=17&output=embed' }}" title="Peta lokasi calon pelanggan"
                                    class="aspect-video w-full rounded-lg border border-slate-200" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                            </dd>
                        </div>
                    @endif
                    @if ($housePhotos->isNotEmpty())
                        <div class="sm:col-span-2">
                            <dt class="text-xs text-slate-500">Foto Depan Rumah</dt>
                            <dd><x-attachment-grid :attachments="$housePhotos" route="tickets.attachment" :parent="$ticket" /></dd>
                        </div>
                    @endif
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-slate-500">Catatan Order</dt>
                        <dd class="mt-1 rounded-lg bg-slate-50 p-3 whitespace-pre-line wrap-break-word">{{ $ticket->complaint }}</dd>
                    </div>
                </dl>
            </section>

            {{-- Installation data --}}
            <section class="card p-5" x-data="{ editing: @js($errors->has('installation') || $errors->hasAny(['package', 'sales', 'odp_location', 'odp_distance']) && old('_panel') === 'data') }">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 class="flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="clipboard-list" class="h-4 w-4"></i>Data Pemasangan</h2>
                    @if ($canEditData)
                        <button type="button" x-show="! editing" @click="editing = true" class="btn-secondary px-3 py-1.5 text-xs"><i data-lucide="pencil" class="h-3.5 w-3.5"></i>Ubah</button>
                    @endif
                </div>

                @if ($canEditData)
                    <form method="POST" action="{{ route('installations.update', $ticket) }}" x-show="editing" x-cloak class="space-y-4">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="_panel" value="data">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="label" for="edit_package">Paket Layanan</label>
                                <select id="edit_package" name="package" class="input">
                                    <option value="">-</option>
                                    @foreach (collect($packages)->push($ticket->package)->filter()->unique() as $package)
                                        <option value="{{ $package }}" @selected(old('package', $ticket->package) === $package)>{{ $package }}</option>
                                    @endforeach
                                </select>
                                <x-field-error name="package" />
                            </div>
                            <div>
                                <label class="label" for="edit_sales">Sales / Referensi</label>
                                <input id="edit_sales" name="sales" value="{{ old('sales', $ticket->sales) }}" class="input" maxlength="100">
                                <x-field-error name="sales" />
                            </div>
                            <div class="sm:col-span-2">
                                @include('installations.partials.odp-fields', ['prefix' => 'edit'])
                            </div>
                        </div>
                        <x-field-error name="installation" />
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="editing = false" class="btn-secondary">Batal</button>
                            <button class="btn-primary"><i data-lucide="save" class="h-4 w-4"></i>Simpan</button>
                        </div>
                    </form>
                @endif

                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2" x-show="! editing">
                    <div><dt class="text-xs text-slate-500">Paket Layanan</dt><dd class="font-medium">{{ $ticket->package ?? '-' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Sales / Referensi</dt><dd class="font-medium">{{ $ticket->sales ?? '-' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">ODP</dt><dd class="font-medium">{{ $ticket->odp ?? '-' }}</dd></div>
                    @if ($ticket->odp_location || $ticket->odp_distance)
                        <div class="sm:col-span-2">
                            <dt class="text-xs text-slate-500">Lokasi ODP &amp; Jarak ke Rumah</dt>
                            <dd class="mt-1 space-y-2 rounded-lg border border-slate-200 p-3">
                                @if ($ticket->odp_location)
                                    <div class="break-all">
                                        @if ($ticket->hasOdpLocation())
                                            <a href="{{ $ticket->odpMapsUrl() }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-medium text-indigo-700 hover:underline">
                                                <i data-lucide="map-pin" class="h-3.5 w-3.5 text-rose-600"></i>{{ App\Support\GeoLocation::format($ticket->odp_latitude) }}, {{ App\Support\GeoLocation::format($ticket->odp_longitude) }}
                                            </a>
                                        @else
                                            <span class="font-medium">{{ $ticket->odp_location }}</span>
                                            <span class="block text-xs text-amber-700">Titik ODP tidak terbaca dari shareloc ini.</span>
                                        @endif
                                    </div>
                                @endif
                                <div class="rounded-lg bg-slate-50 p-2">
                                    <div class="text-xs text-slate-500">Jarak kabel sesuai jalan (diisi Helpdesk)</div>
                                    <div @class(['font-semibold', 'text-slate-400' => ! $ticket->odp_distance])>{{ $ticket->odp_distance ? '±'.number_format($ticket->odp_distance, 0, ',', '.').' m' : 'Belum diisi' }}</div>
                                </div>
                                @if ($ticket->odpRouteUrl())
                                    <a href="{{ $ticket->odpRouteUrl() }}" target="_blank" rel="noopener noreferrer" class="btn-secondary w-full px-3 py-1.5 text-xs">
                                        <i data-lucide="route" class="h-3.5 w-3.5 text-indigo-600"></i>Lihat Rute ODP ke Rumah di Google Maps
                                    </a>
                                @endif
                            </dd>
                        </div>
                    @endif
                    <div><dt class="text-xs text-slate-500">Helpdesk</dt><dd class="font-medium">{{ $ticket->helpdesk?->name ?? '-' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Teknisi</dt><dd class="font-medium">{{ $ticket->technicianNames() ?: '-' }}</dd></div>
                    @if ($ticket->modem)
                        <div><dt class="text-xs text-slate-500">Modem / ONT</dt><dd class="font-medium">{{ $ticket->modem }}</dd></div>
                    @endif
                    @if ($ticket->repair_started_at)
                        <div><dt class="text-xs text-slate-500">Mulai Pemasangan</dt><dd class="font-medium">{{ $ticket->repair_started_at->format('d/m/Y H:i') }}</dd></div>
                    @endif
                    @if ($ticket->resolved_at)
                        <div><dt class="text-xs text-slate-500">Selesai Dipasang</dt><dd class="font-medium">{{ $ticket->resolved_at->format('d/m/Y H:i') }}</dd></div>
                    @endif
                    @if ($status === S::PSB_SELESAI)
                        <div><dt class="text-xs text-slate-500">ID Pelanggan</dt><dd class="font-mono font-medium">{{ $ticket->customer_id }}</dd></div>
                        <div><dt class="text-xs text-slate-500">Aktif Sejak</dt><dd class="font-medium">{{ $ticket->closed_at?->format('d/m/Y H:i') }}</dd></div>
                    @endif
                    @foreach (['survey_notes' => 'Hasil Survey', 'technician_notes' => 'Laporan Pemasangan', 'confirmation_notes' => $status === S::PSB_BATAL ? 'Alasan Batal' : 'Konfirmasi Pelanggan'] as $field => $label)
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
                <h2 class="mb-4 flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="history" class="h-4 w-4"></i>Riwayat PSB</h2>
                @include('tickets.partials.history')
            </section>
        </div>

        {{-- Actions: the step that moves the order on comes first; notes and cancelling sit below it. --}}
        <aside class="space-y-4 lg:col-start-3 lg:row-span-2 lg:row-start-1">
            <div class="flex items-center justify-between gap-2 px-1">
                <h2 class="flex items-center gap-2 text-xs font-bold tracking-wider text-indigo-700 uppercase">
                    <span class="flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-white"><i data-lucide="arrow-right" class="h-3 w-3"></i></span>Langkah Berikutnya
                </h2>
                <span class="text-xs text-slate-500">Tahap: <span class="font-semibold text-slate-700">{{ $status->label() }}</span></span>
            </div>

            @if ($canDecide)
                <div class="{{ $stepCard }}" x-data="{ mode: @js(old('_panel') === 'survey' ? 'survey' : 'pasang') }">
                    <h3 class="mb-1 font-semibold">Hasil Cek Lokasi</h3>
                    <p class="mb-3 text-sm text-slate-500">Cek coverage dari titik lokasi. Lokasi tercover dan jalurnya jelas? Langsung masuk antrian pasang. Ragu? Survey dulu.</p>
                    <span class="label">Pilih hasil cek lokasi</span>
                    <div class="mb-4 grid grid-cols-2 gap-2">
                        <button type="button" @click="mode = 'pasang'" :class="mode === 'pasang' ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-slate-200 text-slate-600'" class="rounded-lg border-2 px-2 py-2 text-xs font-semibold">
                            Bisa Langsung Pasang
                        </button>
                        <button type="button" @click="mode = 'survey'" :class="mode === 'survey' ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-slate-200 text-slate-600'" class="rounded-lg border-2 px-2 py-2 text-xs font-semibold">
                            Perlu Survey Dulu
                        </button>
                    </div>
                    <form method="POST" action="{{ $actionUrl(A::PSB_LANGSUNG_PASANG) }}" x-show="mode === 'pasang'" class="space-y-3">
                        @csrf
                        <input type="hidden" name="_panel" value="pasang">
                        @include('installations.partials.odp-fields', ['prefix' => 'decide'])
                        <textarea name="notes" rows="2" class="input" maxlength="2000" placeholder="Catatan untuk teknisi pasang (opsional)">{{ old('_panel') === 'pasang' ? old('notes') : '' }}</textarea>
                        <button class="btn-success w-full"><i data-lucide="check" class="h-4 w-4"></i>Masuk Antrian Pasang</button>
                    </form>
                    <form method="POST" action="{{ $actionUrl(A::PSB_PERLU_SURVEY) }}" x-show="mode === 'survey'" x-cloak class="space-y-3">
                        @csrf
                        <input type="hidden" name="_panel" value="survey">
                        @include('installations.partials.odp-fields', ['prefix' => 'survey'])
                        <div>
                            <label class="label" for="survey_notes">Yang perlu dicek saat survey</label>
                            <textarea id="survey_notes" name="notes" rows="3" class="input" required maxlength="2000" placeholder="Contoh: Jarak ke ODP terdekat ±200 m, cek jalur tiang">{{ old('_panel') === 'survey' ? old('notes') : '' }}</textarea>
                            @if (old('_panel') === 'survey')<x-field-error name="notes" />@endif
                        </div>
                        <button class="btn-primary w-full"><i data-lucide="compass" class="h-4 w-4"></i>Masuk Antrian Survey</button>
                    </form>
                </div>
            @endif

            @if ($canAssignSurvey || $canAssignInstall)
                <form method="POST" action="{{ $actionUrl($canAssignSurvey ? A::PSB_TUGAS_SURVEY : A::PSB_TUGAS_PASANG) }}" class="{{ $stepCard }} space-y-3">
                    @csrf
                    <h3 class="flex items-center gap-2 font-semibold"><i data-lucide="{{ $canAssignSurvey ? 'compass' : 'wrench' }}" class="h-4 w-4 text-orange-500"></i>Tugaskan Teknisi {{ $canAssignSurvey ? 'Survey' : 'Pasang' }}</h3>
                    <p class="text-sm text-slate-500">Pilih teknisi yang dijadwalkan. Teknisi dan CS langsung dapat notifikasi.</p>
                    @if ($ticket->survey_notes && $canAssignInstall)
                        <p class="rounded-lg bg-blue-50 p-2 text-xs text-blue-800"><span class="font-semibold">Hasil survey:</span> {{ $ticket->survey_notes }}</p>
                    @endif
                    <div>
                        <span class="label">Teknisi</span>
                        <x-technician-picker :technicians="$technicians" />
                    </div>
                    <textarea name="notes" rows="2" class="input" maxlength="2000" placeholder="{{ $canAssignSurvey ? 'Instruksi survey (opsional)' : 'Instruksi pemasangan (opsional), contoh: bawa dropcore 150 m' }}"></textarea>
                    <button class="btn-warning w-full"><i data-lucide="send" class="h-4 w-4"></i>Tugaskan</button>
                </form>
            @endif

            @if ($canSurveyResult)
                <div class="{{ $stepCard }}" x-data="{ mode: @js(old('_panel') === 'tidak-layak' ? 'tidak' : 'layak') }">
                    <h3 class="mb-1 font-semibold">Hasil Survey</h3>
                    <p class="mb-3 text-sm text-slate-500">
                        @if ($isFieldTechnician)
                            Laporkan hasil survey di lokasi.
                        @else
                            Catat hasil survey teknisi <span class="font-medium text-slate-700">{{ $ticket->technicianNames() ?: '-' }}</span>.
                        @endif
                    </p>
                    <div class="mb-4 grid grid-cols-2 gap-2">
                        <button type="button" @click="mode = 'layak'" :class="mode === 'layak' ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-slate-200 text-slate-600'" class="rounded-lg border-2 px-2 py-2 text-xs font-semibold">
                            Bisa Dipasang
                        </button>
                        <button type="button" @click="mode = 'tidak'" :class="mode === 'tidak' ? 'border-rose-500 bg-rose-50 text-rose-700' : 'border-slate-200 text-slate-600'" class="rounded-lg border-2 px-2 py-2 text-xs font-semibold">
                            Tidak Bisa Dipasang
                        </button>
                    </div>
                    <form method="POST" action="{{ $actionUrl(A::PSB_SURVEY_LAYAK) }}" enctype="multipart/form-data" x-show="mode === 'layak'" class="space-y-3">
                        @csrf
                        <input type="hidden" name="_panel" value="layak">
                        @include('installations.partials.odp-fields', ['prefix' => 'layak'])
                        <div>
                            <label class="label" for="layak_notes">Hasil survey</label>
                            <textarea id="layak_notes" name="notes" rows="3" class="input" required maxlength="2000" placeholder="Jalur kabel, perkiraan panjang dropcore, kebutuhan tiang, dll">{{ old('_panel') === 'layak' ? old('notes') : '' }}</textarea>
                            @if (old('_panel') === 'layak')<x-field-error name="notes" />@endif
                        </div>
                        <x-photo-input id="survey_photos" label="Foto survey" />
                        <button class="btn-success w-full"><i data-lucide="check" class="h-4 w-4"></i>Bisa Dipasang, Masuk Antrian Pasang</button>
                    </form>
                    <form method="POST" action="{{ $actionUrl(A::PSB_SURVEY_TIDAK_LAYAK) }}" enctype="multipart/form-data" x-show="mode === 'tidak'" x-cloak class="space-y-3"
                        data-confirm="Lokasi tidak bisa dipasang? PSB akan dibatalkan.">
                        @csrf
                        <input type="hidden" name="_panel" value="tidak-layak">
                        <div>
                            <label class="label" for="tidak_notes">Alasan tidak bisa dipasang</label>
                            <textarea id="tidak_notes" name="notes" rows="3" class="input" required maxlength="2000" placeholder="Contoh: ODP terdekat penuh / di luar jangkauan 300 m">{{ old('_panel') === 'tidak-layak' ? old('notes') : '' }}</textarea>
                            @if (old('_panel') === 'tidak-layak')<x-field-error name="notes" />@endif
                        </div>
                        <x-photo-input id="reject_photos" label="Foto survey" />
                        <button class="btn-danger w-full"><i data-lucide="x" class="h-4 w-4"></i>Tidak Bisa Dipasang</button>
                        <p class="text-center text-xs text-slate-500">PSB dibatalkan dan CS mendapat notifikasi</p>
                    </form>
                </div>
            @endif

            @if ($canInstall)
                @if (! $ticket->repair_started_at)
                    <form method="POST" action="{{ $actionUrl(A::PSB_MULAI_PASANG) }}" class="{{ $stepCard }}">
                        @csrf
                        <h3 class="mb-1 font-semibold">Mulai Pemasangan</h3>
                        @if ($isFieldTechnician)
                            <p class="mb-3 text-sm text-slate-500">Tekan saat Anda sudah tiba di lokasi calon pelanggan.</p>
                            <button class="btn-warning w-full"><i data-lucide="play" class="h-4 w-4"></i>Saya Sudah di Lokasi</button>
                        @else
                            <p class="mb-3 text-sm text-slate-500">Catat saat teknisi <span class="font-medium text-slate-700">{{ $ticket->technicianNames() ?: '-' }}</span> mengabarkan sudah tiba di lokasi.</p>
                            <button class="btn-warning w-full"><i data-lucide="play" class="h-4 w-4"></i>Teknisi Sudah di Lokasi</button>
                        @endif
                    </form>
                @else
                    <form method="POST" action="{{ $actionUrl(A::PSB_PASANG_SELESAI) }}" enctype="multipart/form-data" class="{{ $stepCard }} space-y-3"
                        x-data="repairMaterials(@js($materialForm))" data-confirm="Kirim laporan pemasangan selesai? PSB lanjut ke konfirmasi aktif oleh CS.">
                        @csrf
                        <h3 class="font-semibold">Laporan Pemasangan</h3>
                        <p class="text-sm text-slate-500">Dimulai {{ $ticket->repair_started_at->diffForHumans() }}. Isi hasil pemasangan, material yang terpakai, dan foto hasil pasang.</p>
                        <div>
                            <label class="label" for="install_modem">Modem / ONT Terpasang</label>
                            <input id="install_modem" name="modem" value="{{ old('modem', $ticket->modem) }}" list="install_modem_suggestions" maxlength="100" class="input" placeholder="Contoh: ZTE F670L">
                            <datalist id="install_modem_suggestions">
                                @foreach ($modemSuggestions as $suggestion)
                                    <option value="{{ $suggestion }}">
                                @endforeach
                            </datalist>
                        </div>
                        @include('installations.partials.odp-fields', ['prefix' => 'install'])
                        <textarea name="notes" rows="3" class="input" required maxlength="2000" placeholder="Contoh: Tarik dropcore 120 m dari ODP-12 port 4, redaman -19 dBm, ONT online">{{ old('notes') }}</textarea>

                        @include('tickets.partials.material-lines')

                        <x-photo-input id="install_photos" label="Foto hasil pemasangan" />
                        <button class="btn-success w-full"><i data-lucide="check-circle-2" class="h-4 w-4"></i>Pemasangan Selesai</button>
                    </form>
                @endif
            @endif

            @if ($canResubmitMaterial)
                @php($rejected = $ticket->materialRequests->last())
                <form method="POST" action="{{ route('tickets.material.resubmit', $ticket) }}" class="{{ $stepCard }} space-y-3" x-data="repairMaterials(@js($materialForm))">
                    @csrf
                    <h3 class="flex items-center gap-2 font-semibold"><i data-lucide="refresh-cw" class="h-4 w-4 text-rose-600"></i>Ajukan Ulang Material</h3>
                    <p class="rounded-lg bg-rose-50 p-2 text-sm text-rose-800">Ditolak {{ $rejected->reviewer?->name ?? 'Gudang' }}: {{ $rejected->review_notes }}</p>
                    @include('tickets.partials.material-lines')
                    <button class="btn-primary w-full"><i data-lucide="send" class="h-4 w-4"></i>Ajukan ke Gudang</button>
                </form>
            @endif

            @if ($canActivate)
                <div class="{{ $stepCard }} space-y-3" x-data="{ notes: @js(old('notes', '')) }">
                    <h3 class="font-semibold">Konfirmasi Aktif ke Pelanggan</h3>
                    <ol class="list-inside list-decimal space-y-1 text-sm text-slate-500">
                        <li>Hubungi pelanggan via WhatsApp.</li>
                        <li>Pastikan internet sudah aktif, lalu pilih hasilnya.</li>
                    </ol>
                    <a href="{{ $waLink }}" target="_blank" rel="noopener" class="btn-secondary w-full"><i data-lucide="message-circle" class="h-4 w-4 text-emerald-600"></i>Chat Pelanggan</a>
                    <textarea x-model="notes" rows="2" class="input" placeholder="Catatan jawaban pelanggan (wajib jika belum aktif)"></textarea>
                    <form method="POST" action="{{ $actionUrl(A::PSB_AKTIF) }}" class="space-y-2 rounded-lg border border-emerald-200 bg-emerald-50/50 p-3"
                        data-confirm="Pelanggan sudah aktif? PSB selesai dan pelanggan masuk Direktori Pelanggan.">
                        @csrf
                        <input type="hidden" name="notes" :value="notes">
                        <label class="label" for="customer_code">ID Pelanggan</label>
                        <input id="customer_code" name="customer_code" value="{{ old('customer_code') }}" class="input" maxlength="50" placeholder="Kosongkan untuk dibuat otomatis">
                        <x-field-error name="customer_code" />
                        <button class="btn-success w-full"><i data-lucide="check" class="h-4 w-4"></i>Sudah Aktif, Selesai</button>
                    </form>
                    <form method="POST" action="{{ $actionUrl(A::PSB_BELUM_AKTIF) }}" data-confirm="Internet pelanggan belum aktif? PSB dikembalikan ke teknisi pasang.">
                        @csrf
                        <input type="hidden" name="notes" :value="notes">
                        <button class="btn-secondary w-full text-rose-700"><i data-lucide="x" class="h-4 w-4"></i>Belum Aktif, Kembali ke Teknisi</button>
                        <x-field-error name="notes" />
                    </form>
                </div>
            @endif

            @unless ($hasNextStep)
                <div class="card p-5 text-sm text-slate-500">
                    @if ($status === S::PSB_SELESAI)
                        <p class="flex items-center gap-2 font-medium text-emerald-700"><i data-lucide="check-circle-2" class="h-4 w-4"></i>Pelanggan sudah aktif dengan ID {{ $ticket->customer_id }}.</p>
                    @elseif ($status === S::PSB_BATAL)
                        <p class="flex items-center gap-2 font-medium text-slate-700"><i data-lucide="x-circle" class="h-4 w-4"></i>PSB ini sudah dibatalkan.</p>
                    @else
                        <p class="flex items-start gap-2"><i data-lucide="info" class="mt-0.5 h-4 w-4 shrink-0"></i>Belum ada langkah untuk peran Anda di tahap ini.</p>
                    @endif
                </div>
            @endunless

            @if ($canReassign || $canRequeue)
                <section class="card divide-y divide-slate-100" x-data="{ open: @js(in_array($openPanel, ['reassign', 'requeue'], true) ? $openPanel : null) }">
                    <div class="px-5 py-3">
                        <h3 class="flex items-center gap-2 text-sm font-semibold text-slate-700"><i data-lucide="wrench" class="h-4 w-4 text-orange-500"></i>Teknisi {{ $status === S::PSB_SURVEY ? 'Survey' : 'Pasang' }}</h3>
                        <p class="mt-1 text-sm text-slate-600">{{ $ticket->technicianNames() ?: '-' }}</p>
                    </div>
                    @if ($canReassign)
                        <x-action-panel name="reassign" icon="users" color="text-orange-500" title="Ganti / Tambah Teknisi" hint="Teknisi berhalangan atau butuh tenaga tambahan">
                            <form method="POST" action="{{ $actionUrl(A::UBAH_TEKNISI) }}" class="space-y-3">
                                @csrf
                                <input type="hidden" name="_panel" value="reassign">
                                <x-technician-picker :technicians="$technicians" :selected="$ticket->technicians->modelKeys()" />
                                <textarea name="notes" rows="2" class="input" required placeholder="Alasan perubahan">{{ old('_panel') === 'reassign' ? old('notes') : '' }}</textarea>
                                <button class="btn-warning w-full"><i data-lucide="save" class="h-4 w-4"></i>Simpan Teknisi</button>
                            </form>
                        </x-action-panel>
                    @endif
                    @if ($canRequeue)
                        <x-action-panel name="requeue" icon="hourglass" color="text-yellow-600" title="Tunda, Kembali ke Antrian" hint="Jadwal batal: pelanggan tidak di rumah, material belum ada">
                            <form method="POST" action="{{ $actionUrl(A::PSB_KEMBALI_ANTRIAN) }}" class="space-y-3" data-confirm="Kembalikan PSB ke antrian? Penugasan teknisi dilepas.">
                                @csrf
                                <input type="hidden" name="_panel" value="requeue">
                                <textarea name="notes" rows="2" class="input" required placeholder="Alasan ditunda">{{ old('_panel') === 'requeue' ? old('notes') : '' }}</textarea>
                                <button class="btn-warning w-full"><i data-lucide="hourglass" class="h-4 w-4"></i>Kembali ke Antrian</button>
                            </form>
                        </x-action-panel>
                    @endif
                </section>
            @endif

            @if ($hasNotes)
                @include('tickets.partials.notes-form')
            @endif

            @if ($canCancel)
                <section class="card divide-y divide-slate-100 overflow-hidden" x-data="{ open: @js($openPanel === 'cancel' ? 'cancel' : null) }">
                    <x-action-panel name="cancel" icon="x-circle" color="text-rose-600" title="Batalkan PSB" hint="Calon pelanggan batal atau tidak bisa dihubungi">
                        <form method="POST" action="{{ $actionUrl(A::PSB_BATAL) }}" class="space-y-3" data-confirm="Batalkan PSB ini? Tindakan ini tidak bisa diurungkan.">
                            @csrf
                            <input type="hidden" name="_panel" value="cancel">
                            <textarea name="notes" rows="2" class="input" required placeholder="Alasan pembatalan">{{ old('_panel') === 'cancel' ? old('notes') : '' }}</textarea>
                            @if ($openPanel === 'cancel')<x-field-error name="notes" />@endif
                            <button class="btn-danger w-full"><i data-lucide="x-circle" class="h-4 w-4"></i>Batalkan PSB</button>
                        </form>
                    </x-action-panel>
                </section>
            @endif
        </aside>
    </div>
</x-layouts.app>
