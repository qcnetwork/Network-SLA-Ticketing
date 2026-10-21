<?php

namespace App\Services;

use App\Enums\IncidentAction;
use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Enums\TicketAction;
use App\Enums\TicketStatus;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Handles a mass outage (gamas): one repair flow that drives the tickets of every affected customer.
 */
class IncidentWorkflowService
{
    /**
     * Ticket statuses that can still be pulled into a gamas. Resolved or closed tickets are left alone.
     */
    public const LINKABLE_STATUSES = [
        TicketStatus::PROSES_CS,
        TicketStatus::ANTRIAN_HELPDESK,
        TicketStatus::HELPDESK_CEK,
        TicketStatus::ANTRIAN_TEKNISI,
        TicketStatus::PERBAIKAN,
        TicketStatus::CEK_ULANG,
    ];

    public function __construct(
        private TelegramService $telegram,
        private TelegramOutbox $outbox,
        private DocumentationPhotoStore $photoStore,
        private TicketNotifier $notifier,
    ) {}

    /**
     * @param  array{title: string, area: string, cause?: ?string, estimated_resolved_at?: ?string}  $data
     * @param  array<int>  $ticketIds
     */
    public function create(array $data, User $actor, array $ticketIds = []): Incident
    {
        $this->ensureRole(IncidentAction::GAMAS_DIBUAT, $actor);

        $incident = DB::transaction(function () use ($data, $actor, $ticketIds) {
            $incident = Incident::create([
                'incident_number' => $this->nextNumber(),
                'title' => $data['title'],
                'area' => $data['area'],
                'cause' => $data['cause'] ?? null,
                'estimated_resolved_at' => $data['estimated_resolved_at'] ?? null,
                'status' => IncidentStatus::AKTIF,
                'created_by_id' => $actor->id,
            ]);

            $this->record($incident, IncidentAction::GAMAS_DIBUAT, $actor, $incident->cause ?? 'Gangguan massal dibuka.');
            $this->attachTickets($incident, $ticketIds, $actor);
            $this->notifier->gamasOpened($incident, $actor);

            return $incident;
        });

        if (Setting::read('notify_on_new_ticket') === '1') {
            $this->sendTelegram($incident, IncidentAction::GAMAS_DIBUAT, $incident->cause, $actor);
        }

        return $incident;
    }

    /**
     * Pull existing customer tickets into the gamas. Returns how many tickets were linked.
     *
     * @param  array<int>  $ticketIds
     */
    public function linkTickets(Incident $incident, array $ticketIds, User $actor): int
    {
        $this->ensureRole(IncidentAction::TIKET_DITAUTKAN, $actor);

        return DB::transaction(function () use ($incident, $ticketIds, $actor) {
            return $this->attachTickets($this->lockOpen($incident), $ticketIds, $actor);
        });
    }

    /**
     * Take a ticket out of the gamas (e.g. the customer's problem turned out to be unrelated).
     * It goes back to the helpdesk queue to be handled on its own.
     */
    public function detachTicket(Incident $incident, Ticket $ticket, User $actor): void
    {
        $this->ensureRole(IncidentAction::TIKET_DILEPAS, $actor);

        DB::transaction(function () use ($incident, $ticket, $actor) {
            $incident = $this->lockOpen($incident);
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            if ($ticket->incident_id !== $incident->id || $ticket->status !== TicketStatus::GAMAS) {
                throw ValidationException::withMessages(['ticket' => "Tiket {$ticket->ticket_number} tidak sedang menunggu gamas ini"]);
            }

            $ticket->update(['status' => TicketStatus::ANTRIAN_HELPDESK, 'incident_id' => null]);
            $ticket->histories()->create([
                'user_id' => $actor->id,
                'actor_name' => $actor->name,
                'action' => TicketAction::LEPAS_GAMAS,
                'from_status' => TicketStatus::GAMAS,
                'to_status' => TicketStatus::ANTRIAN_HELPDESK,
                'notes' => "Dilepas dari gamas {$incident->incident_number}. Tiket kembali ke Antrian Helpdesk untuk ditangani terpisah.",
            ]);

            $this->record($incident, IncidentAction::TIKET_DILEPAS, $actor, "Tiket {$ticket->ticket_number} ({$ticket->customer_name}) dilepas.");
        });
    }

