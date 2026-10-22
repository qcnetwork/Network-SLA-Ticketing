<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Incident;
use App\Models\TelegramLog;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IncidentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $cs;

    private User $helpdesk;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cs = $this->userWithRole(Role::CS);
        $this->helpdesk = $this->userWithRole(Role::HELPDESK);
        $this->technician = $this->userWithRole(Role::TEKNISI);
    }

    private function createIncident(array $ticketIds = []): Incident
    {
        $this->actingAs($this->helpdesk)->post('/gamas', [
            'title' => 'Kabel feeder putus',
            'area' => 'ODP SKM-03',
            'ticket_ids' => $ticketIds,
        ])->assertSessionHasNoErrors();

        return Incident::query()->latest('id')->firstOrFail();
    }

    private function act(User $user, Incident $incident, string $action, array $data = [])
    {
        return $this->actingAs($user)->post("/gamas/{$incident->incident_number}/actions/{$action}", $data);
    }

    public function test_helpdesk_creates_gamas_and_links_only_open_tickets(): void
    {
        $queued = Ticket::factory()->create();
        $repairing = Ticket::factory()->status(TicketStatus::PERBAIKAN)->assignedTo($this->technician)->create(['repair_started_at' => now()]);
        $closed = Ticket::factory()->status(TicketStatus::TUTUP)->create();

        $this->actingAs($this->cs)->post('/gamas', ['title' => 'X', 'area' => 'Y'])->assertForbidden();

        $incident = $this->createIncident([$queued->id, $repairing->id, $closed->id]);

        $this->assertMatchesRegularExpression('/^GMS-\d{8}-01$/', $incident->incident_number);
        $this->assertSame(IncidentStatus::AKTIF, $incident->status);
        $this->assertEqualsCanonicalizing([$queued->id, $repairing->id], $incident->tickets->pluck('id')->all());
        $this->assertSame(TicketStatus::GAMAS, $queued->fresh()->status);
        $this->assertNull($repairing->fresh()->repair_started_at);
        $this->assertSame(TicketStatus::TUTUP, $closed->fresh()->status);
        $this->assertSame('GABUNG_GAMAS', $queued->histories()->latest('id')->first()->action->value);

        // While waiting on the gamas, the per-ticket helpdesk flow is blocked.
        $this->actingAs($this->helpdesk)->post("/tickets/{$queued->ticket_number}/actions/KLAIM_HELPDESK")->assertSessionHasErrors('action');
        $this->post("/tickets/{$queued->ticket_number}/actions/GAMAS_SELESAI")->assertNotFound();
    }

    public function test_cs_can_create_ticket_straight_into_an_open_gamas(): void
    {
        $incident = $this->createIncident();
        $logsBefore = TelegramLog::query()->where('message', 'like', '%TIKET BARU MASUK%')->count();

        $this->actingAs($this->cs)->post('/tickets', [
            'customer_name' => 'Pelanggan B',
            'customer_phone' => '081234567890',
            'category' => 'LOS Merah / Kabel Putus',
            'priority' => 'SEDANG',
            'complaint' => 'LOS merah',
            'incident_id' => $incident->id,
        ])->assertSessionHasNoErrors();

        $ticket = Ticket::query()->latest('id')->firstOrFail();
        $this->assertSame(TicketStatus::GAMAS, $ticket->status);
        $this->assertSame($incident->id, $ticket->incident_id);
        $this->assertSame($logsBefore, TelegramLog::query()->where('message', 'like', '%TIKET BARU MASUK%')->count());

        $incident->update(['status' => IncidentStatus::SELESAI]);
        $this->post('/tickets', [
            'customer_name' => 'Pelanggan C',
            'customer_phone' => '081234567891',
            'category' => 'LOS Merah / Kabel Putus',
            'priority' => 'SEDANG',
            'complaint' => 'LOS merah',
            'incident_id' => $incident->id,
        ])->assertSessionHasErrors('incident_id');
    }

    public function test_queued_ticket_can_be_moved_into_gamas_from_its_page(): void
    {
        $incident = $this->createIncident();
        $ticket = Ticket::factory()->create();
        $ticketUrl = "/tickets/{$ticket->ticket_number}";

        $this->actingAs($this->helpdesk)->get($ticketUrl)->assertOk()
            ->assertSee('Masukkan ke Gamas')->assertSee(route('incidents.tickets.link', $incident), false);

        $this->from($ticketUrl)->post("/gamas/{$incident->incident_number}/tickets", ['ticket_ids' => [$ticket->id]])
            ->assertRedirect($ticketUrl)->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame(TicketStatus::GAMAS, $ticket->status);
        $this->assertSame($incident->id, $ticket->incident_id);
        $this->get($ticketUrl)->assertDontSee('Masukkan ke Gamas');

        $this->from($ticketUrl)->post("/gamas/{$incident->incident_number}/tickets", ['ticket_ids' => [$ticket->id]])
            ->assertSessionHasErrors('ticket_ids');
    }

    public function test_resolving_gamas_moves_waiting_tickets_to_confirmation(): void
    {
        [$first, $second, $unrelated] = Ticket::factory()->count(3)->create();
        $incident = $this->createIncident([$first->id, $second->id, $unrelated->id]);

        $this->actingAs($this->helpdesk)->delete("/gamas/{$incident->incident_number}/tickets/{$unrelated->ticket_number}")
            ->assertSessionHasNoErrors();
        $unrelated->refresh();
        $this->assertSame(TicketStatus::ANTRIAN_HELPDESK, $unrelated->status);
        $this->assertNull($unrelated->incident_id);

        $this->actingAs($this->technician)->get("/gamas/{$incident->incident_number}")->assertForbidden();
        $this->act($this->helpdesk, $incident, 'TUGASKAN_TEKNISI', ['technician_ids' => [$this->cs->id]])->assertSessionHasErrors('technician_ids');
        $this->act($this->helpdesk, $incident, 'TUGASKAN_TEKNISI', ['technician_ids' => [$this->technician->id]])->assertSessionHasNoErrors();
        $this->actingAs($this->technician)->get("/gamas/{$incident->incident_number}")->assertOk();

        $this->act($this->technician, $incident, 'GAMAS_SELESAI', ['notes' => 'Too early'])->assertSessionHasErrors('action');
        $this->act($this->technician, $incident, 'MULAI_PERBAIKAN')->assertSessionHasNoErrors();
        $this->act($this->technician, $incident, 'GAMAS_SELESAI')->assertSessionHasErrors('notes');
        $this->act($this->technician, $incident, 'GAMAS_SELESAI', ['notes' => 'Sambung ulang feeder'])->assertSessionHasNoErrors();

        $incident->refresh();
        $this->assertSame(IncidentStatus::SELESAI, $incident->status);
        $this->assertSame('Sambung ulang feeder', $incident->resolution_notes);
        foreach ([$first, $second] as $ticket) {
            $ticket->refresh();
            $this->assertSame(TicketStatus::MENUNGGU_KONFIRMASI, $ticket->status);
            $this->assertNotNull($ticket->resolved_at);
        }
        $this->assertSame(TicketStatus::ANTRIAN_HELPDESK, $unrelated->fresh()->status);

        // CS still confirms each customer on their own ticket.
        $this->actingAs($this->cs)->post("/tickets/{$first->ticket_number}/actions/KONFIRMASI_NORMAL")->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::TUTUP, $first->fresh()->status);

        $this->act($this->helpdesk, $incident, 'UPDATE_PROGRES', ['notes' => 'Late'])->assertSessionHasErrors('action');
        $this->actingAs($this->cs)->post("/gamas/{$incident->incident_number}/tickets", ['ticket_ids' => [$unrelated->id]])
            ->assertSessionHasErrors('action');
    }

    public function test_gamas_can_be_assigned_to_several_technicians(): void
    {
        $partner = $this->userWithRole(Role::TEKNISI);
        $helper = $this->userWithRole(Role::TEKNISI);
        $incident = $this->createIncident();

        $this->act($this->helpdesk, $incident, 'TUGASKAN_TEKNISI', ['technician_ids' => [$this->technician->id, $this->cs->id]])
            ->assertSessionHasErrors('technician_ids');
        $this->act($this->helpdesk, $incident, 'TUGASKAN_TEKNISI', ['technician_ids' => [$this->technician->id, $partner->id]])
            ->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$this->technician->id, $partner->id], $incident->technicians()->pluck('users.id')->all());
        $this->actingAs($helper)->get("/gamas/{$incident->incident_number}")->assertForbidden();

        $this->act($partner, $incident, 'MULAI_PERBAIKAN')->assertSessionHasNoErrors();
        $this->actingAs($this->technician)->get("/gamas/{$incident->incident_number}")->assertOk()->assertDontSee('Saya Sudah di Lokasi');

        // Adding a helper keeps the repair going; replacing the whole crew restarts it.
        $this->act($this->helpdesk, $incident, 'TUGASKAN_TEKNISI', ['technician_ids' => [$partner->id, $helper->id]])->assertSessionHasNoErrors();
        $this->assertNotNull($incident->fresh()->repair_started_at);
        $this->actingAs($this->technician)->get("/gamas/{$incident->incident_number}")->assertForbidden();

        $this->act($this->helpdesk, $incident, 'TUGASKAN_TEKNISI', ['technician_ids' => [$this->technician->id]])->assertSessionHasNoErrors();
        $this->assertNull($incident->fresh()->repair_started_at);
    }

    public function test_progress_update_photos_are_only_served_to_gamas_viewers(): void
    {
        Storage::fake(TicketAttachment::DISK);
        $incident = $this->createIncident();

        $this->act($this->helpdesk, $incident, 'UPDATE_PROGRES')->assertSessionHasErrors('notes');
        $this->act($this->helpdesk, $incident, 'UPDATE_PROGRES', [
            'notes' => 'Penarikan kabel 60%',
            'estimated_resolved_at' => '2026-10-12 21:00',
            'photos' => [UploadedFile::fake()->image('kabel.jpg')],
        ])->assertSessionHasNoErrors();

        $incident->refresh();
        $this->assertSame('2026-10-12 21:00', $incident->estimated_resolved_at->format('Y-m-d H:i'));
        $attachment = $incident->attachments->sole();
        Storage::disk(TicketAttachment::DISK)->assertExists($attachment->path);

        $this->actingAs($this->cs)->get("/gamas/{$incident->incident_number}/attachments/{$attachment->id}")->assertOk();
        $this->actingAs($this->technician)->get("/gamas/{$incident->incident_number}/attachments/{$attachment->id}")->assertForbidden();
    }

    public function test_technician_loses_access_once_the_gamas_is_closed(): void
    {
        $incident = $this->createIncident();
        $this->act($this->helpdesk, $incident, 'TUGASKAN_TEKNISI', ['technician_ids' => [$this->technician->id]])->assertSessionHasNoErrors();
        $this->actingAs($this->technician)->get("/gamas/{$incident->incident_number}")->assertOk();

        $incident->update(['status' => IncidentStatus::SELESAI]);

        $this->get("/gamas/{$incident->incident_number}")->assertForbidden();
        $this->get('/gamas')->assertOk()->assertDontSee($incident->incident_number);
    }

    public function test_a_gamas_attachment_cannot_be_read_through_another_gamas(): void
    {
        Storage::fake(TicketAttachment::DISK);
        $first = $this->createIncident();
        $this->act($this->helpdesk, $first, 'UPDATE_PROGRES', ['notes' => 'Foto', 'photos' => [UploadedFile::fake()->image('kabel.jpg')]])
            ->assertSessionHasNoErrors();
        $attachment = $first->attachments()->sole();
        $second = $this->createIncident();

        $this->actingAs($this->cs)->get("/gamas/{$second->incident_number}/attachments/{$attachment->id}")->assertNotFound();
    }

    public function test_gamas_pages_render(): void
    {
        $ticket = Ticket::factory()->create(['customer_name' => 'Pelanggan Gamas']);
        $incident = $this->createIncident([$ticket->id]);

        $this->actingAs($this->cs)->get('/gamas')->assertOk()->assertSee($incident->incident_number);
        $this->get("/gamas/{$incident->incident_number}")->assertOk()->assertSee('Pelanggan Gamas');
        $this->get('/dashboard')->assertOk()->assertSee('gangguan massal sedang berlangsung');
        $this->get("/tickets/{$ticket->ticket_number}")->assertOk()->assertSee('Bagian dari Gamas');
        $this->get("/tickets/create?incident={$incident->id}")->assertOk()->assertSee($incident->incident_number);
        $this->get('/gamas/create')->assertForbidden();
        $unlinked = Ticket::factory()->create();
        $this->actingAs($this->helpdesk)->get('/gamas/create')->assertOk()
            ->assertSee($unlinked->ticket_number)->assertDontSee($ticket->ticket_number);
    }
}
