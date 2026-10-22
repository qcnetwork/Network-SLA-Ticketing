<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketArchiveTest extends TestCase
{
    use RefreshDatabase;

    private User $helpdesk;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->helpdesk = $this->userWithRole(Role::HELPDESK);
        $this->technician = $this->userWithRole(Role::TEKNISI);
    }

    private function share(Ticket $ticket, int $days = 1)
    {
        return $this->actingAs($this->helpdesk)->post("/tickets/{$ticket->ticket_number}/shares", ['user_id' => $this->technician->id, 'days' => $days]);
    }

    public function test_helpdesk_finds_old_closed_tickets_by_name_number_or_customer_id(): void
    {
        $old = Ticket::factory()->status(TicketStatus::TUTUP)->create([
            'customer_name' => 'Bapak Sutrisno', 'customer_id' => 'PLG-0042', 'closed_at' => now()->subMonths(8),
        ]);
        $other = Ticket::factory()->create(['customer_name' => 'Ibu Wati']);

        $this->actingAs($this->helpdesk)->get('/arsip?q=sutris')->assertOk()->assertSee($old->ticket_number)->assertDontSee($other->ticket_number);
        $this->get('/arsip?q='.strtolower($old->ticket_number))->assertSee('Bapak Sutrisno');
        $this->get('/arsip?q=PLG-0042')->assertSee('Bapak Sutrisno');
        $this->get('/arsip?status=OPEN')->assertSee($other->ticket_number)->assertDontSee($old->ticket_number);
        // Wildcards in the search are matched literally.
        $this->get('/arsip?q=%25')->assertSee('0 tiket ditemukan');
    }

    public function test_cs_has_no_archive_and_technicians_see_only_what_was_shared(): void
    {
        $shared = Ticket::factory()->status(TicketStatus::TUTUP)->create();
        $notShared = Ticket::factory()->status(TicketStatus::TUTUP)->create();
        $this->share($shared)->assertSessionHasNoErrors();

        $this->actingAs($this->userWithRole(Role::CS))->get('/arsip')->assertForbidden();
        $this->actingAs($this->technician)->get('/arsip')->assertOk()
            ->assertSee($shared->ticket_number)->assertDontSee($notShared->ticket_number);
    }

    public function test_a_shared_ticket_is_readable_but_not_actionable_until_it_expires(): void
    {
        $ticket = Ticket::factory()->status(TicketStatus::TUTUP)->create(['customer_name' => 'Pelanggan Lama']);
        $this->actingAs($this->technician)->get("/tickets/{$ticket->ticket_number}")->assertForbidden();

        $this->share($ticket)->assertSessionHasNoErrors();
        $this->assertStringContainsString('membagikan arsip', $this->technician->fresh()->unreadNotifications->first()->data['body']);

        $this->actingAs($this->technician)->get("/tickets/{$ticket->ticket_number}")->assertOk()
            ->assertSee('Pelanggan Lama')->assertSee('Hanya bisa dilihat')->assertDontSee('Bagikan ke Teknisi');
        $this->post("/tickets/{$ticket->ticket_number}/actions/CATATAN_DOKUMENTASI", ['notes' => 'x'])->assertForbidden();
        $this->post("/tickets/{$ticket->ticket_number}/shares", ['user_id' => $this->technician->id, 'days' => 7])->assertForbidden();

        $this->travel(25)->hours();
        $this->get("/tickets/{$ticket->ticket_number}")->assertForbidden();
        $this->get('/arsip')->assertDontSee($ticket->ticket_number);
    }

    public function test_sharing_again_extends_and_revoking_ends_access(): void
    {
        $ticket = Ticket::factory()->status(TicketStatus::TUTUP)->create();
        $this->share($ticket, 1);
        $this->share($ticket, 7);
        $this->assertSame(1, $ticket->shares()->count());
        $this->assertTrue($ticket->shares()->first()->expires_at->isAfter(now()->addDays(6)));

        $this->share($ticket, 30)->assertSessionHasErrors('days');
        $this->actingAs($this->helpdesk)->post("/tickets/{$ticket->ticket_number}/shares", ['user_id' => $this->helpdesk->id, 'days' => 1])
            ->assertSessionHasErrors('user_id');

        $share = $ticket->shares()->first();
        $this->actingAs($this->helpdesk)->get("/tickets/{$ticket->ticket_number}")->assertSee('Cabut');
        $this->delete("/tickets/{$ticket->ticket_number}/shares/{$share->id}")->assertSessionHasNoErrors();
        $this->actingAs($this->technician)->get("/tickets/{$ticket->ticket_number}")->assertForbidden();
    }

    public function test_a_share_cannot_be_revoked_through_another_ticket(): void
    {
        $ticket = Ticket::factory()->create();
        $other = Ticket::factory()->create();
        $this->share($ticket);
        $share = $ticket->shares()->first();

        $this->actingAs($this->helpdesk)->delete("/tickets/{$other->ticket_number}/shares/{$share->id}")->assertNotFound();
        $this->assertModelExists($share);
    }
}