    /**
     * @param  array{notes?: ?string, technician_ids?: array<int>, estimated_resolved_at?: ?string, photos?: array<UploadedFile>}  $data
     */
    public function perform(Incident $incident, IncidentAction $action, User $actor, array $data = []): Incident
    {
        $this->ensureRole($action, $actor);
        $notes = trim((string) ($data['notes'] ?? '')) ?: null;
        $photos = $data['photos'] ?? [];

        [$incident, $description] = DB::transaction(function () use ($incident, $action, $actor, $data, $notes, $photos) {
            $incident = $this->lockOpen($incident);

            if ($actor->role === Role::TEKNISI && ! $incident->isAssignedTo($actor)) {
                throw new AuthorizationException('Gamas ini tidak ditugaskan kepada Anda');
            }

            switch ($action) {
                case IncidentAction::TUGASKAN_TEKNISI:
                    $technicians = $this->activeTechnicians($data['technician_ids'] ?? []);
                    // Adding a helper keeps the repair going; only a whole new crew has to start over.
                    if ($technicians->intersect($incident->technicians)->isEmpty()) {
                        $incident->repair_started_at = null;
                    }
                    $incident->technicians()->sync($technicians->modelKeys());
                    $incident->setRelation('technicians', $technicians);
                    $incident->status = IncidentStatus::PERBAIKAN;
                    $description = "Teknisi [{$incident->technicianNames()}] ditugaskan menangani gamas."
                        .($notes ? " Instruksi: {$notes}" : '');
                    break;

                case IncidentAction::MULAI_PERBAIKAN:
                    if ($incident->status !== IncidentStatus::PERBAIKAN) {
                        throw ValidationException::withMessages(['action' => 'Tugaskan teknisi terlebih dahulu sebelum memulai perbaikan']);
                    }
                    if ($incident->repair_started_at) {
                        throw ValidationException::withMessages(['action' => 'Perbaikan gamas ini sudah dimulai']);
                    }
                    $incident->repair_started_at = now();
                    $description = "Teknisi [{$actor->name}] tiba di lokasi dan mulai perbaikan.";
                    break;

                case IncidentAction::UPDATE_PROGRES:
                    $estimate = ! empty($data['estimated_resolved_at']) ? Carbon::parse($data['estimated_resolved_at']) : null;
                    if (! $notes && ! $photos && ! $estimate) {
                        throw ValidationException::withMessages(['notes' => 'Isi catatan, estimasi selesai, atau lampirkan foto/video']);
                    }
                    if ($estimate) {
                        $incident->estimated_resolved_at = $estimate;
                    }
                    $description = trim(($notes ?? ($estimate ? '' : count($photos).' foto/video dokumentasi dilampirkan.'))
                        .($estimate ? ' Estimasi selesai diperbarui: '.$estimate->format('d/m/Y H:i').'.' : ''));
                    break;

                case IncidentAction::GAMAS_SELESAI:
                    if (! $notes) {
                        throw ValidationException::withMessages(['notes' => 'Tuliskan tindakan perbaikan gamas']);
                    }
                    if ($actor->role === Role::TEKNISI && ! $incident->repair_started_at) {
                        throw ValidationException::withMessages(['action' => 'Mulai perbaikan terlebih dahulu sebelum menyelesaikan gamas']);
                    }
                    $incident->status = IncidentStatus::SELESAI;
                    $incident->resolution_notes = $notes;
                    $incident->resolved_at = now();
                    $count = $this->resolveTickets($incident, $actor);
                    $description = "Gamas selesai: {$notes} ({$count} tiket pelanggan menunggu konfirmasi CS).";
                    break;

                default:
                    throw ValidationException::withMessages(['action' => "Aksi '{$action->value}' tidak dikenal"]);
            }

            $incident->save();
            $update = $this->record($incident, $action, $actor, $description);

            foreach ($photos as $photo) {
                $this->photoStore->store($photo, $actor, ['incident_id' => $incident->id, 'incident_update_id' => $update->id]);
            }

            return [$incident, $description];
        });

        // Progress updates stay on the gamas page; the group only hears about status changes.
        if ($action !== IncidentAction::UPDATE_PROGRES && Setting::read('notify_on_status_change') === '1') {
            $this->sendTelegram($incident, $action, $description, $actor);
        }

        return $incident;
    }

