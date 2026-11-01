<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Services\ComplaintMapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ComplaintHeatmapTest extends TestCase
{
    use RefreshDatabase;

    private function ticketAt(?float $latitude, ?float $longitude, array $attributes = []): Ticket
    {
        return Ticket::factory()->create([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'created_at' => '2026-10-15 09:00:00',
            ...$attributes,
        ]);
    }

    public function test_pins_are_grouped_into_areas_busiest_first(): void
    {
        // Three complaints within a few hundred metres, one across town, one without a pin.
        $this->ticketAt(-7.25012, 112.75021, ['customer_address' => 'Jl. Melati 5, Perum Indah https://maps.app.goo.gl/abc', 'category' => 'Internet Mati / LOS']);
        $this->ticketAt(-7.25230, 112.75310, ['category' => 'Internet Mati / LOS', 'customer_address' => null]);
        $this->ticketAt(-7.25480, 112.75120, ['category' => 'Lambat', 'customer_address' => '-7.25480, 112.75120']);
        $this->ticketAt(-7.31000, 112.70000);
        $this->ticketAt(null, null);

        $map = app(ComplaintMapService::class)->build(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

        $this->assertSame(5, $map['total']);
        $this->assertSame(4, $map['located']);
        $this->assertCount(4, $map['points']);
        $this->assertCount(2, $map['hotspots']);
        $this->assertSame(3, $map['hotspots'][0]['count']);
        $this->assertSame('Internet Mati / LOS', $map['hotspots'][0]['top_category']);
        // The shareloc link is dropped from the address shown for the area.
        $this->assertSame('Jl. Melati 5, Perum Indah', $map['hotspots'][0]['address']);
        $this->assertSame(1, $map['hotspots'][1]['count']);
    }

    public function test_page_filters_by_date_and_category(): void
    {
        $inRange = $this->ticketAt(-7.25, 112.75, ['category' => 'Lambat']);
        $otherCategory = $this->ticketAt(-7.25, 112.75, ['category' => 'Internet Mati / LOS']);
        $tooOld = $this->ticketAt(-7.25, 112.75, ['category' => 'Lambat', 'created_at' => '2026-08-01 09:00:00']);

        $this->actingAs($this->userWithRole(Role::CS))
            ->get('/reports/heatmap?from=2026-10-01&to=2026-10-31&category=Lambat')
            ->assertOk()
            ->assertSee('Peta Area Komplain')
            ->assertSee('id="complaint-map"', false)
            ->assertSee($inRange->ticket_number)
            ->assertDontSee($otherCategory->ticket_number)
            ->assertDontSee($tooOld->ticket_number);

        $this->get('/reports/heatmap?from=2026-10-31&to=2026-10-01')->assertSessionHasErrors('to');
    }

    public function test_page_without_pins_shows_no_map_and_warns_about_coverage(): void
    {
        $this->ticketAt(null, null, ['created_at' => now()]);

        $this->actingAs($this->userWithRole(Role::HELPDESK))->get('/reports/heatmap')
            ->assertOk()
            ->assertDontSee('id="complaint-map"', false)
            ->assertSee('Belum ada tiket dengan titik lokasi')
            ->assertSee('Baru 0% tiket yang punya titik lokasi');
    }

    public function test_technicians_cannot_open_the_heatmap_and_the_tiles_are_allowed(): void
    {
        $this->actingAs($this->userWithRole(Role::TEKNISI))->get('/reports/heatmap')->assertForbidden();

        $this->actingAs($this->userWithRole(Role::SPV))->get('/reports/heatmap')
            ->assertOk()
            ->assertHeader('Content-Security-Policy');
        $this->assertStringContainsString('https://tile.openstreetmap.org', $this->get('/reports/heatmap')->headers->get('Content-Security-Policy'));
    }
}
