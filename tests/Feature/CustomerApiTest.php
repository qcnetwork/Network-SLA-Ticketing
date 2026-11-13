<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const WRITE_TOKEN = 'wwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwww';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.customer_api.tokens' => ['short', self::TOKEN], 'services.customer_api.write_tokens' => [self::WRITE_TOKEN]]);
        Customer::query()->create(['code' => '202501058', 'name' => 'Rika Contoh', 'phone' => '6282220000001', 'address' => 'Makam Dowo', 'latitude' => -7.0519816, 'longitude' => 109.7136385]);
        Customer::query()->create(['code' => 'CUST-100001', 'name' => 'Andi Contoh', 'phone' => '', 'address' => 'Jl. Kenanga 9']);
    }

    private function api(): self
    {
        return $this->withToken(self::TOKEN);
    }

    public function test_a_missing_wrong_or_too_short_token_is_refused(): void
    {
        $this->getJson('/api/v1/customers')->assertUnauthorized();
        $this->withToken('salah')->getJson('/api/v1/customers')->assertUnauthorized();
        $this->withToken('short')->getJson('/api/v1/customers')->assertUnauthorized();

        config(['services.customer_api.tokens' => []]);
        $this->withToken(self::TOKEN)->getJson('/api/v1/customers')->assertUnauthorized();
    }

    public function test_it_lists_customers_with_address_and_pin(): void
    {
        $this->api()->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0', [
                'id' => '202501058',
                'name' => 'Rika Contoh',
                'phone' => '6282220000001',
                'address' => 'Makam Dowo',
                'latitude' => -7.0519816,
                'longitude' => 109.7136385,
                'maps_url' => 'https://www.google.com/maps?q=-7.0519816,109.7136385',
                'network' => ['olt' => null, 'odc' => null, 'odp' => null, 'odp_port' => null, 'updated_at' => null],
                'created_at' => Customer::query()->withCode('202501058')->value('created_at')->toIso8601String(),
                'updated_at' => Customer::query()->withCode('202501058')->value('updated_at')->toIso8601String(),
            ])
            ->assertJsonPath('data.1.phone', null)
            ->assertJsonPath('data.1.maps_url', null);
    }

    public function test_it_filters_by_search_location_and_change_time(): void
    {
        $this->api()->getJson('/api/v1/customers?q=rika')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', '202501058');
        $this->api()->getJson('/api/v1/customers?has_location=1')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', '202501058');
        $this->api()->getJson('/api/v1/customers?has_location=0')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', 'CUST-100001');

        $this->travel(1)->hour();
        Customer::query()->withCode('CUST-100001')->first()->update(['address' => 'Jl. Kenanga 10']);
        $this->api()->getJson('/api/v1/customers?updated_since='.urlencode(now()->subMinutes(5)->toIso8601String()))
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.address', 'Jl. Kenanga 10');

        $this->api()->getJson('/api/v1/customers?per_page=1')->assertJsonPath('meta.last_page', 2)->assertJsonCount(1, 'data');
        $this->api()->getJson('/api/v1/customers?per_page=9999')->assertUnprocessable();
    }

    public function test_it_finds_one_customer_by_id(): void
    {
        $this->api()->getJson('/api/v1/customers/cust-100001')->assertOk()->assertJsonPath('data.name', 'Andi Contoh');
        $this->api()->getJson('/api/v1/customers/TIDAK-ADA')->assertNotFound();
    }

    public function test_customers_cannot_be_created_or_deleted(): void
    {
        $this->withToken(self::WRITE_TOKEN)->postJson('/api/v1/customers', ['code' => 'X', 'name' => 'X'])->assertMethodNotAllowed();
        $this->withToken(self::WRITE_TOKEN)->deleteJson('/api/v1/customers/202501058')->assertMethodNotAllowed();
        $this->assertSame(2, Customer::query()->count());
    }

    public function test_only_a_write_token_sets_the_network_and_nothing_else_changes(): void
    {
        $this->api()->patchJson('/api/v1/customers/202501058/network', ['odp' => 'ODP-12'])->assertForbidden();
        $this->withoutToken()->patchJson('/api/v1/customers/202501058/network', ['odp' => 'ODP-12'])->assertUnauthorized();

        $this->withToken(self::WRITE_TOKEN)->patchJson('/api/v1/customers/202501058/network', [
            'olt' => 'OLT 17', 'odc' => ' ODC-3 ', 'odp' => 'ODP-12', 'odp_port' => 4,
            'name' => 'Diganti', 'address' => 'Diganti', 'latitude' => 1,
        ])->assertOk()
            ->assertJsonPath('data.network.olt', 'OLT 17')
            ->assertJsonPath('data.network.odc', 'ODC-3')
            ->assertJsonPath('data.network.odp_port', 4);

        $customer = Customer::query()->withCode('202501058')->sole();
        $this->assertSame(['Rika Contoh', 'Makam Dowo', -7.0519816], [$customer->name, $customer->address, $customer->latitude]);
        $this->assertNotNull($customer->network_updated_at);

        // Only the fields sent change; null clears one.
        $this->withToken(self::WRITE_TOKEN)->patchJson('/api/v1/customers/202501058/network', ['odp_port' => null])->assertOk();
        $customer->refresh();
        $this->assertSame(['OLT 17', 'ODP-12', null], [$customer->olt, $customer->odp, $customer->odp_port]);

        // A write token also reads, and the network can be filtered on.
        $this->withToken(self::WRITE_TOKEN)->getJson('/api/v1/customers?odp=ODP-12')->assertJsonPath('meta.total', 1);
        $this->withToken(self::WRITE_TOKEN)->patchJson('/api/v1/customers/TIDAK-ADA/network', ['odp' => 'X'])->assertNotFound();
        $this->withToken(self::WRITE_TOKEN)->patchJson('/api/v1/customers/202501058/network', ['odp_port' => 'abc'])->assertUnprocessable();

        $this->actingAs($this->userWithRole(Role::CS))->get('/pelanggan')->assertSee('OLT 17 / ODC-3 / ODP-12');
    }

    public function test_many_customers_are_set_at_once(): void
    {
        $this->withToken(self::WRITE_TOKEN)->patchJson('/api/v1/customers/network', ['customers' => [
            ['id' => '202501058', 'olt' => 'OLT 17', 'odp' => 'ODP-12', 'odp_port' => 1],
            ['id' => 'cust-100001', 'olt' => 'OLT 17', 'odp' => 'ODP-12', 'odp_port' => 2],
            ['id' => 'TIDAK-ADA', 'odp' => 'ODP-12'],
        ]])->assertOk()->assertExactJson(['updated' => 2, 'not_found' => ['TIDAK-ADA']]);

        $this->assertSame(2, Customer::query()->where('odp', 'ODP-12')->count());
        $this->assertSame(2, Customer::query()->withCode('CUST-100001')->value('odp_port'));
        $this->api()->patchJson('/api/v1/customers/network', ['customers' => [['id' => '202501058', 'odp' => 'X']]])->assertForbidden();
    }
}
