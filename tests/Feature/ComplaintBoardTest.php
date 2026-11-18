<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Http\Controllers\ComplaintBoardController;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplaintBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_tickets_closed_longer_than_the_visible_window_are_hidden_but_searchable(): void
    {
        $days = Ticket::CLOSED_VISIBLE_DAYS;
        $open = Ticket::factory()->create(['updated_at' => now()->subYear()]);
        $recentClosed = Ticket::factory()->status(TicketStatus::TUTUP)->create(['closed_at' => now()->subDays($days)->addHour()]);
        $staleClosed = Ticket::factory()->status(TicketStatus::TUTUP)->create(['closed_at' => now()->subDays($days)->subHour()]);

        $this->actingAs($this->userWithRole(Role::CS));
        foreach (['/komplain', '/komplain?view=table&status=TUTUP'] as $url) {
            $this->get($url)->assertOk()
                ->assertSee($recentClosed->ticket_number)
                ->assertDontSee($staleClosed->ticket_number);
        }
        $this->get('/komplain')->assertSee($open->ticket_number)->assertSee('2 tiket ditampilkan');

        // Hidden from the lists only: the record is kept and a search still reaches it.
        $this->assertModelExists($staleClosed);
        $this->get('/komplain?search='.$staleClosed->ticket_number)->assertOk()->assertSee($staleClosed->ticket_number);
        $this->get("/tickets/{$staleClosed->ticket_number}")->assertOk();
    }

    /**
     * @return array<string>
     */
    private function boardColumns($response): array
    {
        return array_map(fn (TicketStatus $status) => $status->value, $response->viewData('boardStatuses'));
    }

    public function test_each_user_picks_which_kanban_columns_they_see(): void
    {
        $atox = $this->userWithRole(Role::HELPDESK);
        $esa = $this->userWithRole(Role::HELPDESK);

        $this->actingAs($atox)->post('/komplain/kolom', ['visible' => ['ANTRIAN_HELPDESK', 'HELPDESK_CEK']])
            ->assertRedirect('/komplain')->assertSessionHas('success');
        $this->assertSame(['ANTRIAN_HELPDESK', 'HELPDESK_CEK'], $this->boardColumns($this->get('/komplain')));
        $this->get('/komplain')->assertSee(count(TicketStatus::complaint()) - 2 .' disembunyikan');

        // Summary counts still cover every node.
        $this->assertCount(count(TicketStatus::complaint()), $this->get('/komplain')->viewData('summaryStatuses'));

        // Someone else's board is untouched.
        $this->flushSession();
        $this->actingAs($esa);
        $this->assertCount(count(TicketStatus::complaint()), $this->boardColumns($this->get('/komplain')));
    }

    public function test_presets_show_the_role_focus_or_everything(): void
    {
        $helpdesk = $this->userWithRole(Role::HELPDESK);

        $this->actingAs($helpdesk)->post('/komplain/kolom', ['preset' => 'focus']);
        $this->assertSame(ComplaintBoardController::ROLE_FOCUS['HELPDESK'], $this->boardColumns($this->get('/komplain')));
        $this->assertNotContains('PROSES_CS', $this->boardColumns($this->get('/komplain')));

        $this->post('/komplain/kolom', ['preset' => 'all']);
        $this->assertNull($helpdesk->fresh()->hidden_board_statuses);
        $this->assertCount(count(TicketStatus::complaint()), $this->boardColumns($this->get('/komplain')));
    }

    public function test_a_hidden_column_still_shows_when_filtered_to(): void
    {
        $this->actingAs($this->userWithRole(Role::HELPDESK))->post('/komplain/kolom', ['visible' => ['HELPDESK_CEK']]);

        $this->assertSame(['PROSES_CS', 'HELPDESK_CEK'], $this->boardColumns($this->get('/komplain?status=PROSES_CS')));
    }

    public function test_at_least_one_column_stays_and_technicians_cannot_change_theirs(): void
    {
        $this->actingAs($this->userWithRole(Role::CS))->post('/komplain/kolom', ['visible' => []])->assertSessionHasErrors('visible');
        $this->post('/komplain/kolom', ['visible' => ['BUKAN_NODE']])->assertSessionHasErrors('visible.0');

        $this->flushSession();
        $this->actingAs($this->userWithRole(Role::TEKNISI))->post('/komplain/kolom', ['preset' => 'all'])->assertForbidden();
        $this->get('/komplain')->assertDontSee('Atur Kolom');
    }

    public function test_kanban_board_scrolls_from_a_bar_above_it(): void
    {
        $this->actingAs($this->userWithRole(Role::CS))->get('/komplain')->assertOk()
            ->assertSee('x-data="topScrollbar"', false)
            ->assertSee('x-ref="bar"', false)
            ->assertSee('sm:scrollbar-none', false);

        // The table view keeps its own scrolling.
        $this->get('/komplain?view=table')->assertOk()->assertDontSee('x-data="topScrollbar"', false);
    }

    public function test_each_kanban_column_is_paged_on_its_own(): void
    {
        $perPage = ComplaintBoardController::COLUMN_PER_PAGE;
        $queued = Ticket::factory()->count($perPage + 1)->sequence(fn ($sequence) => ['updated_at' => now()->subMinutes($sequence->index)])->create();
        $repairing = Ticket::factory()->status(TicketStatus::PERBAIKAN)->create();

        $this->actingAs($this->userWithRole(Role::CS))->get('/komplain')->assertOk()
            ->assertSee($queued->first()->ticket_number)
            ->assertDontSee($queued->last()->ticket_number)
            ->assertSee('page_antrian_helpdesk=2', false)
            ->assertSee($repairing->ticket_number);

        $this->get('/komplain?page_antrian_helpdesk=2')->assertOk()
            ->assertSee($queued->last()->ticket_number)
            ->assertDontSee($queued->first()->ticket_number)
            ->assertSee($repairing->ticket_number);
    }
}
