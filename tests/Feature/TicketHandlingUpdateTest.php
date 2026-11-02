<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketHandlingUpdateTest extends TestCase
{
    use RefreshDatabase;

    private User $helpdesk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->helpdesk = $this->userWithRole(Role::HELPDESK);
    }

    private function update(User $user, Ticket $ticket, array $data)
    {
        return $this->actingAs($user)->put("/tickets/{$ticket->ticket_number}/handling", $data + [
            'modem' => $ticket->modem,
            'remote_resolution_notes' => $ticket->remote_resolution_notes,
            'technician_notes' => $ticket->technician_notes,
        ]);
    }

    public function test_helpdesk_fills_in_a_forgotten_modem_on_a_closed_ticket(): void
    {
        $ticket = Ticket::factory()->status(TicketStatus::TUTUP)->create(['modem' => null, 'remote_resolution_notes' => 'Reboot ONT']);

        $this->actingAs($this->helpdesk)->get("/tickets/{$ticket->ticket_number}")->assertOk()
            ->assertSee(route('tickets.handling.update', $ticket));

        $this->update($this->helpdesk, $ticket, ['modem' => ' ZTE F609 '])->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame('ZTE F609', $ticket->modem);
        $this->assertSame('Reboot ONT', $ticket->remote_resolution_notes);
        $this->assertSame(TicketStatus::TUTUP, $ticket->status);

        $history = $ticket->histories->last();
        $this->assertSame('UBAH_PENANGANAN', $history->action->value);
        $this->assertSame("Data penanganan diubah oleh {$this->helpdesk->name}:\nModem / ONT: \"-\" → \"ZTE F609\"", $history->notes);
    }

    public function test_existing_notes_can_be_corrected_but_not_added_or_cleared(): void
    {
        $ticket = Ticket::factory()->status(TicketStatus::MENUNGGU_KONFIRMASI)->create([
            'modem' => 'ZTE F609', 'technician_notes' => 'Ganti konektor', 'remote_resolution_notes' => null,
        ]);

        $this->update($this->helpdesk, $ticket, ['technician_notes' => ''])->assertSessionHasErrors('technician_notes');

        $this->update($this->helpdesk, $ticket, ['technician_notes' => 'Ganti konektor dan patchcord', 'remote_resolution_notes' => 'Reboot'])
            ->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame('Ganti konektor dan patchcord', $ticket->technician_notes);
        $this->assertNull($ticket->remote_resolution_notes);
        $this->assertCount(1, $ticket->histories);
    }

    public function test_unchanged_data_is_rejected(): void
    {
        $ticket = Ticket::factory()->create(['modem' => 'ZTE F609']);

        $this->update($this->helpdesk, $ticket, [])->assertSessionHasErrors('handling');
        $this->assertCount(0, $ticket->histories);
    }

    public function test_cs_and_technicians_cannot_edit_handling(): void
    {
        $ticket = Ticket::factory()->status(TicketStatus::TUTUP)->create(['modem' => null]);
        $cs = $this->userWithRole(Role::CS);
        $this->update($cs, $ticket, ['modem' => 'ZTE F609'])->assertForbidden();
        $this->actingAs($cs)->get("/tickets/{$ticket->ticket_number}")->assertOk()
            ->assertDontSee(route('tickets.handling.update', $ticket));

        $technician = $this->userWithRole(Role::TEKNISI);
        $repair = Ticket::factory()->status(TicketStatus::PERBAIKAN)->assignedTo($technician)->create(['modem' => null]);
        $this->update($technician, $repair, ['modem' => 'ZTE F609'])->assertForbidden();
        $this->assertNull($repair->fresh()->modem);
    }
}
