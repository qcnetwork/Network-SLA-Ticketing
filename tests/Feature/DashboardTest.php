<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Http\Controllers\DashboardController;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_tickets_closed_longer_than_the_visible_window_are_hidden_but_searchable(): void
    {
        $days = Ticket::CLOSED_VISIBLE_DAYS;
        $open = Ticket::factory()->create(['updated_at' => now()->subYear()]);
        $recentClosed = Ticket::factory()->status(TicketStatus::TUTUP)->create(['closed_at' => now()->subDays($days)->addHour()]);
        $staleClosed = Ticket::factory()->status(TicketStatus::TUTUP)->create(['closed_at' => now()->subDays($days)->subHour()]);

        $this->actingAs($this->userWithRole(Role::CS));
        foreach (['/dashboard', '/dashboard?view=table&status=TUTUP'] as $url) {
            $this->get($url)->assertOk()
                ->assertSee($recentClosed->ticket_number)
                ->assertDontSee($staleClosed->ticket_number);
        }
        $this->get('/dashboard')->assertSee($open->ticket_number)->assertSee('2 tiket ditampilkan');

        // Hidden from the lists only: the record is kept and a search still reaches it.
        $this->assertModelExists($staleClosed);
        $this->get('/dashboard?search='.$staleClosed->ticket_number)->assertOk()->assertSee($staleClosed->ticket_number);
        $this->get("/tickets/{$staleClosed->ticket_number}")->assertOk();
    }

    public function test_each_kanban_column_is_paged_on_its_own(): void
    {
        $perPage = DashboardController::COLUMN_PER_PAGE;
        $queued = Ticket::factory()->count($perPage + 1)->sequence(fn ($sequence) => ['updated_at' => now()->subMinutes($sequence->index)])->create();
        $repairing = Ticket::factory()->status(TicketStatus::PERBAIKAN)->create();

        $this->actingAs($this->userWithRole(Role::CS))->get('/dashboard')->assertOk()
            ->assertSee($queued->first()->ticket_number)
            ->assertDontSee($queued->last()->ticket_number)
            ->assertSee('page_antrian_helpdesk=2', false)
            ->assertSee($repairing->ticket_number);

        $this->get('/dashboard?page_antrian_helpdesk=2')->assertOk()
            ->assertSee($queued->last()->ticket_number)
            ->assertDontSee($queued->first()->ticket_number)
            ->assertSee($repairing->ticket_number);
    }
}
