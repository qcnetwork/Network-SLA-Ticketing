<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Item;
use App\Models\SerialUnit;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private User $technician;

    private Warehouse $own;

    protected function setUp(): void
    {
        parent::setUp();

        $this->technician = $this->userWithRole(Role::TEKNISI, ['name' => 'Tim Baim']);
        $this->own = Warehouse::of($this->technician);
        $modem = Item::query()->create(['code' => 'MDM001', 'name' => 'ONT ZTE F609', 'tracks_serial' => true]);
        $cable = Item::query()->create(['code' => 'KBL002', 'name' => 'DROPCORE 1 METER', 'unit' => 'meter']);
        $clamp = Item::query()->create(['code' => 'AKS001', 'name' => 'CLAMP S']);
        foreach (['ZTEGAAA1', 'ZTEGAAA2', 'ZTEGAAA3'] as $sn) {
            SerialUnit::query()->create(['item_id' => $modem->id, 'serial_number' => $sn, 'mac' => 'AABBCCDDEE01', 'warehouse_id' => $this->own->id]);
        }
        Stock::query()->create(['warehouse_id' => $this->own->id, 'item_id' => $modem->id, 'quantity' => 3]);
        Stock::query()->create(['warehouse_id' => $this->own->id, 'item_id' => $cable->id, 'quantity' => -20]);
        Stock::query()->create(['warehouse_id' => $this->own->id, 'item_id' => $clamp->id, 'quantity' => 0]);
    }

    public function test_the_closing_page_lists_every_item_and_every_sn_of_a_technician(): void
    {
        $warehouse = $this->userWithRole(Role::GUDANG);
        $other = $this->userWithRole(Role::TEKNISI, ['name' => 'Tim Dian']);
        Warehouse::of($other);

        $this->actingAs($warehouse)->get("/gudang/{$this->own->id}/screenshot")->assertOk()
            ->assertSee('Tim Baim')->assertSee('ONT ZTE F609')
            ->assertSeeInOrder(['ZTEGAAA1', 'ZTEGAAA2', 'ZTEGAAA3'])
            ->assertSee('DROPCORE 1 METER')->assertSee('stok minus')
            ->assertDontSee('CLAMP S')
            ->assertDontSee('AA:BB:CC:DD:EE:01')
            ->assertSee('Tim Dian');
        $this->get("/gudang/{$this->own->id}/screenshot?mac=1")->assertSee('AA:BB:CC:DD:EE:01');
        $this->get("/gudang/{$this->own->id}")->assertSee('Screenshot Stok');
        $this->get('/gudang')->assertSee(route('warehouses.snapshot', $this->own));
        $this->get('/gudang/'.Warehouse::main()->id.'/screenshot')->assertRedirect('/gudang');
    }

    public function test_a_technician_sees_only_their_own(): void
    {
        $other = Warehouse::of($this->userWithRole(Role::TEKNISI));

        $this->actingAs($this->technician)->get("/gudang/{$this->own->id}/screenshot")->assertOk()->assertSee('ZTEGAAA1');
        $this->get("/gudang/{$other->id}/screenshot")->assertForbidden();
    }
}
