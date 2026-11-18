<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TicketKind;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_sums_up_complaints_and_installations_and_links_the_menus(): void
    {
        Ticket::factory()->status(TicketStatus::ANTRIAN_HELPDESK)->create();
        Ticket::factory()->status(TicketStatus::CEK_ULANG)->create();
        Ticket::factory()->status(TicketStatus::TUTUP)->create(['closed_at' => now()]);
        Ticket::factory()->status(TicketStatus::PSB_CEK_LOKASI)->create(['kind' => TicketKind::PSB]);
        Ticket::factory()->status(TicketStatus::PSB_SELESAI)->create(['kind' => TicketKind::PSB, 'closed_at' => now()]);

        $response = $this->actingAs($this->userWithRole(Role::HELPDESK))->get('/dashboard')->assertOk()
            ->assertSee('Perlu Ditangani')->assertSee('Tiket Komplain')->assertSee(route('complaints.index'))
            ->assertSee(route('installations.index'))
            ->assertSee('Log Notifikasi')->assertSee('dashboardLive', false)->assertSee('data-live="attention"', false);

        $stats = $response->viewData('stats');
        $this->assertSame(2, $stats['complaintsOpen']);
        $this->assertSame(1, $stats['complaintsClosedToday']);
        $this->assertSame(1, $stats['installationsOpen']);
        $this->assertSame(1, $stats['installationsActiveThisMonth']);

        $attention = $response->viewData('attention')->mapWithKeys(fn ($item) => [$item['status']->value => $item['count']]);
        $this->assertSame(1, $attention['ANTRIAN_HELPDESK']);
        $this->assertSame(1, $attention['CEK_ULANG']);
        $this->assertSame(1, $attention['PSB_CEK_LOKASI']);
        // The board itself lives under Tiket Komplain now.
        $this->assertArrayNotHasKey('columns', $response->original->getData());
    }

    public function test_technician_sees_their_own_visits(): void
    {
        $technician = $this->userWithRole(Role::TEKNISI);
        $mine = Ticket::factory()->status(TicketStatus::PERBAIKAN)->assignedTo($technician)->create();
        $survey = Ticket::factory()->status(TicketStatus::PSB_SURVEY)->assignedTo($technician)->create(['kind' => TicketKind::PSB]);
        $other = Ticket::factory()->status(TicketStatus::PERBAIKAN)->create();

        $this->actingAs($technician)->get('/dashboard')->assertOk()
            ->assertSee('Tugas Saya')->assertSee($mine->ticket_number)->assertSee($survey->ticket_number)
            ->assertDontSee($other->ticket_number);
    }
}
