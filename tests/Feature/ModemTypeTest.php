<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\ModemType;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModemTypeTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = $this->userWithRole(Role::SPV);
    }

    public function test_the_former_suggestions_are_seeded(): void
    {
        $this->assertSame('ZTE F609', ModemType::activeNames()[0]);
        $this->assertContains('Fiberhome HG6243C', ModemType::activeNames());
        $this->assertCount(8, ModemType::activeNames());
    }

    public function test_only_supervisors_manage_modem_types(): void
    {
        foreach ([Role::CS, Role::HELPDESK, Role::TEKNISI] as $role) {
            $this->actingAs($this->userWithRole($role))->get('/modems')->assertForbidden();
        }

        $this->actingAs($this->supervisor)->get('/modems')->assertOk()->assertSee('Jenis Modem / ONT')->assertSee('ZTE F670L');
    }

    public function test_added_modem_is_suggested_and_inactive_one_is_not(): void
    {
        $this->actingAs($this->supervisor)->post('/modems', ['name' => 'Nokia G-140W'])->assertSessionHasNoErrors();
        $this->post('/modems', ['name' => 'Nokia G-140W'])->assertSessionHasErrors('name');

        $zte = ModemType::query()->where('name', 'ZTE F609')->sole();
        $this->put("/modems/{$zte->id}", ['name' => 'ZTE F609', 'sort_order' => $zte->sort_order, 'active' => '0'])->assertSessionHasNoErrors();

        $helpdesk = $this->userWithRole(Role::HELPDESK);
        $ticket = Ticket::factory()->status(TicketStatus::HELPDESK_CEK)->create(['assigned_helpdesk_id' => $helpdesk->id]);
        $this->actingAs($helpdesk)->get("/tickets/{$ticket->ticket_number}")->assertOk()
            ->assertSee('<option value="Nokia G-140W">', false)
            ->assertDontSee('<option value="ZTE F609">', false);

        // A model that is not on the list can still be typed in.
        $this->post("/tickets/{$ticket->ticket_number}/actions/SELESAI_REMOTE", ['modem' => 'Merek Lain X1', 'notes' => 'Reboot'])->assertSessionHasNoErrors();
        $this->assertSame('Merek Lain X1', $ticket->fresh()->modem);
    }

    public function test_renaming_carries_tickets_and_used_types_cannot_be_deleted(): void
    {
        $type = ModemType::query()->where('name', 'ZTE F660')->sole();
        $ticket = Ticket::factory()->create(['modem' => 'ZTE F660']);

        $this->actingAs($this->supervisor)->delete("/modems/{$type->id}")->assertSessionHasErrors('option');
        $this->put("/modems/{$type->id}", ['name' => 'ZTE F660 V6', 'sort_order' => 20, 'active' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('ZTE F660 V6', $ticket->fresh()->modem);

        $unused = ModemType::query()->where('name', 'Huawei HG8245H5')->sole();
        $this->delete("/modems/{$unused->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($unused);
    }
}
