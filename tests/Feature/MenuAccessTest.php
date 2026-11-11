<?php

namespace Tests\Feature;

use App\Enums\Menu;
use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supervisor = $this->userWithRole(Role::SPV, ['username' => User::PRIMARY_SUPERVISOR]);
    }

    /** @param  list<Menu>  $menus */
    private function saveMenus(User $user, array $menus)
    {
        return $this->actingAs($this->supervisor)->put('/hak-akses', [
            'users' => [$user->id],
            'access' => [$user->id => array_map(fn (Menu $m) => $m->value, $menus)],
        ]);
    }

    public function test_only_supervisors_open_the_access_page(): void
    {
        foreach ([Role::CS, Role::HELPDESK, Role::TEKNISI, Role::GUDANG] as $role) {
            $this->actingAs($this->userWithRole($role))->get('/hak-akses')->assertForbidden();
            $this->put('/hak-akses', ['users' => [1]])->assertForbidden();
        }

        $cs = $this->userWithRole(Role::CS, ['name' => 'Siti CS']);
        $this->actingAs($this->supervisor)->get('/hak-akses')->assertOk()->assertSee('Siti CS')->assertSee('Supervisor utama selalu punya akses penuh');
    }

    public function test_a_granted_menu_opens_and_a_revoked_one_closes(): void
    {
        $cs = $this->userWithRole(Role::CS);
        $this->actingAs($cs)->get('/telegram-logs')->assertForbidden();
        $this->get('/reports')->assertOk();

        // CS keeps its archive, customers and reports, gains the Telegram feed and loses reports.
        $this->saveMenus($cs, [Menu::ARCHIVE, Menu::CUSTOMERS, Menu::TELEGRAM_LOGS])->assertRedirect('/hak-akses');

        $cs->refresh();
        $this->assertSame(['reports' => false, 'telegram_logs' => true], $cs->menu_access);
        $this->actingAs($cs)->get('/telegram-logs')->assertOk();
        $this->get('/reports')->assertForbidden();
        $this->get('/reports/export')->assertForbidden();
        $this->get('/dashboard')->assertOk()->assertSee('Feed Telegram')->assertDontSee('Laporan');
    }

    public function test_back_to_the_role_menus_clears_the_overrides(): void
    {
        $helpdesk = $this->userWithRole(Role::HELPDESK);
        $this->saveMenus($helpdesk, [Menu::ARCHIVE]);
        $this->assertNotNull($helpdesk->fresh()->menu_access);

        $this->saveMenus($helpdesk, Menu::defaultsFor(Role::HELPDESK));

        $this->assertNull($helpdesk->fresh()->menu_access);
    }

    public function test_a_user_with_every_box_unticked_loses_every_menu(): void
    {
        $helpdesk = $this->userWithRole(Role::HELPDESK);

        $this->actingAs($this->supervisor)->put('/hak-akses', ['users' => [$helpdesk->id]])->assertRedirect();

        $helpdesk->refresh();
        foreach (Menu::cases() as $menu) {
            $this->assertFalse($helpdesk->canAccess($menu), $menu->value);
        }
        $this->actingAs($helpdesk)->get('/arsip')->assertForbidden();
        $this->get('/gudang')->assertForbidden();
    }

    public function test_managing_the_warehouse_brings_seeing_it(): void
    {
        $cs = $this->userWithRole(Role::CS);

        $this->saveMenus($cs, [Menu::WAREHOUSE_MANAGE]);

        $cs->refresh();
        $this->assertTrue($cs->canAccess(Menu::WAREHOUSE));
        $this->actingAs($cs)->get('/gudang')->assertOk()->assertSee('Master Barang');
        $this->get('/gudang/barang')->assertOk();
    }

    public function test_the_primary_supervisor_cannot_be_restricted(): void
    {
        $other = $this->userWithRole(Role::SPV);
        $this->actingAs($other)->put('/hak-akses', ['users' => [$this->supervisor->id]])->assertRedirect();

        $this->assertNull($this->supervisor->fresh()->menu_access);
        $this->actingAs($this->supervisor)->get('/telegram-logs')->assertOk();
    }

    public function test_the_ticket_form_still_finds_customers_without_the_customer_menu(): void
    {
        $cs = $this->userWithRole(Role::CS);
        $this->saveMenus($cs, [Menu::ARCHIVE]);

        $this->actingAs($cs->fresh())->get('/pelanggan')->assertForbidden();
        $this->getJson('/pelanggan/cari?q=ab')->assertOk();

        $this->actingAs($this->userWithRole(Role::TEKNISI))->getJson('/pelanggan/cari?q=ab')->assertForbidden();
    }

    public function test_the_warehouse_archive_lists_only_tickets_it_may_open(): void
    {
        $gudang = $this->userWithRole(Role::GUDANG);
        $ticket = Ticket::factory()->create();
        $this->actingAs($gudang)->get('/arsip')->assertForbidden();

        $this->saveMenus($gudang, [...Menu::defaultsFor(Role::GUDANG), Menu::ARCHIVE]);

        $this->actingAs($gudang->fresh())->get('/arsip')->assertOk()->assertDontSee($ticket->ticket_number);
    }

    public function test_unknown_menus_are_rejected(): void
    {
        $cs = $this->userWithRole(Role::CS);

        $this->actingAs($this->supervisor)->put('/hak-akses', ['users' => [$cs->id], 'access' => [$cs->id => ['users']]])
            ->assertSessionHasErrors();

        $this->assertNull($cs->fresh()->menu_access);
    }
}
