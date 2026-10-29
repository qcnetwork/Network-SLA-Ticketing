<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Services\MonthlyReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianQueueTest extends TestCase
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

    private function checkedTicket(): Ticket
    {
        $ticket = Ticket::factory()->create();
        $this->act($this->helpdesk, $ticket, 'KLAIM_HELPDESK')->assertSessionHasNoErrors();

        return $ticket;
    }

    private function act(User $user, Ticket $ticket, string $action, array $data = [])
    {
        return $this->actingAs($user)->post("/tickets/{$ticket->ticket_number}/actions/{$action}", $data);
    }

    public function test_helpdesk_parks_a_ticket_until_a_technician_is_free(): void
    {
        $ticket = $this->checkedTicket();
        $this->actingAs($this->helpdesk)->get("/tickets/{$ticket->ticket_number}")->assertSee('Masukkan Antrian Teknisi');

        $this->act($this->helpdesk, $ticket, 'ANTRI_TEKNISI')->assertSessionHasErrors('notes');
        $this->act($this->cs, $ticket, 'ANTRI_TEKNISI', ['notes' => 'x'])->assertForbidden();
        $this->act($this->helpdesk, $ticket, 'ANTRI_TEKNISI', ['notes' => 'LOS merah, perlu cek ODP', 'modem' => 'ZTE F609'])->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame(TicketStatus::ANTRIAN_TEKNISI, $ticket->status);
        $this->assertSame('ZTE F609', $ticket->modem);
        $this->assertCount(0, $ticket->technicians);
        $this->assertStringContainsString('LOS merah, perlu cek ODP', $ticket->histories->last()->notes);

        // CS is told so they can let the customer know a visit is being scheduled.
        $this->assertStringContainsString('menunggu jadwal', $this->cs->fresh()->unreadNotifications->first()->data['body']);
        $this->assertCount(0, $this->technician->fresh()->unreadNotifications);

        // No technician can see it yet: it is nobody's job until it is assigned.
        $this->actingAs($this->technician)->get("/tickets/{$ticket->ticket_number}")->assertForbidden();

        $this->actingAs($this->helpdesk)->get("/tickets/{$ticket->ticket_number}")->assertOk()
            ->assertSee('Menunggu teknisi sejak')
            ->assertSee('Tugaskan Teknisi')
            ->assertDontSee('Masukkan Antrian Teknisi');
        $this->get('/dashboard')->assertOk()->assertSee('Antrian Teknisi');
    }

    public function test_queued_ticket_is_assigned_later_and_counts_for_the_technician(): void
    {
        $ticket = $this->checkedTicket();
        $this->act($this->helpdesk, $ticket, 'ANTRI_TEKNISI', ['notes' => 'Perlu cek lokasi']);
        $this->act($this->helpdesk, $ticket, 'ANTRI_TEKNISI', ['notes' => 'lagi'])->assertSessionHasErrors('action');

        $this->act($this->helpdesk, $ticket, 'PERLU_TEKNISI', ['technician_ids' => [$this->technician->id], 'notes' => 'Besok pagi'])
            ->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::PERBAIKAN, $ticket->fresh()->status);
        $this->assertStringContainsString('Anda ditugaskan', $this->technician->fresh()->unreadNotifications->first()->data['body']);

        $this->act($this->technician, $ticket, 'MULAI_PERBAIKAN');
        $this->act($this->technician, $ticket, 'LAPOR_PERBAIKAN_SELESAI', ['notes' => 'Ganti konektor']);
        $this->act($this->cs, $ticket, 'KONFIRMASI_NORMAL');

        $closure = app(MonthlyReportService::class)->build(now()->year, now()->month)['closure']->keyBy('key');
        $this->assertSame(1, $closure['closed_teknisi']['count']);
    }

    public function test_queued_ticket_can_still_be_fixed_remotely(): void
    {
        $ticket = $this->checkedTicket();
        $this->act($this->helpdesk, $ticket, 'ANTRI_TEKNISI', ['notes' => 'Perlu cek lokasi']);

        $this->act($this->helpdesk, $ticket, 'SELESAI_REMOTE', ['notes' => 'Ternyata cukup reboot OLT port'])->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::MENUNGGU_KONFIRMASI, $ticket->fresh()->status);
    }

    public function test_a_stalled_repair_goes_back_to_the_queue_and_is_handed_out_again(): void
    {
        $ticket = $this->checkedTicket();
        $this->act($this->helpdesk, $ticket, 'PERLU_TEKNISI', ['technician_ids' => [$this->technician->id]])->assertSessionHasNoErrors();
        $this->act($this->technician, $ticket, 'MULAI_PERBAIKAN')->assertSessionHasNoErrors();
        $this->actingAs($this->helpdesk)->get("/tickets/{$ticket->ticket_number}")->assertSee('Pending Kunjungan');

        $this->act($this->technician, $ticket, 'ANTRI_TEKNISI', ['notes' => 'x'])->assertForbidden();
        $this->act($this->helpdesk, $ticket, 'ANTRI_TEKNISI')->assertSessionHasErrors(['notes' => 'Tuliskan alasan tiket dikembalikan ke Antrian Teknisi']);
        $this->act($this->helpdesk, $ticket, 'ANTRI_TEKNISI', ['notes' => 'Material kabel belum ada'])->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame(TicketStatus::ANTRIAN_TEKNISI, $ticket->status);
        $this->assertCount(0, $ticket->technicians);
        $this->assertNull($ticket->repair_started_at);
        $history = $ticket->histories->last()->notes;
        $this->assertStringContainsString('Material kabel belum ada', $history);
        $this->assertStringContainsString($this->technician->name, $history);
        $this->actingAs($this->technician)->get("/tickets/{$ticket->ticket_number}")->assertForbidden();

        // Handed out again later, the visit starts from scratch.
        $this->act($this->helpdesk, $ticket, 'PERLU_TEKNISI', ['technician_ids' => [$this->technician->id]])->assertSessionHasNoErrors();
        $this->act($this->technician, $ticket, 'LAPOR_PERBAIKAN_SELESAI', ['notes' => 'Selesai'])->assertSessionHasErrors('action');
        $this->act($this->technician, $ticket, 'MULAI_PERBAIKAN')->assertSessionHasNoErrors();
    }

    public function test_only_a_checked_ticket_can_be_queued(): void
    {
        $waiting = Ticket::factory()->create();

        $this->act($this->helpdesk, $waiting, 'ANTRI_TEKNISI', ['notes' => 'Belum dicek'])->assertSessionHasErrors('action');
        $this->assertSame(TicketStatus::ANTRIAN_HELPDESK, $waiting->fresh()->status);
    }
}
