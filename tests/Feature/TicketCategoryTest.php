<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketCategoryTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = $this->userWithRole(Role::SPV);
    }

    public function test_the_former_categories_are_seeded(): void
    {
        $this->assertSame([
            'LOS Merah / Kabel Putus',
            'Internet Mati Total (LOS Hijau tapi RTO)',
            'Internet Lambat / Ping Tinggi',
            'WiFi Sering Putus / Jangkauan Sinyal',
            'Router / Modem Rusak (Mati Total)',
            'Lainnya',
        ], TicketCategory::activeNames());
    }

    public function test_only_supervisors_manage_categories(): void
    {
        foreach ([Role::CS, Role::HELPDESK, Role::TEKNISI] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->get('/categories')->assertForbidden();
            $this->actingAs($user)->post('/categories', ['name' => 'Baru'])->assertForbidden();
        }

        $this->actingAs($this->supervisor)->get('/categories')->assertOk()->assertSee('LOS Merah / Kabel Putus');
    }

    public function test_new_category_is_offered_when_creating_a_ticket(): void
    {
        $this->actingAs($this->supervisor)->post('/categories', ['name' => ' Pindah Lokasi '])->assertSessionHasNoErrors();
        $this->post('/categories', ['name' => 'Pindah Lokasi'])->assertSessionHasErrors('name');

        $this->assertSame('Pindah Lokasi', collect(TicketCategory::activeNames())->last());

        $cs = $this->userWithRole(Role::CS);
        $this->actingAs($cs)->get('/tickets/create')->assertSee('Pindah Lokasi');
        $this->post('/tickets', [
            'customer_name' => 'Budi', 'customer_phone' => '081234567890', 'category' => 'Pindah Lokasi',
            'priority' => 'SEDANG', 'complaint' => 'Mau pindah rumah',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Pindah Lokasi', Ticket::query()->sole()->category);
    }

    public function test_renaming_a_category_renames_it_on_its_tickets(): void
    {
        $category = TicketCategory::query()->where('name', 'Lainnya')->sole();
        $ticket = Ticket::factory()->create(['category' => 'Lainnya']);

        $this->actingAs($this->supervisor)->put("/categories/{$category->id}", [
            'name' => 'Lain-lain', 'sort_order' => 5, 'active' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Lain-lain', $ticket->fresh()->category);
        $this->assertSame('Lain-lain', TicketCategory::activeNames()[0]);
    }

    public function test_inactive_category_is_no_longer_offered_but_stays_on_old_tickets(): void
    {
        $category = TicketCategory::query()->where('name', 'Lainnya')->sole();
        $ticket = Ticket::factory()->create(['category' => 'Lainnya']);

        $this->actingAs($this->supervisor)->put("/categories/{$category->id}", [
            'name' => 'Lainnya', 'sort_order' => $category->sort_order, 'active' => '0',
        ])->assertSessionHasNoErrors();

        $this->assertNotContains('Lainnya', TicketCategory::activeNames());
        $this->actingAs($this->userWithRole(Role::CS))->post('/tickets', [
            'customer_name' => 'Budi', 'customer_phone' => '081234567890', 'category' => 'Lainnya',
            'priority' => 'SEDANG', 'complaint' => 'Internet mati',
        ])->assertSessionHasErrors('category');

        $this->actingAs($this->userWithRole(Role::HELPDESK))->get("/tickets/{$ticket->ticket_number}")->assertOk()->assertSee('Lainnya');
    }

    public function test_only_unused_categories_can_be_deleted(): void
    {
        $used = TicketCategory::query()->where('name', 'Lainnya')->sole();
        $unused = TicketCategory::query()->where('name', 'Router / Modem Rusak (Mati Total)')->sole();
        Ticket::factory()->create(['category' => 'Lainnya']);

        $this->actingAs($this->supervisor)->delete("/categories/{$used->id}")->assertSessionHasErrors('category');
        $this->assertModelExists($used);

        $this->delete("/categories/{$unused->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($unused);
    }
}
