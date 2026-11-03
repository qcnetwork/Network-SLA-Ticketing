<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Item;
use App\Models\SerialUnit;
use App\Models\Stock;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerialInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $warehouse;

    private User $technician;

    private Item $modem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = $this->userWithRole(Role::GUDANG);
        $this->technician = $this->userWithRole(Role::TEKNISI, ['name' => 'Tim Rasyid']);
        $this->modem = Item::query()->create(['code' => 'MDM031', 'name' => 'FIBERHOME HG6145D2', 'tracks_serial' => true]);
    }

    private function quantity(Warehouse $warehouse): int
    {
        return (int) Stock::query()->where('warehouse_id', $warehouse->id)->where('item_id', $this->modem->id)->value('quantity');
    }

    private function receive(array $serials)
    {
        return $this->actingAs($this->warehouse)->post('/gudang/masuk-sn', ['item' => 'MDM031', 'serials' => $serials, 'notes' => 'Paket 16 Juli']);
    }

    public function test_new_modems_are_scanned_in_by_sn_and_mac(): void
    {
        $this->receive([
            ['sn' => ' fhttc1fa89b6 ', 'mac' => '28:6D:DA:0C:D4:60'],
            ['sn' => 'FHTTC1FA89B7', 'mac' => ''],
        ])->assertSessionHasNoErrors()->assertSessionHas('success', '2 unit MDM031 masuk Gudang Utama');

        $unit = SerialUnit::query()->where('serial_number', 'FHTTC1FA89B6')->sole();
        $this->assertSame('286DDA0CD460', $unit->mac);
        $this->assertSame(Warehouse::main()->id, $unit->warehouse_id);
        $this->assertSame(2, $this->quantity(Warehouse::main()));

        // Each mistake is rejected as a whole, so nothing half-registers.
        $this->receive([['sn' => 'FHTTC1FA89B6']])->assertSessionHasErrors('serials');
        $this->receive([['sn' => 'NEW1'], ['sn' => 'new1']])->assertSessionHasErrors('serials');
        $this->receive([['sn' => 'NEW2', 'mac' => '12:34']])->assertSessionHasErrors('serials');
        $this->post('/gudang/masuk', ['item' => 'MDM031', 'quantity' => 5])->assertSessionHasErrors('item');
        $this->assertSame(2, SerialUnit::query()->count());

        $this->getJson('/gudang/cek-sn?new=1&sn=fhttc1fa89b6')->assertStatus(422)->assertJsonPath('message', 'SN FHTTC1FA89B6 sudah terdaftar (MDM031, Gudang Utama)');
        $this->getJson('/gudang/cek-sn?new=1&sn=BARU123')->assertOk();
    }

    public function test_scanned_modems_go_to_a_technician_and_come_back(): void
    {
        $this->receive([['sn' => 'SN-A'], ['sn' => 'SN-B'], ['sn' => 'SN-C']]);
        $main = Warehouse::main();

        $this->getJson("/gudang/cek-sn?warehouse={$main->id}&sn=sn-a")->assertOk()->assertJsonPath('item', 'MDM031 · FIBERHOME HG6145D2');
        $this->post('/gudang/kirim-sn', ['technician_id' => $this->technician->id, 'serials' => [['sn' => 'SN-A'], ['sn' => 'SN-B']]])
            ->assertSessionHasNoErrors();

        $own = Warehouse::of($this->technician);
        $this->assertSame(1, $this->quantity($main));
        $this->assertSame(2, $this->quantity($own));
        $this->getJson("/gudang/cek-sn?warehouse={$main->id}&sn=SN-A")->assertStatus(422)
            ->assertJsonPath('message', 'SN SN-A tidak ada di gudang ini (posisi: Gudang Tim Rasyid)');
        // A unit already out with a technician cannot be sent again.
        $this->post('/gudang/kirim-sn', ['technician_id' => $this->technician->id, 'serials' => [['sn' => 'SN-A']]])
            ->assertSessionHasErrors('serials');

        $this->post("/gudang/{$own->id}/retur-sn", ['serials' => [['sn' => 'SN-B']], 'notes' => 'error'])->assertSessionHasNoErrors();
        $this->assertSame($main->id, SerialUnit::query()->where('serial_number', 'SN-B')->value('warehouse_id'));
        $this->assertSame(1, $this->quantity($own));

        $this->get('/gudang?q=SN-A')->assertOk()->assertSee('Posisi SN')->assertSee('Gudang Tim Rasyid');
    }

    public function test_resume_installs_a_modem_by_its_sn(): void
    {
        $this->receive([['sn' => 'SN-A', 'mac' => '286DDA0CD460'], ['sn' => 'SN-B'], ['sn' => 'SN-OTHER']]);
        $this->post('/gudang/kirim-sn', ['technician_id' => $this->technician->id, 'serials' => [['sn' => 'SN-A'], ['sn' => 'SN-B']]]);
        $ticket = Ticket::factory()->status(TicketStatus::PERBAIKAN)->assignedTo($this->technician)->create(['repair_started_at' => now()]);
        $url = "/tickets/{$ticket->ticket_number}/actions/LAPOR_PERBAIKAN_SELESAI";
        $line = ['technician_id' => $this->technician->id, 'code' => 'MDM031'];

        $this->actingAs($this->technician)->get("/tickets/{$ticket->ticket_number}")->assertOk()->assertDontSee('SN-A');
        $units = "/tickets/{$ticket->ticket_number}/material/sn?technician={$this->technician->id}";
        $this->getJson($units)->assertOk()->assertExactJson(['MDM031' => [['sn' => 'SN-A', 'mac' => '28:6D:DA:0C:D4:60'], ['sn' => 'SN-B', 'mac' => null]]]);
        $this->getJson("/tickets/{$ticket->ticket_number}/material/sn?technician={$this->warehouse->id}")->assertNotFound();
        $this->actingAs($this->userWithRole(Role::TEKNISI))->getJson($units)->assertForbidden();
        $this->actingAs($this->technician);

        $this->post($url, ['notes' => 'Ganti modem', 'materials' => [$line]])->assertSessionHasErrors('materials');
        $this->post($url, ['notes' => 'Ganti modem', 'materials' => [$line + ['serial' => 'SN-OTHER']]])->assertSessionHasErrors('materials');

        $this->post($url, ['notes' => 'Ganti modem', 'materials' => [$line + ['serial' => 'sn-a', 'notes' => 'ZTE F609 lama ditarik']]])
            ->assertSessionHasNoErrors();
        $this->assertStringContainsString('MDM031 FIBERHOME HG6145D2 SN SN-A dari Gudang Tim Rasyid (ZTE F609 lama ditarik)', $ticket->fresh()->histories->last()->notes);

        // Until the warehouse approves, the modem stays with the technician and cannot go anywhere else.
        $own = Warehouse::of($this->technician);
        $this->assertSame($own->id, SerialUnit::query()->where('serial_number', 'SN-A')->value('warehouse_id'));
        $this->assertSame(2, $this->quantity($own));
        $next = Ticket::factory()->status(TicketStatus::PERBAIKAN)->assignedTo($this->technician)->create(['repair_started_at' => now()]);
        $this->getJson("/tickets/{$next->ticket_number}/material/sn?technician={$this->technician->id}")
            ->assertExactJson(['MDM031' => [['sn' => 'SN-B', 'mac' => null]]]);
        $this->post("/tickets/{$next->ticket_number}/actions/LAPOR_PERBAIKAN_SELESAI", ['notes' => 'Ganti modem', 'materials' => [$line + ['serial' => 'SN-A']]])
            ->assertSessionHasErrors('materials');
        $this->actingAs($this->warehouse)->post("/gudang/{$own->id}/retur-sn", ['serials' => [['sn' => 'SN-A']]])->assertSessionHasErrors('serials');

        $this->post("/gudang/pengajuan/{$ticket->materialRequests()->sole()->id}/setujui")->assertSessionHasNoErrors();

        $unit = SerialUnit::query()->where('serial_number', 'SN-A')->sole();
        $this->assertNull($unit->warehouse_id);
        $this->assertSame($ticket->id, $unit->ticket_id);
        $this->assertSame(1, $this->quantity(Warehouse::of($this->technician)));

        $this->get("/tickets/{$ticket->ticket_number}")->assertSee('SN SN-A · 28:6D:DA:0C:D4:60');
        $this->get('/gudang?q=SN-A')->assertSee("Terpasang · {$ticket->ticket_number}");
    }

    public function test_only_the_warehouse_team_scans(): void
    {
        $this->actingAs($this->technician)->post('/gudang/masuk-sn', ['item' => 'MDM031', 'serials' => [['sn' => 'X']]])->assertForbidden();
        $this->getJson('/gudang/cek-sn?sn=X')->assertForbidden();
    }
}
