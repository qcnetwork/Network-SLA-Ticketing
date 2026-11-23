<?php

namespace App\Services;

use App\Enums\IncidentAction;
use App\Enums\Role;
use App\Enums\TicketAction;
use App\Enums\TicketKind;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Incident;
use App\Models\RemovedDevice;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Support\GeoLocation;
use App\Support\PhoneNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketWorkflowService
{
    public const URGENCY_LEVELS = ['INFO', 'NORMAL', 'MENDESAK', 'KRITIS'];

    /**
     * Actions whose history and notification keep the full description (who did the work, old/new crew)
     * instead of only the text typed into the form.
     */
    private const KEEPS_FULL_DESCRIPTION = [
        TicketAction::UBAH_TEKNISI, TicketAction::LAPOR_PERBAIKAN_SELESAI, TicketAction::UBAH_KATEGORI,
        TicketAction::PSB_PASANG_SELESAI, TicketAction::PSB_KEMBALI_ANTRIAN, TicketAction::PSB_AKTIF,
    ];

    /**
     * Actions that work on complaints and installations alike; every other action belongs to one kind.
     */
    private const SHARED_ACTIONS = [
        TicketAction::CATATAN_URGENSI_CS, TicketAction::CATATAN_INFO_HELPDESK, TicketAction::CATATAN_DOKUMENTASI, TicketAction::UBAH_TEKNISI,
    ];

    public function __construct(
        private TicketNumberGenerator $numbers,
        private TelegramService $telegram,
        private TelegramOutbox $outbox,
        private DocumentationPhotoStore $photoStore,
        private TicketNotifier $notifier,
        private InventoryService $inventory,
    ) {}

    /**
     * CS registers a customer complaint as soon as the chat comes in, so the daily chat count is complete.
     * The ticket stays with CS while they coordinate with the customer and is forwarded to the helpdesk
     * queue afterwards, or goes straight into an open gamas when the customer is in the affected area.
     */
    public function create(array $data, User $actor, ?Incident $incident = null): Ticket
    {
        $this->ensureRole(TicketAction::TIKET_DIBUAT, $actor);
        // Read outside the transaction: a short link needs a request to Google to find the pin.
        $coordinates = GeoLocation::fromText($data['customer_address'] ?? null);

        $ticket = DB::transaction(function () use ($data, $actor, $incident, $coordinates) {
            if ($incident) {
                $incident = Incident::query()->lockForUpdate()->findOrFail($incident->id);
                if (! $incident->isOpen()) {
                    throw ValidationException::withMessages(['incident_id' => "Gamas {$incident->incident_number} sudah selesai"]);
                }
            }
            $status = $incident ? TicketStatus::GAMAS : TicketStatus::PROSES_CS;
            $code = trim((string) ($data['customer_id'] ?? ''));
            $phone = PhoneNumber::normalize($data['customer_phone']);

            $ticket = Ticket::create([
                'ticket_number' => $this->numbers->next(),
                // A known ID keeps the customer's own spelling of it, so their tickets stay together. Without
                // an ID, a customer with the same name and number is the same person; anyone else is new.
                'customer_id' => $code !== ''
                    ? (Customer::query()->withCode($code)->value('code') ?? $code)
                    : (Customer::query()->withNameAndPhone($data['customer_name'], $phone)->latest('updated_at')->value('code') ?? $this->newCustomerCode()),
                'customer_name' => $data['customer_name'],
                'customer_phone' => PhoneNumber::normalize($data['customer_phone']),
                'customer_address' => $data['customer_address'] ?? null,
                'latitude' => $coordinates[0] ?? null,
                'longitude' => $coordinates[1] ?? null,
                'category' => $data['category'] ?? null ?: 'Internet Mati / LOS',
                'priority' => $data['priority'] ?? TicketPriority::SEDANG,
                'complaint' => $data['complaint'],
                'status' => $status,
                'incident_id' => $incident?->id,
                'created_by_id' => $actor->id,
            ]);
            $this->rememberCustomer($ticket);

            $ticket->histories()->create([
                'user_id' => $actor->id,
                'actor_name' => $actor->name,
                'action' => TicketAction::TIKET_DIBUAT,
                'from_status' => null,
                'to_status' => $status,
                'notes' => "Tiket dibuat dari komplain pelanggan via WhatsApp: \"{$ticket->complaint}\""
                    .($incident ? "\nTerdampak gangguan massal {$incident->incident_number} ({$incident->area})." : ''),
            ]);

            $incident?->updates()->create([
                'user_id' => $actor->id,
                'actor_name' => $actor->name,
                'action' => IncidentAction::TIKET_DITAUTKAN,
                'notes' => "Tiket baru {$ticket->ticket_number} ({$ticket->customer_name}) ditautkan.",
            ]);

            return $ticket;
        });

        // Gamas tickets are covered by the gamas notifications; one message per customer would flood the group.
        if (! $incident && Setting::read('notify_on_new_ticket') === '1') {
            $this->outbox->push(
                $this->telegram->ticketCreatedMessage($ticket),
                ['ticket' => $ticket->ticket_number, 'action' => TicketAction::TIKET_DIBUAT->value],
            );
        }

        return $ticket;
    }

    /**
     * CS takes a new installation order (PSB). It waits in Cek Lokasi until Helpdesk checks coverage.
     * The photo of the house front is kept on the order's first history entry.
     *
     * @param  array{customer_name: string, customer_phone: string, customer_address: string, package?: ?string, sales?: ?string, notes?: ?string, photos?: array<UploadedFile>}  $data
     */
    public function createInstallation(array $data, User $actor): Ticket
    {
        $this->ensureRole(TicketAction::PSB_DIBUAT, $actor);
        $coordinates = GeoLocation::fromText($data['customer_address'] ?? null);
        $notes = trim((string) ($data['notes'] ?? '')) ?: null;
        $package = trim((string) ($data['package'] ?? '')) ?: null;

        $ticket = DB::transaction(function () use ($data, $actor, $coordinates, $notes, $package) {
            $ticket = Ticket::create([
                'ticket_number' => $this->numbers->next(kind: TicketKind::PSB),
                'kind' => TicketKind::PSB,
                'customer_name' => trim($data['customer_name']),
                'customer_phone' => PhoneNumber::normalize($data['customer_phone']),
                'customer_address' => $data['customer_address'] ?? null,
                'latitude' => $coordinates[0] ?? null,
                'longitude' => $coordinates[1] ?? null,
                'category' => 'Pasang Baru',
                'package' => $package,
                'sales' => trim((string) ($data['sales'] ?? '')) ?: null,
                'priority' => TicketPriority::SEDANG,
                'complaint' => $notes ?? 'Pemasangan baru'.($package ? " paket {$package}" : '').'.',
                'status' => TicketStatus::PSB_CEK_LOKASI,
                'created_by_id' => $actor->id,
            ]);

            $history = $ticket->histories()->create([
                'user_id' => $actor->id,
                'actor_name' => $actor->name,
                'action' => TicketAction::PSB_DIBUAT,
                'from_status' => null,
                'to_status' => $ticket->status,
                'notes' => "Order pasang baru diterima {$actor->name}".($package ? ", paket {$package}" : '').'.'.($notes ? "\n{$notes}" : ''),
            ]);
            foreach ($data['photos'] ?? [] as $photo) {
                $this->photoStore->store($photo, $actor, ['ticket_id' => $ticket->id, 'ticket_history_id' => $history->id]);
            }

            return $ticket;
        });

        if (Setting::read('notify_on_new_ticket') === '1') {
            $this->outbox->push(
                $this->telegram->installationCreatedMessage($ticket),
                ['ticket' => $ticket->ticket_number, 'action' => TicketAction::PSB_DIBUAT->value],
            );
        }

        return $ticket;
    }

    /**
     * Correct or complete an installation order's own details: the package, the sales who brought
     * the customer in, and the ODP found when checking the location.
     *
     * @param  array{package?: ?string, sales?: ?string, odp?: ?string, odp_location?: ?string, odp_distance?: ?int}  $data
     */
    public function updateInstallation(Ticket $ticket, array $data, User $actor): Ticket
    {
        $this->ensureRole(TicketAction::PSB_UBAH_DATA, $actor);
        $location = trim((string) ($data['odp_location'] ?? '')) ?: null;
        $locationChanged = $location !== $ticket->odp_location;
        $pin = $locationChanged ? GeoLocation::fromText($location) : null;

        return DB::transaction(function () use ($ticket, $data, $actor, $location, $locationChanged, $pin) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            if (! $ticket->isInstallation()) {
                throw ValidationException::withMessages(['installation' => 'Tiket ini bukan tiket pasang baru']);
            }
            $labels = ['package' => 'Paket', 'sales' => 'Sales', 'odp' => 'ODP', 'odp_location' => 'Shareloc ODP', 'odp_distance' => 'Jarak kabel sesuai jalan (m)'];
            $before = $ticket->only(array_keys($labels));
            foreach (['package', 'sales', 'odp'] as $field) {
                $ticket->{$field} = trim((string) ($data[$field] ?? '')) ?: null;
            }
            $ticket->odp_location = $location;
            if ($locationChanged) {
                $ticket->odp_latitude = $pin[0] ?? null;
                $ticket->odp_longitude = $pin[1] ?? null;
            }
            // Only Helpdesk measures the cable route; anyone else editing leaves it as it is.
            if (self::measuresOdpDistance($actor)) {
                $ticket->odp_distance = filled($data['odp_distance'] ?? null) ? (int) $data['odp_distance'] : null;
            }

            $changes = collect($labels)
                ->filter(fn ($label, $field) => $before[$field] !== $ticket->{$field})
                ->map(fn ($label, $field) => "{$label}: \"".($before[$field] ?? '-').'" → "'.($ticket->{$field} ?? '-').'"')
                ->values();
            if ($changes->isEmpty()) {
                throw ValidationException::withMessages(['installation' => 'Tidak ada data PSB yang berubah']);
            }

            $ticket->save();
            $ticket->histories()->create([
                'user_id' => $actor->id,
                'actor_name' => $actor->name,
                'action' => TicketAction::PSB_UBAH_DATA,
                'from_status' => $ticket->status,
                'to_status' => $ticket->status,
                'notes' => "Data PSB diubah oleh {$actor->name}:\n".$changes->implode("\n"),
            ]);

            return $ticket;
        });
    }

    /**
     * Move a ticket through the workflow. Throws ValidationException when the
     * transition is not allowed from the ticket's current status.
     *
     * @param  array{notes?: ?string, technician_ids?: array<int>, modem?: ?string, urgency_level?: ?string, upgrade_priority?: bool, category?: ?string, photos?: array<UploadedFile>}  $data
     */
    public function transition(Ticket $ticket, TicketAction $action, User $actor, array $data = []): Ticket
    {
        $this->ensureRole($action, $actor);
        $notes = trim((string) ($data['notes'] ?? '')) ?: null;
        $photos = $data['photos'] ?? [];
        $modem = trim((string) ($data['modem'] ?? '')) ?: null;
        // A shareloc short link needs a request to Google, so the ODP pin is read before the transaction.
        if ($action->isInstallation()) {
            $data['odp_pin'] = $this->odpPin($ticket, $data);
        }

        [$ticket, $previous, $description] = DB::transaction(function () use ($ticket, $action, $actor, $data, $notes, $photos, $modem) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $previous = $ticket->status;
            $now = now();

            // Re-checked on the locked row: the ticket may have left the field visit since the policy check.
            if ($actor->role === Role::TEKNISI && (! $ticket->status->isFieldWork() || ! $ticket->isAssignedTo($actor))) {
                throw new AuthorizationException('Tiket ini tidak ditugaskan kepada Anda');
            }
            if ($action->isInstallation() !== $ticket->isInstallation() && ! in_array($action, self::SHARED_ACTIONS, true)) {
                throw ValidationException::withMessages(['action' => "Aksi {$action->label()} tidak berlaku untuk tiket {$ticket->kind->label()}"]);
            }
            if ($action->isInstallation()) {
                $description = $this->installationStep($ticket, $action, $actor, $data, $notes, $now);
            }

            switch ($action) {
                case TicketAction::SELESAI_EDUKASI_CS:
                    $this->requireStatus($ticket, [TicketStatus::PROSES_CS, TicketStatus::ANTRIAN_HELPDESK],
                        'CS hanya bisa menutup tiket yang belum diambil Helpdesk');
                    if (! $notes) {
                        throw ValidationException::withMessages(['notes' => 'Tuliskan edukasi yang diberikan ke pelanggan']);
                    }
                    $ticket->status = TicketStatus::TUTUP;
                    $ticket->confirmation_notes = $notes;
                    $ticket->resolved_at = $now;
                    $ticket->closed_at = $now;
                    $description = "CS ({$actor->name}) menyelesaikan kendala lewat edukasi pelanggan. Tiket ditutup.";
                    break;

                case TicketAction::TERUSKAN_HELPDESK:
                    $this->requireStatus($ticket, [TicketStatus::PROSES_CS], 'Hanya tiket yang masih diproses CS yang bisa diteruskan ke Helpdesk');
                    $ticket->status = TicketStatus::ANTRIAN_HELPDESK;
                    $description = "CS ({$actor->name}) meneruskan tiket ke Antrian Helpdesk.";
                    break;

                case TicketAction::KLAIM_HELPDESK:
                    $this->requireStatus($ticket, [TicketStatus::ANTRIAN_HELPDESK, TicketStatus::CEK_ULANG],
                        'Klaim helpdesk hanya bisa dari status Antrian atau Cek Ulang');
                    $ticket->status = TicketStatus::HELPDESK_CEK;
                    $ticket->assigned_helpdesk_id = $actor->id;
                    $description = "Helpdesk ({$actor->name}) memulai pengecekan teknis.";
                    break;

                case TicketAction::SELESAI_REMOTE:
                    // From the technician queue too: the fault can turn out fixable remotely before anyone visits.
                    $this->requireStatus($ticket, [TicketStatus::HELPDESK_CEK, TicketStatus::CEK_ULANG, TicketStatus::ANTRIAN_TEKNISI],
                        'Penyelesaian remote hanya bisa dari tahap Helpdesk Cek, Cek Ulang, atau Antrian Teknisi');
                    $ticket->status = TicketStatus::MENUNGGU_KONFIRMASI;
                    $ticket->remote_resolution_notes = $notes ?? 'Masalah diselesaikan secara remote (reset/reboot/rebind).';
                    $ticket->modem = $modem ?? $ticket->modem;
                    $ticket->resolved_at = $now;
                    $description = "Penyelesaian remote oleh {$actor->name}: {$ticket->remote_resolution_notes}";
                    break;

                case TicketAction::ANTRI_TEKNISI:
                    // From Perbaikan too: a visit can stall (no material, customer away) after it was handed out.
                    $this->requireStatus($ticket, [TicketStatus::HELPDESK_CEK, TicketStatus::CEK_ULANG, TicketStatus::PERBAIKAN],
                        'Tiket hanya bisa masuk Antrian Teknisi dari tahap Helpdesk Cek, Cek Ulang, atau Perbaikan');
                    $fromRepair = $ticket->status === TicketStatus::PERBAIKAN;
                    if (! $notes) {
                        throw ValidationException::withMessages(['notes' => $fromRepair
                            ? 'Tuliskan alasan tiket dikembalikan ke Antrian Teknisi'
                            : 'Tuliskan kendala yang perlu dicek teknisi di lokasi']);
                    }
                    $ticket->modem = $modem ?? $ticket->modem;
                    $ticket->status = TicketStatus::ANTRIAN_TEKNISI;

                    if ($fromRepair) {
                        $previousCrew = $ticket->technicianNames() ?: '-';
                        // The queue means no one is on it yet; whoever is assigned next starts the visit afresh.
                        $ticket->technicians()->sync([]);
                        $ticket->setRelation('technicians', new EloquentCollection);
                        $ticket->repair_started_at = null;
                        $ticket->technician_notes = null;
                        $description = "Helpdesk ({$actor->name}) mengembalikan tiket ke Antrian Teknisi dari teknisi [{$previousCrew}]. Alasan: {$notes}";
                    } else {
                        $description = "Helpdesk ({$actor->name}): perlu kunjungan teknisi, menunggu teknisi tersedia. Kendala: {$notes}";
                    }
                    break;

                case TicketAction::PERLU_TEKNISI:
                    $this->requireStatus($ticket, [TicketStatus::HELPDESK_CEK, TicketStatus::CEK_ULANG, TicketStatus::ANTRIAN_TEKNISI],
                        'Penugasan teknisi hanya bisa dari tahap Helpdesk Cek, Cek Ulang, atau Antrian Teknisi');
                    $technicians = $this->activeTechnicians($data['technician_ids'] ?? [],
                        'Pilih minimal satu teknisi aktif untuk eskalasi ke perbaikan');
                    $ticket->modem = $modem ?? $ticket->modem;
                    $ticket->status = TicketStatus::PERBAIKAN;
                    $ticket->technicians()->sync($technicians->modelKeys());
                    $ticket->setRelation('technicians', $technicians);
                    // A new repair cycle (e.g. after Cek Ulang) must be started again by the technician.
                    $ticket->repair_started_at = null;
                    $ticket->technician_notes = null;
                    $description = "Helpdesk menugaskan teknisi lapangan [{$ticket->technicianNames()}]. Tugas: "
                        .($notes ?? 'Perlu pengecekan fisik/redaman di lokasi');
                    break;

                case TicketAction::UBAH_TEKNISI:
                    $this->requireStatus($ticket, [TicketStatus::PERBAIKAN, TicketStatus::PSB_SURVEY, TicketStatus::PSB_PEMASANGAN],
                        'Teknisi hanya bisa diubah saat teknisi sedang bertugas di lokasi');
                    if (! $notes) {
                        throw ValidationException::withMessages(['notes' => 'Tuliskan alasan perubahan teknisi']);
                    }
                    $technicians = $this->activeTechnicians($data['technician_ids'] ?? [], 'Pilih minimal satu teknisi aktif');
                    $current = $ticket->technicians;
                    if ($technicians->count() === $current->count() && $technicians->diff($current)->isEmpty()) {
                        throw ValidationException::withMessages(['technician_ids' => 'Teknisi yang dipilih sama dengan penugasan saat ini']);
                    }
                    // Adding a helper keeps the repair going; only a whole new crew has to start over.
                    if ($technicians->intersect($current)->isEmpty()) {
                        $ticket->repair_started_at = null;
                    }
                    $ticket->technicians()->sync($technicians->modelKeys());
                    $ticket->setRelation('technicians', $technicians);
                    // The status stays Perbaikan, so only the newly added technicians hear about it.
                    $this->notifier->techniciansAssigned($ticket, $technicians->diff($current), $actor);
                    $description = "Helpdesk ({$actor->name}) mengubah teknisi dari [".($current->pluck('name')->implode(', ') ?: '-')
                        ."] menjadi [{$ticket->technicianNames()}]. Alasan: {$notes}";
                    break;

                case TicketAction::MULAI_PERBAIKAN:
                    $this->requireStatus($ticket, [TicketStatus::PERBAIKAN], 'Mulai Perbaikan hanya untuk tiket berstatus Perbaikan');
                    if ($ticket->repair_started_at) {
                        throw ValidationException::withMessages(['action' => 'Pengerjaan untuk tiket ini sudah dimulai']);
                    }
                    $ticket->repair_started_at = $now;
                    $description = "Teknisi [{$this->repairCrew($ticket, $actor)}] telah tiba di lokasi dan mulai melakukan perbaikan fisik."
                        .$this->recordedOnBehalf($actor);
                    break;

                case TicketAction::LAPOR_PERBAIKAN_SELESAI:
                    $this->requireStatus($ticket, [TicketStatus::PERBAIKAN], 'Laporan perbaikan hanya untuk tiket berstatus Perbaikan');
                    if (! $ticket->repair_started_at) {
                        throw ValidationException::withMessages(['action' => 'Teknisi harus memulai pengerjaan sebelum mengirim laporan selesai']);
                    }
                    $ticket->status = TicketStatus::MENUNGGU_KONFIRMASI;
                    $ticket->technician_notes = $notes ?? 'Perbaikan fisik di lokasi telah selesai.';
                    $ticket->resolved_at = $now;
                    $materials = $this->inventory->requestForTicket($ticket, $data['materials'] ?? [], $actor);
                    // Devices taken off the customer: carried by the technician until the warehouse receives them.
                    $removed = ($data['removed_any'] ?? null) === 'ya'
                        ? app(RemovedDeviceService::class)->recordFromResume($ticket, $data['removed_devices'] ?? [], $actor)
                        : collect();
                    $description = "Teknisi [{$this->repairCrew($ticket, $actor)}] menyelesaikan perbaikan: {$ticket->technician_notes}"
                        .($materials->isNotEmpty() ? "\nMaterial terpakai (menunggu ACC Gudang, stok belum berkurang):\n".$materials->map(fn (string $line) => "• {$line}")->implode("\n") : '')
                        .($removed->isNotEmpty() ? "\nAlat dicabut (wajib diserahkan ke Gudang paling lambat ".now()->addDays(RemovedDevice::DAYS_TO_RETURN)->format('d/m/Y H:i')."):\n".$removed->map(fn (string $line) => "• {$line}")->implode("\n") : '')
                        .$this->recordedOnBehalf($actor);
                    break;

                case TicketAction::CATATAN_URGENSI_CS:
                    if ($ticket->status->isFinal()) {
                        throw ValidationException::withMessages(['action' => 'Tidak dapat menambahkan catatan urgensi pada tiket yang sudah ditutup']);
                    }
                    // The note pings the helpdesk group, which has not seen the ticket yet.
                    if ($ticket->status === TicketStatus::PROSES_CS) {
                        throw ValidationException::withMessages(['action' => 'Teruskan tiket ke Helpdesk terlebih dahulu sebelum mengirim catatan urgensi']);
                    }
                    if (! $notes) {
                        throw ValidationException::withMessages(['notes' => 'Catatan urgensi wajib diisi']);
                    }
                    $urgency = $data['urgency_level'] ?? 'MENDESAK';
                    if (! in_array($urgency, self::URGENCY_LEVELS, true)) {
                        throw ValidationException::withMessages(['urgency_level' => 'Tingkat urgensi tidak valid']);
                    }
                    if (! empty($data['upgrade_priority'])) {
                        $ticket->priority = TicketPriority::TINGGI;
                    }
                    $ticket->loadMissing('technicians');
                    $this->notifier->ticketNote($ticket, $notes, $urgency, $actor, 'CS');
                    $description = "[{$urgency}] CS ({$actor->name}): {$notes}";
                    break;

                case TicketAction::CATATAN_INFO_HELPDESK:
                    if ($ticket->status->isFinal()) {
                        throw ValidationException::withMessages(['action' => 'Tidak dapat menambahkan info pada tiket yang sudah ditutup']);
                    }
                    if (! $notes) {
                        throw ValidationException::withMessages(['notes' => 'Isi info wajib diisi']);
                    }
                    $urgency = $data['urgency_level'] ?? 'INFO';
                    if (! in_array($urgency, self::URGENCY_LEVELS, true)) {
                        throw ValidationException::withMessages(['urgency_level' => 'Tingkat urgensi tidak valid']);
                    }
                    if (! empty($data['upgrade_priority'])) {
                        $ticket->priority = TicketPriority::TINGGI;
                    }
                    $ticket->loadMissing('technicians');
                    $this->notifier->ticketNote($ticket, $notes, $urgency, $actor, 'Helpdesk');
                    $description = "[{$urgency}] Helpdesk ({$actor->name}): {$notes}";
                    break;

                case TicketAction::CATATAN_DOKUMENTASI:
                    if ($ticket->status->isFinal()) {
                        throw ValidationException::withMessages(['action' => 'Tidak dapat menambahkan dokumentasi pada tiket yang sudah ditutup']);
                    }
                    if (! $notes && ! $photos) {
                        throw ValidationException::withMessages(['photos' => 'Isi catatan atau lampirkan minimal satu foto/video dokumentasi']);
                    }
                    $description = "Dokumentasi oleh {$actor->name}: ".count($photos).' foto/video dilampirkan.';
                    break;

                case TicketAction::UBAH_KATEGORI:
                    if ($ticket->status === TicketStatus::TUTUP) {
                        throw ValidationException::withMessages(['category' => 'Kategori tiket yang sudah ditutup tidak dapat diubah']);
                    }
                    $category = $data['category'] ?? null;
                    if (! $category || $category === $ticket->category) {
                        throw ValidationException::withMessages(['category' => 'Pilih kategori kendala yang berbeda dari sebelumnya']);
                    }
                    $description = "Kategori kendala diubah oleh {$actor->name}: \"{$ticket->category}\" → \"{$category}\"."
                        .($notes ? " Alasan: {$notes}" : '');
                    $ticket->category = $category;
                    break;

                case TicketAction::KONFIRMASI_NORMAL:
                    $this->requireStatus($ticket, [TicketStatus::MENUNGGU_KONFIRMASI], 'Konfirmasi hanya bisa pada status Menunggu Konfirmasi');
                    $ticket->status = TicketStatus::TUTUP;
                    $ticket->confirmation_notes = $notes ?? 'Pelanggan mengonfirmasi koneksi sudah normal via WhatsApp.';
                    $ticket->closed_at = $now;
                    $description = "CS ({$actor->name}) konfirmasi via WhatsApp: pelanggan menyatakan NORMAL. Tiket ditutup.";
                    break;

                case TicketAction::KONFIRMASI_TERKENDALA:
                    $this->requireStatus($ticket, [TicketStatus::MENUNGGU_KONFIRMASI], 'Konfirmasi hanya bisa pada status Menunggu Konfirmasi');
                    $ticket->status = TicketStatus::CEK_ULANG;
                    $ticket->confirmation_notes = $notes ?? 'Pelanggan mengonfirmasi kendala masih terjadi.';
                    $ticket->resolved_at = null;
                    $description = "CS ({$actor->name}) konfirmasi via WhatsApp: masih TERKENDALA. Tiket dikembalikan ke Helpdesk.";
                    break;

                default:
                    // Installation steps were applied above.
                    if (! $action->isInstallation()) {
                        throw ValidationException::withMessages(['action' => "Aksi '{$action->value}' tidak dikenal"]);
                    }
            }

            $ticket->save();
            $history = $ticket->histories()->create([
                'user_id' => $actor->id,
                'actor_name' => $actor->name,
                'action' => $action,
                'from_status' => $previous,
                'to_status' => $ticket->status,
                'notes' => $this->keepsFullDescription($action, $previous) ? $description : ($notes ?? $description),
                'urgency_level' => match ($action) {
                    TicketAction::CATATAN_URGENSI_CS => $data['urgency_level'] ?? 'MENDESAK',
                    TicketAction::CATATAN_INFO_HELPDESK => $data['urgency_level'] ?? 'INFO',
                    default => null,
                },
            ]);

            foreach ($photos as $photo) {
                $this->photoStore->store($photo, $actor, ['ticket_id' => $ticket->id, 'ticket_history_id' => $history->id]);
            }

            return [$ticket, $previous, $description];
        });

        // Documentation notes are internal records and never ping the Telegram group.
        if ($action === TicketAction::CATATAN_DOKUMENTASI) {
            return $ticket;
        }

        $this->notify($ticket, $action, $previous, $this->keepsFullDescription($action, $previous) ? $description : ($notes ?? $description), $actor, $data);

        return $ticket;
    }

    /**
     * One step of a new installation (PSB), applied to the locked ticket. Returns what the history records.
     *
     * @param  array{technician_ids?: array<int>, odp?: ?string, modem?: ?string, customer_code?: ?string, materials?: array}  $data
     */
    private function installationStep(Ticket $ticket, TicketAction $action, User $actor, array $data, ?string $notes, Carbon $now): string
    {
        $requireNotes = function (string $message) use ($notes): void {
            if (! $notes) {
                throw ValidationException::withMessages(['notes' => $message]);
            }
        };

        switch ($action) {
            case TicketAction::PSB_LANGSUNG_PASANG:
                $this->requireStatus($ticket, [TicketStatus::PSB_CEK_LOKASI], 'Keputusan cek lokasi hanya untuk PSB yang masih di tahap Cek Lokasi');
                $this->claimForHelpdesk($ticket, $actor);
                $this->fillOdp($ticket, $data, $actor);
                $ticket->status = TicketStatus::PSB_ANTRIAN_PASANG;

                return "Cek lokasi oleh {$actor->name}: tercover, bisa langsung dipasang."
                    .$this->odpSummary($ticket).($notes ? " {$notes}" : '');

            case TicketAction::PSB_PERLU_SURVEY:
                $this->requireStatus($ticket, [TicketStatus::PSB_CEK_LOKASI], 'Keputusan cek lokasi hanya untuk PSB yang masih di tahap Cek Lokasi');
                $requireNotes('Tuliskan apa yang perlu dicek teknisi saat survey');
                $this->claimForHelpdesk($ticket, $actor);
                $this->fillOdp($ticket, $data, $actor);
                $ticket->status = TicketStatus::PSB_ANTRIAN_SURVEY;

                return "Cek lokasi oleh {$actor->name}: perlu survey dulu. {$notes}";

            case TicketAction::PSB_TUGAS_SURVEY:
            case TicketAction::PSB_TUGAS_PASANG:
                $survey = $action === TicketAction::PSB_TUGAS_SURVEY;
                $this->requireStatus($ticket, [$survey ? TicketStatus::PSB_ANTRIAN_SURVEY : TicketStatus::PSB_ANTRIAN_PASANG],
                    $survey ? 'Teknisi survey hanya bisa ditugaskan dari Antrian Survey' : 'Teknisi pasang hanya bisa ditugaskan dari Antrian Pasang');
                $technicians = $this->activeTechnicians($data['technician_ids'] ?? [], 'Pilih minimal satu teknisi aktif');
                $ticket->status = $survey ? TicketStatus::PSB_SURVEY : TicketStatus::PSB_PEMASANGAN;
                $ticket->technicians()->sync($technicians->modelKeys());
                $ticket->setRelation('technicians', $technicians);
                $ticket->repair_started_at = null;

                return "Helpdesk ({$actor->name}) menugaskan teknisi [{$ticket->technicianNames()}] untuk ".($survey ? 'survey lokasi' : 'pemasangan').'.'
                    .($notes ? " {$notes}" : '');

            case TicketAction::PSB_SURVEY_LAYAK:
            case TicketAction::PSB_SURVEY_TIDAK_LAYAK:
                $this->requireStatus($ticket, [TicketStatus::PSB_SURVEY], 'Hasil survey hanya untuk PSB yang sedang disurvey');
                $layak = $action === TicketAction::PSB_SURVEY_LAYAK;
                $requireNotes($layak ? 'Tuliskan hasil survey (jalur kabel, ODP, kebutuhan material)' : 'Tuliskan alasan lokasi tidak bisa dipasang');
                $ticket->survey_notes = $notes;
                $this->fillOdp($ticket, $data, $actor);
                $crew = $this->repairCrew($ticket, $actor);
                if ($layak) {
                    // The install may go to another crew, so the survey crew is released.
                    $ticket->status = TicketStatus::PSB_ANTRIAN_PASANG;
                    $ticket->technicians()->sync([]);
                    $ticket->setRelation('technicians', new EloquentCollection);
                } else {
                    $ticket->status = TicketStatus::PSB_BATAL;
                    $ticket->confirmation_notes = "Tidak bisa dipasang (hasil survey): {$notes}";
                    $ticket->closed_at = $now;
                }

                return "Hasil survey teknisi [{$crew}]: ".($layak ? 'bisa dipasang' : 'tidak bisa dipasang, PSB dibatalkan').'.'
                    .$this->odpSummary($ticket)." {$notes}".$this->recordedOnBehalf($actor);

            case TicketAction::PSB_MULAI_PASANG:
                $this->requireStatus($ticket, [TicketStatus::PSB_PEMASANGAN], 'Mulai pemasangan hanya untuk PSB di tahap Pemasangan');
                if ($ticket->repair_started_at) {
                    throw ValidationException::withMessages(['action' => 'Pemasangan untuk PSB ini sudah dimulai']);
                }
                $ticket->repair_started_at = $now;

                return "Teknisi [{$this->repairCrew($ticket, $actor)}] tiba di lokasi dan mulai pemasangan.".$this->recordedOnBehalf($actor);

            case TicketAction::PSB_PASANG_SELESAI:
                $this->requireStatus($ticket, [TicketStatus::PSB_PEMASANGAN], 'Laporan pemasangan hanya untuk PSB di tahap Pemasangan');
                if (! $ticket->repair_started_at) {
                    throw ValidationException::withMessages(['action' => 'Teknisi harus memulai pemasangan sebelum mengirim laporan selesai']);
                }
                $ticket->status = TicketStatus::PSB_KONFIRMASI;
                $ticket->technician_notes = $notes ?? 'Pemasangan di lokasi telah selesai.';
                $ticket->modem = trim((string) ($data['modem'] ?? '')) ?: $ticket->modem;
                $this->fillOdp($ticket, $data, $actor);
                $ticket->resolved_at = $now;
                $materials = $this->inventory->requestForTicket($ticket, $data['materials'] ?? [], $actor);

                return "Teknisi [{$this->repairCrew($ticket, $actor)}] menyelesaikan pemasangan: {$ticket->technician_notes}"
                    .($ticket->modem ? "\nModem/ONT: {$ticket->modem}" : '')
                    .($materials->isNotEmpty() ? "\nMaterial terpakai (menunggu ACC Gudang, stok belum berkurang):\n".$materials->map(fn (string $line) => "• {$line}")->implode("\n") : '')
                    .$this->recordedOnBehalf($actor);

            case TicketAction::PSB_AKTIF:
                $this->requireStatus($ticket, [TicketStatus::PSB_KONFIRMASI], 'Konfirmasi aktif hanya untuk PSB yang menunggu konfirmasi');
                $code = trim((string) ($data['customer_code'] ?? ''));
                // A known ID keeps the directory's spelling of it; without one the new customer gets a fresh ID.
                $ticket->customer_id = $code !== '' ? (Customer::query()->withCode($code)->value('code') ?? $code) : $this->newCustomerCode();
                $ticket->status = TicketStatus::PSB_SELESAI;
                $ticket->confirmation_notes = $notes ?? 'Pelanggan mengonfirmasi internet sudah aktif.';
                $ticket->closed_at = $now;
                $this->rememberCustomer($ticket);

                return "CS ({$actor->name}) konfirmasi ke pelanggan: internet sudah aktif. Pelanggan terdaftar dengan ID {$ticket->customer_id}."
                    .($notes ? " {$notes}" : '');

            case TicketAction::PSB_BELUM_AKTIF:
                $this->requireStatus($ticket, [TicketStatus::PSB_KONFIRMASI], 'Konfirmasi aktif hanya untuk PSB yang menunggu konfirmasi');
                $requireNotes('Tuliskan kendala yang disampaikan pelanggan');
                $ticket->status = TicketStatus::PSB_PEMASANGAN;
                $ticket->resolved_at = null;

                return "CS ({$actor->name}): pelanggan menyatakan internet belum aktif, dikembalikan ke teknisi [{$ticket->technicianNames()}]. {$notes}";

            case TicketAction::PSB_KEMBALI_ANTRIAN:
                $this->requireStatus($ticket, [TicketStatus::PSB_SURVEY, TicketStatus::PSB_PEMASANGAN], 'Hanya PSB yang sedang dikerjakan teknisi yang bisa dikembalikan ke antrian');
                $requireNotes('Tuliskan alasan dikembalikan ke antrian');
                $previousCrew = $ticket->technicianNames() ?: '-';
                $ticket->status = $ticket->status === TicketStatus::PSB_SURVEY ? TicketStatus::PSB_ANTRIAN_SURVEY : TicketStatus::PSB_ANTRIAN_PASANG;
                $ticket->technicians()->sync([]);
                $ticket->setRelation('technicians', new EloquentCollection);
                $ticket->repair_started_at = null;

                return "Helpdesk ({$actor->name}) mengembalikan PSB ke {$ticket->status->label()} dari teknisi [{$previousCrew}]. Alasan: {$notes}";

            case TicketAction::PSB_BATAL:
                if ($ticket->status->isFinal()) {
                    throw ValidationException::withMessages(['action' => 'PSB ini sudah selesai atau dibatalkan']);
                }
                $requireNotes('Tuliskan alasan pembatalan');
                $ticket->status = TicketStatus::PSB_BATAL;
                $ticket->confirmation_notes = $notes;
                $ticket->closed_at = $now;

                return "PSB dibatalkan oleh {$actor->name}. Alasan: {$notes}";
        }

        throw ValidationException::withMessages(['action' => "Aksi {$action->label()} tidak bisa dijalankan dari sini"]);
    }

    /**
     * The pin of a newly typed ODP shareloc; null when none was typed or it is the one already saved.
     *
     * @return array{float, float}|null
     */
    private function odpPin(Ticket $ticket, array $data): ?array
    {
        $location = trim((string) ($data['odp_location'] ?? ''));

        return $location !== '' && $location !== $ticket->odp_location ? GeoLocation::fromText($location) : null;
    }

    /**
     * Who fills in the cable distance from the ODP along the road: Helpdesk reads it off the route in
     * Google Maps. A straight line between the two pins would understate it, so none is computed.
     */
    public static function measuresOdpDistance(User $user): bool
    {
        return $user->hasRole(Role::HELPDESK, Role::SPV);
    }

    /**
     * What a step's form says about the ODP. A blank field keeps what an earlier step recorded.
     */
    private function fillOdp(Ticket $ticket, array $data, User $actor): void
    {
        $ticket->odp = trim((string) ($data['odp'] ?? '')) ?: $ticket->odp;
        $location = trim((string) ($data['odp_location'] ?? ''));
        if ($location !== '' && $location !== $ticket->odp_location) {
            $ticket->odp_location = $location;
            $ticket->odp_latitude = $data['odp_pin'][0] ?? null;
            $ticket->odp_longitude = $data['odp_pin'][1] ?? null;
        }
        if (filled($data['odp_distance'] ?? null) && self::measuresOdpDistance($actor)) {
            $ticket->odp_distance = (int) $data['odp_distance'];
        }
    }

    /**
     * " ODP: ODP-12, jarak kabel ±220 m." for the history, or nothing.
     */
    private function odpSummary(Ticket $ticket): string
    {
        $parts = array_filter([
            $ticket->odp,
            $ticket->odp_distance ? "jarak kabel ±{$ticket->odp_distance} m" : null,
        ]);

        return $parts ? ' ODP: '.implode(', ', $parts).'.' : '';
    }

    /**
     * The Helpdesk who checks the location is the order's Helpdesk from then on.
     */
    private function claimForHelpdesk(Ticket $ticket, User $actor): void
    {
        if ($actor->role === Role::HELPDESK) {
            $ticket->assigned_helpdesk_id = $actor->id;
        }
    }

    /**
     * Sending a ticket back from Perbaikan removes the crew, so the record keeps who it was taken from.
     */
    private function keepsFullDescription(TicketAction $action, TicketStatus $previous): bool
    {
        return in_array($action, self::KEEPS_FULL_DESCRIPTION, true)
            || ($action === TicketAction::ANTRI_TEKNISI && $previous === TicketStatus::PERBAIKAN);
    }

    /**
     * Correct or complete the customer's details, e.g. a shareloc the customer sent after CS made the ticket.
     *
     * @param  array{customer_name: string, customer_phone: string, customer_id?: ?string, customer_address?: ?string}  $data
     */
    public function updateCustomer(Ticket $ticket, array $data, User $actor): Ticket
    {
        $this->ensureRole(TicketAction::UBAH_DATA_PELANGGAN, $actor);
        $address = trim((string) ($data['customer_address'] ?? '')) ?: null;
        // Only a new address is looked up; a short link needs a request to Google, so it happens outside the transaction.
        $addressChanged = $address !== $ticket->customer_address;
        $coordinates = $addressChanged ? GeoLocation::fromText($address) : null;

        [$ticket, $changes] = DB::transaction(function () use ($ticket, $data, $actor, $address, $addressChanged, $coordinates) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            if ($ticket->status->isFinal()) {
                throw ValidationException::withMessages(['customer' => 'Data pelanggan pada tiket yang sudah ditutup tidak dapat diubah']);
            }

            $before = $ticket->only(['customer_name', 'customer_phone', 'customer_id', 'customer_address']);
            $hadLocation = $ticket->hasLocation();
            $code = trim((string) ($data['customer_id'] ?? ''));
            $ticket->fill([
                'customer_name' => trim($data['customer_name']),
                'customer_phone' => PhoneNumber::normalize($data['customer_phone']),
                // A blank ID keeps the one the ticket already has; a known one keeps the customer's spelling.
                'customer_id' => $code !== '' ? (Customer::query()->withCode($code)->value('code') ?? $code) : $ticket->customer_id,
                'customer_address' => $address,
            ]);
            if ($addressChanged) {
                $ticket->latitude = $coordinates[0] ?? null;
                $ticket->longitude = $coordinates[1] ?? null;
            }

            $labels = ['customer_name' => 'Nama', 'customer_phone' => 'WhatsApp', 'customer_id' => 'ID Pelanggan', 'customer_address' => 'Alamat'];
            $changes = collect($labels)
                ->filter(fn ($label, $field) => $before[$field] !== $ticket->{$field})
                ->map(fn ($label, $field) => "{$label}: \"".($before[$field] ?? '-').'" → "'.($ticket->{$field} ?? '-').'"')
                ->values();
            if ($ticket->hasLocation() && (! $hadLocation || $ticket->isDirty(['latitude', 'longitude']))) {
                $changes->push('Titik lokasi: '.GeoLocation::format($ticket->latitude).', '.GeoLocation::format($ticket->longitude));
            } elseif ($hadLocation && ! $ticket->hasLocation()) {
                $changes->push('Titik lokasi dihapus');
            }
            if ($changes->isEmpty()) {
                throw ValidationException::withMessages(['customer' => 'Tidak ada data pelanggan yang berubah']);
            }

            $ticket->save();
            $this->rememberCustomer($ticket);
            $ticket->histories()->create([
                'user_id' => $actor->id,
                'actor_name' => $actor->name,
                'action' => TicketAction::UBAH_DATA_PELANGGAN,
                'from_status' => $ticket->status,
                'to_status' => $ticket->status,
                'notes' => "Data pelanggan diubah oleh {$actor->name}:\n".$changes->implode("\n"),
            ]);

            return [$ticket, $changes];
        });

        if (Setting::read('notify_on_status_change') === '1') {
            $this->outbox->push(
                $this->telegram->customerUpdatedMessage($ticket, $changes->all(), $actor->name),
                ['ticket' => $ticket->ticket_number, 'action' => TicketAction::UBAH_DATA_PELANGGAN->value],
            );
        }

        return $ticket;
    }

    /**
     * Correct or complete what Helpdesk recorded while handling the ticket, e.g. a modem type left blank.
     * Closed tickets stay editable so the monthly report gets the right data. A note can only be
     * corrected where the ticket already has one, so the edit never invents a remote fix or repair.
     *
     * @param  array{modem?: ?string, remote_resolution_notes?: ?string, technician_notes?: ?string}  $data
     */
    public function updateHandling(Ticket $ticket, array $data, User $actor): Ticket
    {
        $this->ensureRole(TicketAction::UBAH_PENANGANAN, $actor);

        return DB::transaction(function () use ($ticket, $data, $actor) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $labels = ['modem' => 'Modem / ONT', 'remote_resolution_notes' => 'Solusi Remote', 'technician_notes' => 'Laporan Teknisi'];
            $before = $ticket->only(array_keys($labels));

            $ticket->modem = trim((string) ($data['modem'] ?? '')) ?: null;
            foreach (['remote_resolution_notes', 'technician_notes'] as $field) {
                if ($before[$field] === null) {
                    continue;
                }
                $notes = trim((string) ($data[$field] ?? ''));
                if ($notes === '') {
                    throw ValidationException::withMessages([$field => "{$labels[$field]} tidak boleh dikosongkan"]);
                }
                $ticket->{$field} = $notes;
            }

            $changes = collect($labels)
                ->filter(fn ($label, $field) => $before[$field] !== $ticket->{$field})
                ->map(fn ($label, $field) => "{$label}: \"".($before[$field] ?? '-').'" → "'.($ticket->{$field} ?? '-').'"')
                ->values();
            if ($changes->isEmpty()) {
                throw ValidationException::withMessages(['handling' => 'Tidak ada data penanganan yang berubah']);
            }

            $ticket->save();
            $ticket->histories()->create([
                'user_id' => $actor->id,
                'actor_name' => $actor->name,
                'action' => TicketAction::UBAH_PENANGANAN,
                'from_status' => $ticket->status,
                'to_status' => $ticket->status,
                'notes' => "Data penanganan diubah oleh {$actor->name}:\n".$changes->implode("\n"),
            ]);

            return $ticket;
        });
    }

    /**
     * Keep the customer directory up to date with the details last written on a ticket, so the
     * next ticket for this customer can be filled from it. An empty address keeps the one on file.
     */
    private function rememberCustomer(Ticket $ticket): void
    {
        if (trim((string) $ticket->customer_id) === '') {
            return;
        }
        $customer = Customer::query()->withCode($ticket->customer_id)->lockForUpdate()->first()
            ?? new Customer(['code' => $ticket->customer_id]);

        $customer->fill(['name' => $ticket->customer_name, 'phone' => $ticket->customer_phone]);
        // A ticket without a pin never wipes the customer's pin, and the bare "lat, lng" the form starts
        // with for a customer who has one does not replace their written address.
        if ($ticket->hasLocation()) {
            $customer->fill(['latitude' => $ticket->latitude, 'longitude' => $ticket->longitude]);
        }
        if ($ticket->customer_address && ! GeoLocation::isPair($ticket->customer_address)) {
            $customer->fill(['address' => $ticket->customer_address]);
        }
        $customer->save();
    }

    /**
     * An ID for a customer CS did not give one, unused by any customer or ticket.
     */
    private function newCustomerCode(): string
    {
        do {
            $code = 'CUST-'.random_int(100000, 999999);
        } while (Customer::query()->withCode($code)->exists() || Ticket::query()->where('customer_id', $code)->exists());

        return $code;
    }

    /**
     * Close every ticket that has waited for customer confirmation longer than the timeout.
     *
     * @return Collection<int, Ticket>
     */
    public function autoCloseUnconfirmed(): Collection
    {
        $deadline = now()->subHours(Ticket::CONFIRMATION_TIMEOUT_HOURS);
        $ids = Ticket::query()->awaitingConfirmation()->where('resolved_at', '<=', $deadline)->pluck('id');

        return $ids->map(fn (int $id) => DB::transaction(function () use ($id, $deadline) {
            $ticket = Ticket::query()->lockForUpdate()->find($id);
            // Skip tickets CS confirmed while this run was going.
            if (! $ticket || $ticket->status !== TicketStatus::MENUNGGU_KONFIRMASI || $ticket->resolved_at > $deadline) {
                return null;
            }

            $notes = 'Ditutup otomatis: tidak ada respon pelanggan dalam '.(Ticket::CONFIRMATION_TIMEOUT_HOURS / 24).' hari setelah penyelesaian.';
            $ticket->update(['status' => TicketStatus::TUTUP, 'confirmation_notes' => $notes, 'closed_at' => now()]);
            $ticket->histories()->create([
                'actor_name' => 'Sistem',
                'action' => TicketAction::TUTUP_OTOMATIS,
                'from_status' => TicketStatus::MENUNGGU_KONFIRMASI,
                'to_status' => TicketStatus::TUTUP,
                'notes' => $notes,
            ]);

            return $ticket;
        }))->filter()->values()->toBase();
    }

    private function notify(Ticket $ticket, TicketAction $action, TicketStatus $previous, string $notes, User $actor, array $data): void
    {
        // A forwarded ticket is new to the helpdesk group, so it is announced like a new ticket.
        if ($action === TicketAction::TERUSKAN_HELPDESK) {
            if (Setting::read('notify_on_new_ticket') === '1') {
                $this->outbox->push(
                    $this->telegram->newTicketMessage($ticket, trim((string) ($data['notes'] ?? '')) ?: null),
                    ['ticket' => $ticket->ticket_number, 'action' => 'NEW_TICKET'],
                );
            }

            return;
        }

        if (Setting::read('notify_on_status_change') !== '1') {
            return;
        }

        $ticket->loadMissing('technicians');
        $text = match ($action) {
            TicketAction::CATATAN_URGENSI_CS => $this->telegram->urgencyMessage($ticket, $notes, $data['urgency_level'] ?? 'MENDESAK', $actor->name),
            TicketAction::CATATAN_INFO_HELPDESK => $this->telegram->urgencyMessage($ticket, $notes, $data['urgency_level'] ?? 'INFO', $actor->name, 'Helpdesk'),
            TicketAction::MULAI_PERBAIKAN => $this->telegram->technicianStartMessage($ticket, $this->repairCrew($ticket, $actor), $actor->role === Role::TEKNISI ? null : $actor->name),
            default => $this->telegram->statusUpdateMessage($ticket, $previous, $notes, $actor->name),
        };

        $this->outbox->push($text, ['ticket' => $ticket->ticket_number, 'action' => $action->value]);
    }

    /**
     * Who did the field work: the technician pressing the button, or the whole assigned crew
     * when Helpdesk records the progress for them.
     */
    private function repairCrew(Ticket $ticket, User $actor): string
    {
        return $actor->role === Role::TEKNISI ? $actor->name : ($ticket->technicianNames() ?: '-');
    }

    private function recordedOnBehalf(User $actor): string
    {
        return $actor->role === Role::TEKNISI ? '' : " (Dicatat oleh {$actor->role->label()} {$actor->name})";
    }

    /**
     * The active technicians picked for an assignment. Every picked id must be an active technician.
     *
     * @param  array<int>  $ids
     * @return Collection<int, User>
     */
    private function activeTechnicians(array $ids, string $message): Collection
    {
        // Blank dropdown rows are ignored.
        $ids = array_unique(array_filter(array_map('intval', $ids)));
        $technicians = User::technicians()->findMany($ids);

        if ($technicians->isEmpty() || $technicians->count() !== count($ids)) {
            throw ValidationException::withMessages(['technician_ids' => $message]);
        }

        return $technicians;
    }

    private function ensureRole(TicketAction $action, User $actor): void
    {
        if (! $actor->hasRole(...$action->allowedRoles())) {
            throw new AuthorizationException('Aksi ini tidak diizinkan untuk peran akun Anda');
        }
    }

    /**
     * @param  array<TicketStatus>  $allowed
     */
    private function requireStatus(Ticket $ticket, array $allowed, string $message): void
    {
        if (! in_array($ticket->status, $allowed, true)) {
            throw ValidationException::withMessages(['action' => "{$message} (status saat ini: {$ticket->status->label()})"]);
        }
    }
}
