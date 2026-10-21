<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $cs;

    private User $otherCs;

    private User $helpdesk;

    private User $otherHelpdesk;

    private User $technician;

    private User $otherTechnician;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cs = $this->userWithRole(Role::CS);
        $this->otherCs = $this->userWithRole(Role::CS);
        $this->helpdesk = $this->userWithRole(Role::HELPDESK);
        $this->otherHelpdesk = $this->userWithRole(Role::HELPDESK);
        $this->technician = $this->userWithRole(Role::TEKNISI);
        $this->otherTechnician = $this->userWithRole(Role::TEKNISI);
        $this->supervisor = $this->userWithRole(Role::SPV);
    }

    private function createTicket(User $by): Ticket
    {
        $this->actingAs($by)->post('/tickets', [
            'customer_name' => 'Budi', 'customer_phone' => '081234567890', 'category' => 'Lainnya',
            'priority' => 'SEDANG', 'complaint' => 'Internet mati',
        ])->assertSessionHasNoErrors();

        return Ticket::query()->latest('id')->firstOrFail();
    }

    private function act(User $user, Ticket $ticket, string $action, array $data = []): void
    {
        $this->actingAs($user)->post("/tickets/{$ticket->ticket_number}/actions/{$action}", $data)->assertSessionHasNoErrors();
    }

    /**
     * Unread notification bodies per user, so each step can check exactly who heard about it.
     *
     * @return array<string, array<string>>
     */
    private function inboxes(): array
    {
        return collect(['cs', 'otherCs', 'helpdesk', 'otherHelpdesk', 'technician', 'otherTechnician', 'supervisor'])
            ->mapWithKeys(fn (string $who) => [$who => $this->{$who}->fresh()->unreadNotifications->pluck('data.body')->all()])
            ->all();
    }

    private function readAll(): void
    {
        foreach (User::all() as $user) {
            $user->unreadNotifications->markAsRead();
        }
    }

    public function test_each_role_hears_about_the_nodes_it_works_on(): void
    {
        // CS makes their own ticket: no one else needs telling yet.
        $ticket = $this->createTicket($this->cs);
        $this->assertSame([], array_merge(...array_values($this->inboxes())));

        // Forwarded to the queue: every helpdesk agent, nobody else.
        $this->act($this->cs, $ticket, 'TERUSKAN_HELPDESK');
        $inbox = $this->inboxes();
        $this->assertCount(1, $inbox['helpdesk']);
        $this->assertCount(1, $inbox['otherHelpdesk']);
        $this->assertStringContainsString('Masuk Antrian Helpdesk oleh '.$this->cs->name, $inbox['helpdesk'][0]);
        $this->assertSame([], [...$inbox['cs'], ...$inbox['otherCs'], ...$inbox['technician'], ...$inbox['supervisor']]);
        $this->readAll();

        // Claiming is internal to helpdesk.
        $this->act($this->helpdesk, $ticket, 'KLAIM_HELPDESK');
        $this->assertSame([], array_merge(...array_values($this->inboxes())));

        // Sent to repair: CS (to brief the customer) and only the assigned technician.
        $this->act($this->helpdesk, $ticket, 'PERLU_TEKNISI', ['technician_ids' => [$this->technician->id]]);
        $inbox = $this->inboxes();
        $this->assertStringContainsString('Kabari pelanggan', $inbox['cs'][0]);
        $this->assertCount(1, $inbox['otherCs']);
        $this->assertStringContainsString('Anda ditugaskan', $inbox['technician'][0]);
        $this->assertSame([], [...$inbox['otherTechnician'], ...$inbox['helpdesk'], ...$inbox['otherHelpdesk']]);
        $this->readAll();

        // A helper added during the repair hears about it; the technician already on it does not again.
        $this->act($this->helpdesk, $ticket, 'UBAH_TEKNISI', ['technician_ids' => [$this->technician->id, $this->otherTechnician->id], 'notes' => 'Butuh bantuan']);
        $inbox = $this->inboxes();
        $this->assertCount(1, $inbox['otherTechnician']);
        $this->assertSame([], [...$inbox['technician'], ...$inbox['cs']]);
        $this->readAll();

        // Repaired: CS confirms with the customer.
        $this->act($this->technician, $ticket, 'MULAI_PERBAIKAN');
        $this->act($this->technician, $ticket, 'LAPOR_PERBAIKAN_SELESAI', ['notes' => 'Ganti konektor']);
        $inbox = $this->inboxes();
        $this->assertStringContainsString('siap dikonfirmasi', $inbox['cs'][0]);
        $this->assertSame([], [...$inbox['helpdesk'], ...$inbox['supervisor']]);
        $this->readAll();

        // Still broken: back to helpdesk for a recheck.
        $this->act($this->cs, $ticket, 'KONFIRMASI_TERKENDALA');
        $inbox = $this->inboxes();
        $this->assertStringContainsString('cek ulang', $inbox['helpdesk'][0]);
        $this->assertCount(1, $inbox['otherHelpdesk']);
        $this->assertSame([], [...$inbox['cs'], ...$inbox['otherCs']]);
    }

    public function test_ticket_made_by_helpdesk_is_announced_to_cs(): void
    {
        $this->createTicket($this->helpdesk);

        $inbox = $this->inboxes();
        $this->assertStringContainsString('menunggu diproses CS', $inbox['cs'][0]);
        $this->assertCount(1, $inbox['otherCs']);
        $this->assertSame([], [...$inbox['helpdesk'], ...$inbox['otherHelpdesk']]);
    }

    public function test_supervisors_hear_about_new_gamas_and_inactive_users_hear_nothing(): void
    {
        $otherSupervisor = $this->userWithRole(Role::SPV);
        $retired = $this->userWithRole(Role::SPV, ['active' => false]);

        $this->actingAs($this->helpdesk)->post('/gamas', ['title' => 'Kabel feeder putus', 'area' => 'Perum Indah'])->assertSessionHasNoErrors();

        $this->assertStringContainsString('Kabel feeder putus (Perum Indah)', $this->supervisor->unreadNotifications->first()->data['body']);
        $this->assertCount(1, $otherSupervisor->unreadNotifications);
        $this->assertCount(0, $retired->unreadNotifications);
        $this->assertCount(0, $this->cs->unreadNotifications);
    }

    public function test_bell_lists_opens_and_clears_notifications(): void
    {
        $ticket = $this->createTicket($this->helpdesk);

        $this->actingAs($this->cs)->get('/dashboard')->assertOk()->assertSee('x-data="notificationBell(', false);

        $data = $this->getJson('/notifications')->assertOk()->json();
        $this->assertSame(1, $data['unread']);
        $this->assertSame("{$ticket->ticket_number} · Budi", $data['items'][0]['title']);
        $this->assertFalse($data['items'][0]['read']);

        // Opening marks it read and lands on the ticket.
        $this->get($data['items'][0]['url'])->assertRedirect("/tickets/{$ticket->ticket_number}");
        $this->assertSame(0, $this->getJson('/notifications')->json('unread'));

        $this->createTicket($this->helpdesk);
        $this->actingAs($this->cs)->postJson('/notifications/read-all')->assertOk();
        $this->assertSame(0, $this->getJson('/notifications')->json('unread'));
    }

    public function test_two_users_of_one_role_read_their_notifications_independently(): void
    {
        $atox = $this->helpdesk;
        $esa = $this->otherHelpdesk;
        $ticket = $this->createTicket($this->cs);
        $this->act($this->cs, $ticket, 'TERUSKAN_HELPDESK');

        // Atox opens his; Esa's copy of the same news stays unread.
        $this->actingAs($atox)->get($this->getJson('/notifications')->json('items.0.url'))->assertRedirect();
        $this->assertSame(0, $this->getJson('/notifications')->json('unread'));

        $this->flushSession();
        $this->actingAs($esa);
        $this->assertSame(1, $this->getJson('/notifications')->json('unread'));
        $this->assertFalse($this->getJson('/notifications')->json('items.0.read'));
    }

    public function test_each_user_has_their_own_notification_log(): void
    {
        $first = $this->createTicket($this->helpdesk);
        $second = $this->createTicket($this->helpdesk);
        $this->cs->notifications()->where('data->title', 'like', $first->ticket_number.'%')->first()->markAsRead();

        $this->actingAs($this->cs)->get('/notifications/history')->assertOk()
            ->assertSee('Notifikasi Saya')
            ->assertSee('Semua (2)')
            ->assertSee('Belum dibaca (1)')
            ->assertSee($first->ticket_number)
            ->assertSee($second->ticket_number);
        $this->get('/notifications/history?filter=unread')->assertOk()
            ->assertSee($second->ticket_number)
            ->assertDontSee($first->ticket_number.' ·');

        // Helpdesk made both tickets, so its own log is empty.
        $this->flushSession();
        $this->actingAs($this->helpdesk)->get('/notifications/history')->assertOk()->assertSee('Belum ada notifikasi.');

        $this->flushSession();
        $this->actingAs($this->cs)->post('/notifications/read-all')->assertRedirect()->assertSessionHas('success');
        $this->assertCount(0, $this->cs->fresh()->unreadNotifications);
    }

    public function test_pop_ups_render_outside_the_blurred_header(): void
    {
        $this->actingAs($this->cs)->get('/dashboard')->assertOk()->assertSee('<template x-teleport="body">', false);
    }

    public function test_bell_lists_the_latest_25_and_the_log_has_the_rest(): void
    {
        foreach (range(1, 30) as $i) {
            $this->cs->notify(new InAppNotification("Tiket {$i}", 'Uji', '/dashboard'));
        }

        $bell = $this->actingAs($this->cs)->getJson('/notifications')->json();
        $this->assertSame(30, $bell['unread']);
        $this->assertCount(25, $bell['items']);
        $this->get('/notifications/history')->assertSee('Semua (30)');
    }

    public function test_a_notification_cannot_be_opened_by_someone_else(): void
    {
        $this->createTicket($this->helpdesk);
        $id = $this->cs->notifications()->value('id');

        $this->actingAs($this->otherHelpdesk)->get("/notifications/{$id}/open")->assertNotFound();
        $this->assertCount(1, $this->cs->fresh()->unreadNotifications);
    }
}