    /**
     * @param  array<int>  $ticketIds
     */
    private function attachTickets(Incident $incident, array $ticketIds, User $actor): int
    {
        $tickets = Ticket::query()->lockForUpdate()
            ->whereIn('id', $ticketIds)
            ->whereNull('incident_id')
            ->whereIn('status', self::LINKABLE_STATUSES)
            ->get();

        foreach ($tickets as $ticket) {
            $previous = $ticket->status;
            // A repair visit already in progress is superseded by the gamas repair.
            $ticket->update(['status' => TicketStatus::GAMAS, 'incident_id' => $incident->id, 'repair_started_at' => null]);
            $ticket->histories()->create([
                'user_id' => $actor->id,
                'actor_name' => $actor->name,
                'action' => TicketAction::GABUNG_GAMAS,
                'from_status' => $previous,
                'to_status' => TicketStatus::GAMAS,
                'notes' => "Terdampak gangguan massal {$incident->incident_number}: {$incident->title} ({$incident->area}).",
            ]);
        }

        if ($tickets->isNotEmpty()) {
            $this->record($incident, IncidentAction::TIKET_DITAUTKAN, $actor,
                $tickets->count().' tiket ditautkan: '.$tickets->pluck('ticket_number')->implode(', '));
        }

        return $tickets->count();
    }

    /**
     * Every ticket still waiting on the gamas moves to customer confirmation.
     * CS confirms each customer, so one with a separate problem can still be re-checked.
     */
    private function resolveTickets(Incident $incident, User $actor): int
    {
        $tickets = $incident->tickets()->lockForUpdate()->where('status', TicketStatus::GAMAS)->get();

        foreach ($tickets as $ticket) {
            $ticket->update(['status' => TicketStatus::MENUNGGU_KONFIRMASI, 'resolved_at' => $incident->resolved_at]);
            $ticket->histories()->create([
                'user_id' => $actor->id,
                'actor_name' => $actor->name,
                'action' => TicketAction::GAMAS_SELESAI,
                'from_status' => TicketStatus::GAMAS,
                'to_status' => TicketStatus::MENUNGGU_KONFIRMASI,
                'notes' => "Gamas {$incident->incident_number} selesai: {$incident->resolution_notes}",
            ]);
        }

        return $tickets->count();
    }

    private function lockOpen(Incident $incident): Incident
    {
        $incident = Incident::query()->lockForUpdate()->findOrFail($incident->id);

        if (! $incident->isOpen()) {
            throw ValidationException::withMessages(['action' => "Gamas {$incident->incident_number} sudah selesai"]);
        }

        return $incident;
    }

    private function record(Incident $incident, IncidentAction $action, User $actor, string $notes): IncidentUpdate
    {
        return $incident->updates()->create([
            'user_id' => $actor->id,
            'actor_name' => $actor->name,
            'action' => $action,
            'notes' => $notes,
        ]);
    }

    private function sendTelegram(Incident $incident, IncidentAction $action, ?string $notes, User $actor): void
    {
        $incident->loadMissing('technicians')->loadCount('tickets');

        $this->outbox->push(
            $this->telegram->incidentMessage($incident, $action, $notes, $actor->name),
            ['incident' => $incident->incident_number, 'action' => $action->value],
        );
    }

    /**
     * Next number in the GMS-YYYYMMDD-NN format. Call inside a DB transaction.
     */
    private function nextNumber(): string
    {
        $prefix = 'GMS-'.now()->format('Ymd').'-';
        $last = Incident::query()->where('incident_number', 'like', $prefix.'%')->orderByDesc('incident_number')->value('incident_number');
        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
    }

    /**
     * The active technicians picked for the gamas. Every picked id must be an active technician.
     *
     * @param  array<int>  $ids
     * @return Collection<int, User>
     */
    private function activeTechnicians(array $ids): Collection
    {
        // Blank dropdown rows are ignored.
        $ids = array_unique(array_filter(array_map('intval', $ids)));
        $technicians = User::technicians()->findMany($ids);

        if ($technicians->isEmpty() || $technicians->count() !== count($ids)) {
            throw ValidationException::withMessages(['technician_ids' => 'Pilih minimal satu teknisi aktif untuk menangani gamas']);
        }

        return $technicians;
    }

    private function ensureRole(IncidentAction $action, User $actor): void
    {
        if (! $actor->hasRole(...$action->allowedRoles())) {
            throw new AuthorizationException('Aksi ini tidak diizinkan untuk peran akun Anda');
        }
    }
}
