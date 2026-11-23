<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Enums\UsagePurpose;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\SerialUnit;
use App\Models\Stock;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
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

        // The warehouse team finds any SN on Cari SN; others see where it is from the stock search.
        $this->get('/gudang/tracking?q=SN-A')->assertOk()->assertSee('SN-A')->assertSee('Gudang Tim Rasyid')->assertSee('Idle · di teknisi');
        $this->actingAs($this->userWithRole(Role::HELPDESK))->get('/gudang?q=SN-A')->assertOk()->assertSee('Posisi SN')->assertSee('Gudang Tim Rasyid');
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
        $this->get('/gudang/tracking?q=SN-A')->assertSee('Terpasang di pelanggan')->assertSee($ticket->ticket_number);
    }

    public function test_barcoded_rolls_and_poles_move_by_sn_too(): void
    {
        $this->actingAs($this->warehouse)->post('/gudang/kategori', ['name' => 'Tiang', 'icon' => 'radio-tower', 'requires_serial' => 1])->assertSessionHasNoErrors();
        $poles = ItemCategory::query()->where('name', 'Tiang')->sole();

        // Whether a MAC follows the SN is set on the item master: poles carry a barcode, no MAC label.
        $this->post('/gudang/barang', ['code' => 'TNG7', 'name' => 'TIANG BESI 7M', 'unit' => 'batang', 'item_category_id' => $poles->id])->assertSessionHasNoErrors();
        $pole = Item::query()->where('code', 'TNG7')->sole();
        $this->assertTrue($pole->tracks_serial);
        $this->assertFalse($pole->scans_mac);
        $this->put("/gudang/barang/{$this->modem->id}", ['code' => 'MDM031', 'name' => $this->modem->name, 'unit' => 'unit', 'tracks_serial' => 1, 'scans_mac' => 1, 'active' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($this->modem->fresh()->scans_mac);
        // A counted item never scans a MAC.
        $this->put("/gudang/barang/{$pole->id}", ['code' => 'TNG7', 'name' => $pole->name, 'unit' => 'batang', 'scans_mac' => 1, 'active' => 1])->assertSessionHasNoErrors();
        $this->assertFalse($pole->fresh()->tracks_serial);
        $this->assertFalse($pole->fresh()->scans_mac);
        $this->get('/gudang/barang')->assertOk()->assertSee('SN + MAC');
        $this->put("/gudang/barang/{$pole->id}", ['code' => 'TNG7', 'name' => $pole->name, 'unit' => 'batang', 'item_category_id' => $poles->id, 'active' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($pole->fresh()->tracks_serial);

        $meter = Item::query()->create(['code' => 'DRC-M', 'name' => 'DROPCORE METER', 'unit' => 'meter']);
        $roll = Item::query()->create(['code' => 'DRC-R', 'name' => 'DROPCORE ROLL', 'unit' => 'roll', 'tracks_serial' => true, 'send_as_item_id' => $meter->id, 'send_ratio' => 1000]);

        $this->post('/gudang/masuk-sn', ['item' => 'TNG7', 'serials' => [['sn' => 'TNG-0001'], ['sn' => 'TNG-0002']]])->assertSessionHasNoErrors();
        $this->post('/gudang/masuk-sn', ['item' => 'DRC-R', 'serials' => [['sn' => 'ROLL-01'], ['sn' => 'ROLL-02']]])->assertSessionHasNoErrors();
        $this->get('/gudang')->assertOk()->assertSee('Unit SN Idle')->assertDontSee('Kirim Perangkat');

        $this->post('/gudang/kirim-sn', ['technician_id' => $this->technician->id, 'serials' => [['sn' => 'TNG-0001'], ['sn' => 'ROLL-01']]])->assertSessionHasNoErrors();

        $main = Warehouse::main();
        $own = Warehouse::of($this->technician);
        $stock = fn (Warehouse $warehouse, Item $item) => (int) Stock::query()->where('warehouse_id', $warehouse->id)->where('item_id', $item->id)->value('quantity');
        // The pole is now the technician's, SN and all.
        $this->assertSame($own->id, SerialUnit::query()->where('serial_number', 'TNG-0001')->value('warehouse_id'));
        $this->assertSame(1, $stock($own, $pole));
        // The roll is opened into meters: its SN leaves stock, the technician holds 1000 m.
        $opened = SerialUnit::query()->where('serial_number', 'ROLL-01')->sole();
        $this->assertNull($opened->warehouse_id);
        $this->assertSame("Dibuka jadi meter · {$own->name}", $opened->location());
        $this->assertSame(1, $stock($main, $roll));
        $this->assertSame(0, $stock($own, $roll));
        $this->assertSame(1000, $stock($own, $meter));
        $this->assertSame(0, $stock($main, $meter));

        // An opened roll can't be scanned again.
        $this->post('/gudang/kirim-sn', ['technician_id' => $this->technician->id, 'serials' => [['sn' => 'ROLL-01']]])->assertSessionHasErrors('serials');
    }

    public function test_the_send_form_lists_main_warehouse_units_to_tick(): void
    {
        $this->receive([['sn' => 'SNB', 'mac' => '28:6D:DA:0C:D4:61'], ['sn' => 'SNA'], ['sn' => 'SNC']])->assertSessionHasNoErrors();
        $this->post('/gudang/kirim-sn', ['technician_id' => $this->technician->id, 'serials' => [['sn' => 'SNC']]])->assertSessionHasNoErrors();

        // Oldest first, only what is still in the main warehouse.
        $this->getJson('/gudang/unit-sn?item=MDM031')->assertOk()
            ->assertExactJson([['sn' => 'SNB', 'mac' => '28:6D:DA:0C:D4:61'], ['sn' => 'SNA', 'mac' => null]]);
        $own = Warehouse::of($this->technician);
        $this->getJson("/gudang/unit-sn?item=MDM031&warehouse={$own->id}")->assertOk()->assertExactJson([['sn' => 'SNC', 'mac' => null]]);
        $this->get('/gudang')->assertOk()->assertSee('Pilih SN yang dikirim');

        $this->actingAs($this->technician)->getJson('/gudang/unit-sn?item=MDM031')->assertForbidden();
    }

    public function test_a_wrong_or_missing_sn_and_mac_can_be_corrected(): void
    {
        $this->modem->update(['scans_mac' => true]);
        $this->receive([['sn' => 'SN-TYPO'], ['sn' => 'SN-OTHER', 'mac' => '28:6D:DA:0C:D4:60']])->assertSessionHasNoErrors();
        $unit = SerialUnit::query()->where('serial_number', 'SN-TYPO')->sole();
        $this->get('/gudang?q=MDM031')->assertOk()->assertSee('1 belum ada MAC')->assertSee('MAC kosong');

        $this->put("/gudang/unit-sn/{$unit->id}", ['serial_number' => ' sn-fixed ', 'mac' => '28-6d-da-0c-d4-61'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'SN SN-TYPO diubah menjadi SN-FIXED');
        $unit->refresh();
        $this->assertSame('SN-FIXED', $unit->serial_number);
        $this->assertSame('286DDA0CD461', $unit->mac);
        $this->assertSame(Warehouse::main()->id, $unit->warehouse_id);

        // Another unit's SN or MAC, or a malformed MAC, is refused.
        $this->put("/gudang/unit-sn/{$unit->id}", ['serial_number' => 'SN-OTHER'])->assertSessionHasErrors('serial_edit');
        $this->put("/gudang/unit-sn/{$unit->id}", ['serial_number' => 'SN-FIXED', 'mac' => '28:6D:DA:0C:D4:60'])->assertSessionHasErrors('serial_edit');
        $this->put("/gudang/unit-sn/{$unit->id}", ['serial_number' => 'SN-FIXED', 'mac' => '12:34'])->assertSessionHasErrors('serial_edit');
        // Clearing the MAC is allowed (a unit without a label).
        $this->put("/gudang/unit-sn/{$unit->id}", ['serial_number' => 'SN-FIXED', 'mac' => ''])->assertSessionHasNoErrors();
        $this->assertNull($unit->fresh()->mac);

        $this->actingAs($this->technician)->put("/gudang/unit-sn/{$unit->id}", ['serial_number' => 'X'])->assertForbidden();
    }

    public function test_stock_from_before_an_item_switched_to_sn_is_still_used_by_quantity(): void
    {
        $cable = Item::query()->create(['code' => 'KBL-1', 'name' => 'KABEL DROPCORE', 'unit' => 'roll']);
        $this->actingAs($this->warehouse)->post('/gudang/masuk', ['item' => 'KBL-1', 'quantity' => 5])->assertSessionHasNoErrors();
        $this->post('/gudang/kirim', ['item' => 'KBL-1', 'quantity' => 3, 'technician_id' => $this->technician->id])->assertSessionHasNoErrors();

        // Switching to SN is allowed with stock on hand: it stays as stock without SN.
        $this->put("/gudang/barang/{$cable->id}", ['code' => 'KBL-1', 'name' => $cable->name, 'unit' => 'roll', 'tracks_serial' => 1, 'active' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($cable->fresh()->tracks_serial);

        // New stock only comes in by SN.
        $this->post('/gudang/masuk', ['item' => 'KBL-1', 'quantity' => 1])->assertSessionHasErrors('item');
        $this->post('/gudang/masuk-sn', ['item' => 'KBL-1', 'serials' => [['sn' => 'ROLL-NEW']]])->assertSessionHasNoErrors();

        $main = Warehouse::main();
        $own = Warehouse::of($this->technician);
        $stock = fn (Warehouse $warehouse) => (int) Stock::query()->where('warehouse_id', $warehouse->id)->where('item_id', $cable->id)->value('quantity');
        $inventory = app(InventoryService::class);
        $this->assertSame(2, $inventory->looseAvailable($main, $cable->fresh()));
        $this->assertSame(3, $inventory->looseAvailable($own, $cable->fresh()));
        $this->get('/gudang')->assertOk()->assertSee('KBL-1 (TANPA SN)');
        $this->get('/gudang?q=KBL-1')->assertOk()->assertSee('2 roll stok lama tanpa SN');

        // The old stock still moves by quantity, but never more than what has no SN.
        $this->post('/gudang/kirim', ['item' => 'KBL-1 (TANPA SN)', 'quantity' => 3, 'technician_id' => $this->technician->id])->assertSessionHasErrors('item');
        $this->post('/gudang/kirim', ['item' => 'KBL-1 (TANPA SN)', 'quantity' => 2, 'technician_id' => $this->technician->id])->assertSessionHasNoErrors();
        $this->assertSame(1, $stock($main));
        $this->assertSame(5, $stock($own));
        $this->post("/gudang/{$own->id}/retur", ['item' => 'KBL-1 (TANPA SN)', 'quantity' => 1])->assertSessionHasNoErrors();
        $this->assertSame(4, $stock($own));

        // The technician uses the rest on a job; the SN unit still goes by its SN.
        $inventory->recordUsage(['purpose' => UsagePurpose::PEMASANGAN_BARU->value, 'location' => 'Jl. Mawar'], [['item' => 'KBL-1 (TANPA SN)', 'quantity' => 4]], $own, $this->warehouse);
        $this->assertSame(0, $stock($own));
        // Main holds the SN roll plus the 1 roll returned without SN: only that 1 goes by quantity.
        $this->assertSame(1, $inventory->looseAvailable($main, $cable->fresh()));
        $this->post('/gudang/kirim', ['item' => 'KBL-1', 'quantity' => 2, 'technician_id' => $this->technician->id])->assertSessionHasErrors('item');
        $this->post('/gudang/kirim-sn', ['technician_id' => $this->technician->id, 'serials' => [['sn' => 'ROLL-NEW']]])->assertSessionHasNoErrors();
        $this->assertSame(0, $inventory->looseAvailable($own, $cable->fresh()));
    }

    public function test_only_the_warehouse_team_scans(): void
    {
        $this->actingAs($this->technician)->post('/gudang/masuk-sn', ['item' => 'MDM031', 'serials' => [['sn' => 'X']]])->assertForbidden();
        $this->getJson('/gudang/cek-sn?sn=X')->assertForbidden();
    }
}
