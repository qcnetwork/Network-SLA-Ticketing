<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\TelegramLog;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TicketCustomerUpdateTest extends TestCase
{
    use RefreshDatabase;

    private User $cs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cs = $this->userWithRole(Role::CS);
    }

    private function update(User $user, Ticket $ticket, array $data)
    {
        return $this->actingAs($user)->put("/tickets/{$ticket->ticket_number}/customer", $data + [
            'customer_name' => $ticket->customer_name,
            'customer_phone' => $ticket->customer_phone,
            'customer_id' => $ticket->customer_id,
            'customer_address' => $ticket->customer_address,
        ]);
    }

    public function test_cs_adds_a_shareloc_after_the_ticket_was_made(): void
    {
        $ticket = Ticket::factory()->status(TicketStatus::PROSES_CS)->create(['customer_address' => 'Jl. Merak No. 14']);

        $this->actingAs($this->cs)->get("/tickets/{$ticket->ticket_number}")->assertOk()
            ->assertSee('Belum ada titik lokasi')->assertSee(route('tickets.customer.update', $ticket));

        $this->update($this->cs, $ticket, ['customer_address' => 'https://maps.google.com/maps?q=-6.9667%2C110.4167&z=17'])
            ->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame(-6.9667, $ticket->latitude);
        $this->assertSame(110.4167, $ticket->longitude);

        $history = $ticket->histories->last();
        $this->assertSame('UBAH_DATA_PELANGGAN', $history->action->value);
        $this->assertStringContainsString('Alamat: "Jl. Merak No. 14" → "https://maps.google.com/maps?q=-6.9667%2C110.4167&z=17"', $history->notes);
        $this->assertStringContainsString('Titik lokasi: -6.9667, 110.4167', $history->notes);

        $message = TelegramLog::query()->latest('id')->value('message');
        $this->assertStringContainsString('DATA PELANGGAN DIPERBARUI', $message);
        $this->assertStringContainsString('https://www.google.com/maps?q=-6.9667,110.4167', $message);

        $this->get("/tickets/{$ticket->ticket_number}")->assertSee('Titik Lokasi')->assertDontSee('Belum ada titik lokasi');
    }

    public function test_name_phone_and_id_can_be_corrected(): void
    {
        $ticket = Ticket::factory()->create(['customer_name' => 'Budi', 'customer_phone' => '6281111111111', 'customer_id' => 'CUST-1']);

        $this->update($this->userWithRole(Role::HELPDESK), $ticket, [
            'customer_name' => 'Budi Santoso', 'customer_phone' => '0812-2222-3333', 'customer_id' => 'PLG-0099',
        ])->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame('Budi Santoso', $ticket->customer_name);
        $this->assertSame('6281222223333', $ticket->customer_phone);
        $this->assertSame('PLG-0099', $ticket->customer_id);
        $this->assertStringContainsString('Nama: "Budi" → "Budi Santoso"', $ticket->histories->last()->notes);

        // A blank ID keeps the existing one.
        $this->update($this->cs, $ticket->fresh(), ['customer_id' => '', 'customer_name' => 'Budi S.'])->assertSessionHasNoErrors();
        $this->assertSame('PLG-0099', $ticket->fresh()->customer_id);
    }

    public function test_editing_other_fields_keeps_the_pin_without_a_new_lookup(): void
    {
        $ticket = Ticket::factory()->create([
            'customer_address' => 'https://maps.app.goo.gl/AbC', 'latitude' => -6.9, 'longitude' => 109.6,
        ]);
        Http::preventStrayRequests();

        $this->update($this->cs, $ticket, ['customer_name' => 'Nama Baru'])->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame(-6.9, $ticket->latitude);
        $this->assertStringNotContainsString('Titik lokasi', $ticket->histories->last()->notes);
    }

    public function test_replacing_a_shareloc_with_plain_text_removes_the_pin(): void
    {
        $ticket = Ticket::factory()->create(['customer_address' => '-6.9, 109.6', 'latitude' => -6.9, 'longitude' => 109.6]);

        $this->update($this->cs, $ticket, ['customer_address' => 'Jl. Mawar No. 3'])->assertSessionHasNoErrors();

        $this->assertFalse($ticket->fresh()->hasLocation());
        $this->assertStringContainsString('Titik lokasi dihapus', $ticket->fresh()->histories->last()->notes);
    }

    public function test_unchanged_or_invalid_data_is_rejected(): void
    {
        $ticket = Ticket::factory()->create();

        $this->update($this->cs, $ticket, [])->assertSessionHasErrors('customer');
        $this->update($this->cs, $ticket, ['customer_name' => '', 'customer_phone' => 'abc'])
            ->assertSessionHasErrors(['customer_name', 'customer_phone']);
        $this->assertCount(0, $ticket->histories);
    }

    public function test_technicians_and_closed_tickets_cannot_be_edited(): void
    {
        $technician = $this->userWithRole(Role::TEKNISI);
        $repair = Ticket::factory()->status(TicketStatus::PERBAIKAN)->assignedTo($technician)->create();
        $this->update($technician, $repair, ['customer_name' => 'X'])->assertForbidden();
        $this->actingAs($technician)->get("/tickets/{$repair->ticket_number}")->assertOk()
            ->assertDontSee(route('tickets.customer.update', $repair));

        $closed = Ticket::factory()->status(TicketStatus::TUTUP)->create(['customer_name' => 'Lama']);
        $this->update($this->cs, $closed, ['customer_name' => 'Baru'])->assertSessionHasErrors('customer');
        $this->assertSame('Lama', $closed->fresh()->customer_name);
    }
}
