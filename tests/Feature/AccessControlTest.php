<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_are_restricted_by_role(): void
    {
        $matrix = [
            '/users' => [Role::SPV],
            '/settings' => [Role::SPV],
            '/reports' => [Role::SPV, Role::HELPDESK, Role::CS],
            '/reports/export' => [Role::SPV, Role::HELPDESK, Role::CS],
            '/telegram-logs' => [Role::SPV, Role::HELPDESK],
            '/tickets/create' => [Role::SPV, Role::HELPDESK, Role::CS],
            '/dashboard' => Role::cases(),
            '/profile' => Role::cases(),
        ];

        $users = collect(Role::cases())->mapWithKeys(fn (Role $role) => [$role->value => $this->userWithRole($role)]);

        foreach ($matrix as $url => $allowed) {
            foreach (Role::cases() as $role) {
                $expected = in_array($role, $allowed, true) ? 200 : 403;
                $this->actingAs($users[$role->value])->get($url)
                    ->assertStatus($expected, "{$role->value} on {$url} should be {$expected}");
            }
        }
    }

    public function test_supervisor_manages_users_with_safeguards(): void
    {
        $primary = $this->userWithRole(Role::SPV, ['username' => User::PRIMARY_SUPERVISOR]);
        $this->actingAs($primary);

        $this->post('/users', [
            'username' => ' Andi ', 'name' => 'Andi', 'role' => 'CS', 'password' => 'andi123',
        ])->assertRedirect('/users');
        $andi = User::query()->where('username', 'andi')->firstOrFail();
        $this->assertSame(Role::CS, $andi->role);

        $this->post('/users', ['username' => 'andi', 'name' => 'Duplicate', 'role' => 'CS', 'password' => 'andi123'])
            ->assertSessionHasErrors('username');

        $this->put("/users/{$andi->id}", ['name' => 'Andi P', 'role' => 'HELPDESK', 'active' => '0', 'password' => ''])
            ->assertSessionHasNoErrors();
        $andi->refresh();
        $this->assertSame(Role::HELPDESK, $andi->role);
        $this->assertFalse($andi->active);

        $this->put("/users/{$primary->id}", ['name' => $primary->name, 'role' => 'CS', 'active' => '1'])
            ->assertSessionHasErrors('role');
        $this->delete("/users/{$primary->id}")->assertSessionHasErrors('user');
        $this->assertModelExists($primary);

        $this->delete("/users/{$andi->id}")->assertRedirect('/users');
        $this->assertModelMissing($andi);
    }

    public function test_supervisor_cannot_demote_own_account(): void
    {
        $spv = $this->userWithRole(Role::SPV);

        $this->actingAs($spv)->put("/users/{$spv->id}", ['name' => $spv->name, 'role' => 'SPV', 'active' => '0'])
            ->assertSessionHasErrors('role');
        $this->delete("/users/{$spv->id}")->assertSessionHasErrors('user');
        $this->assertTrue($spv->fresh()->active);
    }

    public function test_settings_are_validated_and_saved(): void
    {
        $this->actingAs($this->userWithRole(Role::SPV));

        $this->put('/settings', ['bot_token' => 'not a token', 'chat_id' => 'abc'])
            ->assertSessionHasErrors(['bot_token', 'chat_id']);

        $this->put('/settings', [
            'bot_token' => '123456:ABC-def_1', 'chat_id' => '-1001234567890', 'cs_phone' => '0812-3456',
            'notify_on_new_ticket' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame('123456:ABC-def_1', Setting::read('bot_token'));
        $this->assertSame('08123456', Setting::read('cs_phone'));
        $this->assertSame('1', Setting::read('notify_on_new_ticket'));
        $this->assertSame('0', Setting::read('notify_on_status_change'));
    }

    public function test_technician_only_sees_own_repair_tickets(): void
    {
        $tech = $this->userWithRole(Role::TEKNISI);
        $other = $this->userWithRole(Role::TEKNISI);
        $mine = Ticket::factory()->status(TicketStatus::PERBAIKAN)->assignedTo($tech)->create();
        $theirs = Ticket::factory()->status(TicketStatus::PERBAIKAN)->assignedTo($other)->create();
        $queued = Ticket::factory()->create();

        $this->actingAs($tech)->get('/dashboard?status=ANTRIAN_HELPDESK')
            ->assertOk()
            ->assertSee($mine->ticket_number)
            ->assertDontSee($theirs->ticket_number)
            ->assertDontSee($queued->ticket_number);

        $this->get("/tickets/{$mine->ticket_number}")->assertOk();
        $this->get("/tickets/{$theirs->ticket_number}")->assertForbidden();
        $this->get("/tickets/{$queued->ticket_number}")->assertForbidden();
        $this->post("/tickets/{$theirs->ticket_number}/actions/MULAI_PERBAIKAN")->assertForbidden();
    }
}
