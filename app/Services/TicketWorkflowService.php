<?php

namespace App\Services;

use App\Enums\IncidentAction;
use App\Enums\Role;
use App\Enums\TicketAction;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Jobs\SendTelegramMessage;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
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
    private const KEEPS_FULL_DESCRIPTION = [TicketAction::UBAH_TEKNISI, TicketAction::LAPOR_PERBAIKAN_SELESAI, TicketAction::UBAH_KATEGORI];

    public function __construct(
        private TicketNumberGenerator $numbers,
        private TelegramService $telegram,
        private DocumentationPhotoStore $photoStore,
    ) {}

    /**
     * CS registers a customer complaint. The ticket starts idle in the helpdesk queue,
     * or goes straight into an open gamas when the customer is in the affected area.
     */
    public function create(array $data, User $actor, ?Incident $incident = null): Ticket
    {
        $this->ensureRole(TicketAction::TIKET_DIBUAT, $actor);

        $ticket = DB::transaction(function () use ($data, $actor, $incident) {
            if ($incident) {
                $incident = Incident::query()->lockForUpdate()->findOrFail($incident->id);
                if (! $incident->isOpen()) {
                    throw ValidationException::withMessages(['incident_id' => "Gamas {$incident->incident_number} sudah selesai"]);
                }
            }
            $status = $incident ? TicketStatus::GAMAS : TicketStatus::ANTRIAN_HELPDESK;

            $ticket = Ticket::create([
                'ticket_number' => $this->numbers->next(),
                'customer_id' => $data['customer_id'] ?? null ?: 'CUST-'.random_int(1000, 9999),
                'customer_name' => $data['customer_name'],
                'customer_phone' => PhoneNumber::normalize($data['customer_phone']),
                'customer_address' => $data['customer_address'] ?? null,
                'category' => $data['category'] ?? null ?: 'Internet Mati / LOS',
                'priority' => $data['priority'] ?? TicketPriority::SEDANG,
                'complaint' => $data['complaint'],
                'status' => $status,
                'incident_id' => $incident?->id,
                'created_by_id' => $actor->id,
            ]);

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
            SendTelegramMessage::dispatchAfterResponse(
                $this->telegram->newTicketMessage($ticket),
                ['ticket' => $ticket->ticket_number, 'action' => 'NEW_TICKET'],
            );
        }

        return $ticket;
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

        [$ticket, $previous, $description] = DB::transaction(function () use ($ticket, $action, $actor, $data, $notes, $photos, $modem) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $previous = $ticket->status;
            $now = now();

            if ($actor->role === Role::TEKNISI && ! $ticket->isAssignedTo($actor)) {
                throw new AuthorizationException('Tiket ini tidak ditugaskan kepada Anda');
            }

            switch ($action) {
                case TicketAction::SELESAI_EDUKASI_CS:
                    $this->requireStatus($ticket, [TicketStatus::ANTRIAN_HELPDESK],
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

                case TicketAction::KLAIM_HELPDESK:
                    $this->requireStatus($ticket, [TicketStatus::ANTRIAN_HELPDESK, TicketStatus::CEK_ULANG],
                        'Klaim helpdesk hanya bisa dari status Antrian atau Cek Ulang');
                    $ticket->status = TicketStatus::HELPDESK_CEK;
                    $ticket->assigned_helpdesk_id = $actor->id;
                    $description = "Helpdesk ({$actor->name}) memulai pengecekan teknis.";
                    break;

                case TicketAction::SELESAI_REMOTE:
                    $this->requireStatus($ticket, [TicketStatus::HELPDESK_CEK, TicketStatus::CEK_ULANG],
                        'Penyelesaian remote hanya bisa dari tahap Helpdesk Cek atau Cek Ulang');
                    $ticket->status = TicketStatus::MENUNGGU_KONFIRMASI;
                    $ticket->remote_resolution_notes = $notes ?? 'Masalah diselesaikan secara remote (reset/reboot/rebind).';
                    $ticket->modem = $modem ?? $ticket->modem;
                    $ticket->resolved_at = $now;
                    $description = "Penyelesaian remote oleh {$actor->name}: {$ticket->remote_resolution_notes}";
                    break;

                case TicketAction::PERLU_TEKNISI:
                    $this->requireStatus($ticket, [TicketStatus::HELPDESK_CEK, TicketStatus::CEK_ULANG],
                        'Penugasan teknisi hanya bisa dari tahap Helpdesk Cek atau Cek Ulang');
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
                    $this->requireStatus($ticket, [TicketStatus::PERBAIKAN], 'Teknisi hanya bisa diubah saat tiket dalam tahap Perbaikan');
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
                    $description = "Teknisi [{$this->repairCrew($ticket, $actor)}] menyelesaikan perbaikan: {$ticket->technician_notes}"
                        .$this->recordedOnBehalf($actor);
                    break;

                case TicketAction::CATATAN_URGENSI_CS:
                    if ($ticket->status === TicketStatus::TUTUP) {
                        throw ValidationException::withMessages(['action' => 'Tidak dapat menambahkan catatan urgensi pada tiket yang sudah ditutup']);
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
                    $description = "[{$urgency}] CS ({$actor->name}): {$notes}";
                    break;

                case TicketAction::CATATAN_DOKUMENTASI:
                    if ($ticket->status === TicketStatus::TUTUP) {
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
                    throw ValidationException::withMessages(['action' => "Aksi '{$action->value}' tidak dikenal"]);
            }

            $ticket->save();
            $history = $ticket->histories()->create([
                'user_id' => $actor->id,
                'actor_name' => $actor->name,
                'action' => $action,
                'from_status' => $previous,
                'to_status' => $ticket->status,
                'notes' => in_array($action, self::KEEPS_FULL_DESCRIPTION, true) ? $description : ($notes ?? $description),
                'urgency_level' => $action === TicketAction::CATATAN_URGENSI_CS ? ($data['urgency_level'] ?? 'MENDESAK') : null,
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

        $this->notify($ticket, $action, $previous, in_array($action, self::KEEPS_FULL_DESCRIPTION, true) ? $description : ($notes ?? $description), $actor, $data);

        return $ticket;
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
        if (Setting::read('notify_on_status_change') !== '1') {
            return;
        }

        $ticket->loadMissing('technicians');
        $text = match ($action) {
            TicketAction::CATATAN_URGENSI_CS => $this->telegram->urgencyMessage($ticket, $notes, $data['urgency_level'] ?? 'MENDESAK', $actor->name),
            TicketAction::MULAI_PERBAIKAN => $this->telegram->technicianStartMessage($ticket, $this->repairCrew($ticket, $actor), $actor->role === Role::TEKNISI ? null : $actor->name),
            default => $this->telegram->statusUpdateMessage($ticket, $previous, $notes, $actor->name),
        };

        SendTelegramMessage::dispatchAfterResponse($text, ['ticket' => $ticket->ticket_number, 'action' => $action->value]);
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
