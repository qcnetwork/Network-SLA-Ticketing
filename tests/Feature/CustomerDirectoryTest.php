<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private User $cs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cs = $this->userWithRole(Role::CS);
    }

    private function createTicket(array $data = []): Ticket
    {
        $this->actingAs($this->cs)->post('/tickets', [
            'customer_name' => 'Budi', 'customer_phone' => '081234567890', 'category' => 'Lainnya',
            'priority' => 'SEDANG', 'complaint' => 'Internet mati', ...$data,
        ])->assertSessionHasNoErrors();

        return Ticket::query()->latest('id')->firstOrFail();
    }

    public function test_tickets_fill_the_directory_and_reuse_a_known_id(): void
    {
        $first = $this->createTicket(['customer_id' => 'QC-0001', 'customer_address' => 'Jl. Melati 5']);

        $customer = Customer::query()->sole();
        $this->assertSame('QC-0001', $customer->code);
        $this->assertSame('6281234567890', $customer->phone);
        $this->assertSame('Jl. Melati 5', $customer->address);

        // Same customer typed in lower case with a new number: one customer, newest details, address kept.
        $second = $this->createTicket(['customer_id' => ' qc-0001 ', 'customer_name' => 'Budi Santoso', 'customer_phone' => '0899111222']);

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame('QC-0001', $second->customer_id);
        $customer->refresh();
        $this->assertSame('Budi Santoso', $customer->name);
        $this->assertSame('62899111222', $customer->phone);
        $this->assertSame('Jl. Melati 5', $customer->address);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $customer->tickets()->pluck('id')->all());
    }

    public function test_a_customer_with_a_pin_starts_the_ticket_with_the_pin_and_keeps_it(): void
    {
        Customer::query()->create(['code' => 'QC-0100', 'name' => 'Budi', 'phone' => '6281234567890', 'address' => 'Jl. Melati 5', 'latitude' => -6.9668430, 'longitude' => 109.6624687]);
        Customer::query()->create(['code' => 'QC-0101', 'name' => 'Andi', 'phone' => '6281234567891', 'address' => 'Jl. Kenanga 9']);

        $this->actingAs($this->cs)->get('/tickets/create?customer=QC-0100')->assertSee('-6.9668430, 109.6624687');
        $this->actingAs($this->cs)->get('/tickets/create?customer=QC-0101')->assertSee('Jl. Kenanga 9');
        $this->actingAs($this->cs)->getJson('/pelanggan/cari?q=QC-010')
            ->assertJsonFragment(['code' => 'QC-0100', 'address' => '-6.9668430, 109.6624687'])
            ->assertJsonFragment(['code' => 'QC-0101', 'address' => 'Jl. Kenanga 9']);

        $ticket = $this->createTicket(['customer_id' => 'QC-0100', 'customer_address' => '-6.9668430, 109.6624687']);
        $this->assertSame([-6.966843, 109.6624687], [$ticket->latitude, $ticket->longitude]);
        $customer = Customer::query()->withCode('QC-0100')->sole();
        $this->assertSame('Jl. Melati 5', $customer->address);

        // CS types a written address over the pin: the customer's pin is not wiped.
        $this->createTicket(['customer_id' => 'QC-0100', 'customer_address' => 'Jl. Melati 5A']);
        $customer->refresh();
        $this->assertSame('Jl. Melati 5A', $customer->address);
        $this->assertSame([-6.966843, 109.6624687], [$customer->latitude, $customer->longitude]);
    }

    public function test_a_ticket_without_an_id_gets_a_new_customer(): void
    {
        $ticket = $this->createTicket();

        $this->assertMatchesRegularExpression('/^CUST-\d{6}$/', $ticket->customer_id);
        $this->assertSame($ticket->customer_id, Customer::query()->sole()->code);
    }

    public function test_without_an_id_the_same_name_and_number_is_the_same_customer(): void
    {
        $first = $this->createTicket(['customer_name' => 'Budi Santoso', 'customer_phone' => '081234567890']);

        // Same person typed differently: joins the first customer.
        $again = $this->createTicket(['customer_name' => '  budi santoso ', 'customer_phone' => '+62 812-3456-7890']);
        $this->assertSame($first->customer_id, $again->customer_id);

        // Same name on another number, or another name on the same number: someone else.
        $otherNumber = $this->createTicket(['customer_name' => 'Budi Santoso', 'customer_phone' => '089900001111']);
        $otherName = $this->createTicket(['customer_name' => 'Budi', 'customer_phone' => '081234567890']);
        $this->assertNotSame($first->customer_id, $otherNumber->customer_id);
        $this->assertNotSame($first->customer_id, $otherName->customer_id);
        $this->assertSame(3, Customer::query()->count());
    }

    public function test_editing_the_customer_on_a_ticket_updates_the_directory(): void
    {
        $ticket = $this->createTicket(['customer_id' => 'QC-0002']);

        $this->actingAs($this->cs)->put("/tickets/{$ticket->ticket_number}/customer", [
            'customer_name' => 'Budi', 'customer_phone' => '081234567890', 'customer_id' => 'QC-0002', 'customer_address' => 'Perum Indah B3',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Perum Indah B3', Customer::query()->withCode('QC-0002')->value('address'));
    }

    public function test_search_finds_customers_and_flags_an_open_ticket(): void
    {
        $open = $this->createTicket(['customer_id' => 'QC-0003', 'customer_name' => 'Siti Aminah', 'customer_phone' => '085700001111']);
        $closed = $this->createTicket(['customer_id' => 'QC-0004', 'customer_name' => 'Siti Rahma', 'customer_phone' => '085700002222']);
        $closed->update(['status' => TicketStatus::TUTUP]);

        $results = $this->actingAs($this->cs)->getJson('/pelanggan/cari?q=siti')->assertOk()->json();
        $this->assertCount(2, $results);
        $byCode = collect($results)->keyBy('code');
        $this->assertSame($open->ticket_number, $byCode['QC-0003']['open_ticket']['number']);
        $this->assertNull($byCode['QC-0004']['open_ticket']);
        $this->assertSame(1, $byCode['QC-0004']['tickets']);

        // A local 08xx number finds the 628xx it is stored as; an exact ID comes first.
        $this->assertSame('QC-0004', $this->getJson('/pelanggan/cari?q=0857-0000-2222')->json('0.code'));
        $this->assertSame('QC-0003', $this->getJson('/pelanggan/cari?q=qc-0003')->json('0.code'));
        $this->assertSame([], $this->getJson('/pelanggan/cari?q=s')->json());
    }

    public function test_customer_list_and_prefilled_ticket_form(): void
    {
        $this->createTicket(['customer_id' => 'QC-0005', 'customer_name' => 'Andi Wijaya', 'customer_address' => 'Jl. Kenanga 9']);
        $this->createTicket(['customer_id' => 'QC-0006', 'customer_name' => 'Rina']);

        $this->actingAs($this->cs)->get('/pelanggan?q=andi')->assertOk()
            ->assertSee('Andi Wijaya')
            ->assertDontSee('Rina');

        $this->get('/tickets/create?customer=qc-0005')->assertOk()
            ->assertSee('value="Andi Wijaya"', false)
            ->assertSee('value="QC-0005"', false)
            ->assertSee('value="Jl. Kenanga 9"', false);
    }

    public function test_technicians_cannot_browse_customers(): void
    {
        $technician = $this->userWithRole(Role::TEKNISI);

        $this->actingAs($technician)->get('/pelanggan')->assertForbidden();
        $this->getJson('/pelanggan/cari?q=budi')->assertForbidden();
    }

    public function test_migration_builds_one_customer_per_id_from_existing_tickets(): void
    {
        Ticket::factory()->create(['customer_id' => 'OLD-1', 'customer_name' => 'Lama', 'customer_address' => 'Alamat lama', 'created_at' => '2026-09-01 08:00']);
        Ticket::factory()->create(['customer_id' => 'old-1 ', 'customer_name' => 'Baru', 'customer_address' => 'Alamat baru', 'created_at' => '2026-10-01 08:00']);
        Ticket::factory()->create(['customer_id' => 'OLD-2', 'customer_name' => 'Lain', 'created_at' => '2026-10-02 08:00']);
        Ticket::factory()->create(['customer_id' => '', 'created_at' => '2026-10-03 08:00']);
        Customer::query()->delete();

        $migration = require database_path('migrations/2026_11_01_120000_create_customers_table.php');
        (fn () => $this->fillFromTickets())->call($migration);

        $this->assertSame(2, Customer::query()->count());
        $old = Customer::query()->withCode('OLD-1')->sole();
        // The newest ticket's details and spelling, the oldest ticket's date.
        $this->assertSame('old-1', $old->code);
        $this->assertSame('Baru', $old->name);
        $this->assertSame('Alamat baru', $old->address);
        $this->assertSame('2026-09-01', $old->created_at->toDateString());
    }
}
