<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Support\GeoLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GeoLocationTest extends TestCase
{
    use RefreshDatabase;

    public static function shareLinks(): array
    {
        return [
            'place link pin' => ['https://www.google.com/maps/place/Toko+ABC/@-6.8901234,109.6000000,17z/data=!3m1!4b1!4m6!3m5!1s0x0:0x0!8m2!3d-6.8912345!4d109.6123456', [-6.8912345, 109.6123456]],
            'map view' => ['https://www.google.com/maps/@-7.0051234,110.4381234,15z', [-7.0051234, 110.4381234]],
            'query link' => ['https://maps.google.com/?q=-6.2088,106.8456', [-6.2088, 106.8456]],
            'whatsapp share' => ['https://maps.google.com/maps?q=-6.9667%2C110.4167&z=17&hl=id', [-6.9667, 110.4167]],
            'plain coordinates' => ['-6.208800, 106.845600', [-6.2088, 106.8456]],
            'coordinates in text' => ['Rumah cat hijau, titik -7.12345,110.98765 dekat masjid', [-7.12345, 110.98765]],
        ];
    }

    #[DataProvider('shareLinks')]
    public function test_reads_the_pin_from_share_links_and_coordinates(string $text, array $expected): void
    {
        Http::preventStrayRequests();

        $this->assertEqualsWithDelta($expected, GeoLocation::fromText($text), 0.0000001);
    }

    public function test_plain_addresses_have_no_pin(): void
    {
        Http::preventStrayRequests();

        $this->assertNull(GeoLocation::fromText('Jl. Merak No. 14, RT 02/05, Kebayoran Baru'));
        $this->assertNull(GeoLocation::fromText('Blok C2 No. 8, 12.5 km dari kota'));
        $this->assertNull(GeoLocation::fromText(''));
        $this->assertNull(GeoLocation::fromText('https://www.google.com/maps/@95.1234,200.1234,15z'));
    }

    public function test_short_links_are_followed_to_the_maps_url(): void
    {
        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, [
                'Location' => 'https://www.google.com/maps/place/Rumah/@-6.9,109.6,17z/data=!4m6!3m5!8m2!3d-6.9011111!4d109.6022222',
            ]),
        ]);

        $this->assertEqualsWithDelta([-6.9011111, 109.6022222], GeoLocation::fromText('https://maps.app.goo.gl/AbCdEf123'), 0.0000001);
        Http::assertSentCount(1);
    }

    public function test_links_outside_google_short_links_are_never_fetched(): void
    {
        Http::fake();

        $this->assertNull(GeoLocation::fromText('http://192.168.1.1/admin'));
        $this->assertNull(GeoLocation::fromText('https://example.com/lokasi'));
        Http::assertNothingSent();
    }

    public function test_a_failed_short_link_still_creates_the_ticket_without_a_pin(): void
    {
        Http::fake(['maps.app.goo.gl/*' => Http::response('', 404)]);

        $this->actingAs($this->userWithRole(Role::CS))->post('/tickets', [
            'customer_name' => 'Budi', 'customer_phone' => '081234567890', 'category' => 'Lainnya',
            'priority' => 'SEDANG', 'complaint' => 'Internet mati', 'customer_address' => 'https://maps.app.goo.gl/rusak',
        ])->assertSessionHasNoErrors();

        $this->assertFalse(Ticket::query()->sole()->hasLocation());
    }

    public function test_ticket_shows_the_pin_from_its_shareloc(): void
    {
        $cs = $this->userWithRole(Role::CS);
        $this->actingAs($cs)->post('/tickets', [
            'customer_name' => 'Budi', 'customer_phone' => '081234567890', 'category' => 'Lainnya',
            'priority' => 'SEDANG', 'complaint' => 'Internet mati',
            'customer_address' => 'https://maps.google.com/maps?q=-6.9667%2C110.4167&z=17',
        ])->assertSessionHasNoErrors();

        $ticket = Ticket::query()->sole();
        $this->assertSame(-6.9667, $ticket->latitude);
        $this->assertSame(110.4167, $ticket->longitude);

        $this->get("/tickets/{$ticket->ticket_number}")->assertOk()
            ->assertSee('Titik Lokasi')
            ->assertSee('-6.9667, 110.4167')
            ->assertSee('https://www.google.com/maps?q=-6.9667,110.4167', false)
            ->assertSee('maps.google.com/maps?q=-6.9667,110.4167&amp;z=16&amp;output=embed', false);
    }

    public function test_ticket_without_a_pin_has_no_map(): void
    {
        $ticket = Ticket::factory()->create(['customer_address' => 'Jl. Merak No. 14']);

        $this->actingAs($this->userWithRole(Role::CS))->get("/tickets/{$ticket->ticket_number}")
            ->assertOk()->assertSee('Jl. Merak No. 14')->assertDontSee('Titik Lokasi');
    }
}
