<?php

namespace Tests\Feature;

use App\Enums\MaterialRequestStatus;
use App\Enums\Menu;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Enums\TicketStatus;
use App\Models\Item;
use App\Models\SerialUnit;
use App\Models\Stock;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private User $warehouse;

    private User $supervisor;

    private User $technician;

    private Item $clamp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = $this->userWithRole(Role::GUDANG, ['name' => 'Admin Gudang']);
        $this->supervisor = $this->userWithRole(Role::SPV);
        $this->technician = $this->userWithRole(Role::TEKNISI, ['name' => 'Tim Baim']);
        $this->clamp = Item::query()->create(['code' => 'AKS001', 'name' => 'CLAMP S']);
    }

    private function quantity(Warehouse $warehouse, Item $item): int
    {
        return (int) Stock::query()->where('warehouse_id', $warehouse->id)->where('item_id', $item->id)->value('quantity');
    }

    public function test_a_technician_stock_gone_minus_is_set_back_to_the_counted_figure(): void
    {
        $own = Warehouse::of($this->technician);
        Stock::query()->create(['warehouse_id' => $own->id, 'item_id' => $this->clamp->id, 'quantity' => -5]);

        $this->actingAs($this->warehouse)->get('/gudang/adjust-stok')->assertOk()
            ->assertSee('Stok minus')->assertSee('Gudang Tim Baim')->assertDontSee('Log Adjust Stok');

        $this->post('/gudang/adjust-stok', ['warehouse_id' => $own->id, 'item' => 'AKS001', 'quantity' => 0, 'reason' => 'Pemakaian terinput dobel'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->quantity($own, $this->clamp));
        $adjustment = StockAdjustment::query()->sole();
        $this->assertSame([-5, 0, 5], [$adjustment->quantity_before, $adjustment->quantity_after, $adjustment->difference]);
        $this->assertSame($this->warehouse->id, $adjustment->user_id);

        $movement = StockMovement::query()->sole();
        $this->assertSame(StockMovementType::PENYESUAIAN, $movement->type);
        $this->assertSame(5, $movement->quantity);
        $this->assertSame($own->id, $movement->to_warehouse_id);
        $this->assertNull($movement->from_warehouse_id);

        // Going down takes stock out of the warehouse.
        $this->post('/gudang/adjust-stok', ['warehouse_id' => Warehouse::main()->id, 'item' => 'AKS001', 'quantity' => 0, 'reason' => 'x'])
            ->assertSessionHasErrors('quantity');
        Stock::query()->create(['warehouse_id' => Warehouse::main()->id, 'item_id' => $this->clamp->id, 'quantity' => 100]);
        $this->post('/gudang/adjust-stok', ['warehouse_id' => Warehouse::main()->id, 'item' => 'AKS001', 'quantity' => 92, 'reason' => 'Hitung fisik'])
            ->assertSessionHasNoErrors();
        $this->assertSame(92, $this->quantity(Warehouse::main(), $this->clamp));
        $this->assertSame(Warehouse::main()->id, StockMovement::query()->latest('id')->first()->from_warehouse_id);
    }

    public function test_a_resume_using_more_cable_than_recorded_is_approved_and_the_warehouse_is_told_to_adjust(): void
    {
        $cable = Item::query()->create(['code' => 'KBL002', 'name' => 'DROPCORE 1 METER', 'unit' => 'meter']);
        $own = Warehouse::of($this->technician);
        Stock::query()->create(['warehouse_id' => $own->id, 'item_id' => $cable->id, 'quantity' => 50]);
        $ticket = Ticket::factory()->status(TicketStatus::PERBAIKAN)->assignedTo($this->technician)->create(['repair_started_at' => now()]);

        // 80 m used, 50 m on record: the rest came from the technician's own leftovers.
        $this->actingAs($this->technician)->post("/tickets/{$ticket->ticket_number}/actions/LAPOR_PERBAIKAN_SELESAI", [
            'notes' => 'Tarik ulang kabel',
            'materials' => [['technician_id' => $this->technician->id, 'code' => 'KBL002', 'quantity' => 80]],
        ])->assertSessionHasNoErrors();
        $request = $ticket->materialRequests()->sole();

        $this->actingAs($this->warehouse)->get('/gudang')->assertSee('Melebihi stok sistem Gudang Tim Baim (50 meter)');
        $this->post("/gudang/pengajuan/{$request->id}/setujui")->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, '1 barang jadi minus'));
        $this->assertSame(-30, $this->quantity($own, $cable));
        $this->assertSame(MaterialRequestStatus::APPROVED, $request->fresh()->status);

        // Every warehouse member is told, with a link that opens Adjust Stok on that technician and item.
        $notice = $this->warehouse->notifications()->sole()->data;
        $this->assertStringContainsString('stok jadi -30 meter', $notice['body']);
        $this->assertSame(route('stock-adjustments.index', ['gudang' => $own->id, 'barang' => 'KBL002'], absolute: false), $notice['url']);
        $this->assertSame(0, $this->technician->notifications()->count());

        $this->get('/gudang')->assertSee('1 stok minus');
        $this->get($notice['url'])->assertOk()->assertSee('Stok minus')->assertSee('DROPCORE 1 METER');
        $this->post('/gudang/adjust-stok', ['warehouse_id' => $own->id, 'item' => 'KBL002', 'quantity' => 5, 'reason' => 'Sisa kabel pribadi teknisi 35 m, sisa sekarang 5 m'])
            ->assertSessionHasNoErrors();
        $this->assertSame(5, $this->quantity($own, $cable));
        $this->get('/gudang')->assertDontSee('stok minus');
    }

    public function test_only_supervisors_see_the_adjustment_log(): void
    {
        $own = Warehouse::of($this->technician);
        Stock::query()->create(['warehouse_id' => $own->id, 'item_id' => $this->clamp->id, 'quantity' => -3]);
        $this->actingAs($this->warehouse)->post('/gudang/adjust-stok', ['warehouse_id' => $own->id, 'item' => 'AKS001', 'quantity' => 2, 'reason' => 'Sisa di tas teknisi']);

        $this->get('/gudang/adjust-stok')->assertOk()->assertDontSee('Log Adjust Stok')->assertDontSee('Sisa di tas teknisi');
        // The ordinary stock history shows the change, but not the reason.
        $this->get("/gudang/{$own->id}")->assertOk()->assertSee('Adjust Stok')->assertDontSee('Sisa di tas teknisi');

        $this->actingAs($this->supervisor)->get('/gudang/adjust-stok')->assertOk()
            ->assertSee('Log Adjust Stok')->assertSee('Sisa di tas teknisi')->assertSee('Admin Gudang');
    }

    public function test_only_the_warehouse_team_and_supervisors_can_adjust(): void
    {
        $helpdesk = $this->userWithRole(Role::HELPDESK);
        // Even when a supervisor grants the warehouse-manage menu, adjusting stays with GUDANG and SPV.
        $helpdesk->forceFill(['menu_access' => [Menu::WAREHOUSE_MANAGE->value => true]])->save();

        foreach ([$helpdesk, $this->technician] as $user) {
            $this->actingAs($user)->get('/gudang/adjust-stok')->assertForbidden();
            $this->post('/gudang/adjust-stok', ['warehouse_id' => Warehouse::main()->id, 'item' => 'AKS001', 'quantity' => 1, 'reason' => 'x'])->assertForbidden();
        }
        $this->assertSame(0, StockAdjustment::query()->count());

        $this->actingAs($this->warehouse)->get('/gudang')->assertSee('Adjust Stok');
    }

    public function test_an_sn_item_cannot_go_below_the_units_it_holds_and_a_reason_is_required(): void
    {
        $modem = Item::query()->create(['code' => 'MDM001', 'name' => 'ONT ZTE', 'tracks_serial' => true]);
        $main = Warehouse::main();
        SerialUnit::query()->create(['item_id' => $modem->id, 'serial_number' => 'ZTEG0001', 'warehouse_id' => $main->id]);
        SerialUnit::query()->create(['item_id' => $modem->id, 'serial_number' => 'ZTEG0002', 'warehouse_id' => $main->id]);
        Stock::query()->create(['warehouse_id' => $main->id, 'item_id' => $modem->id, 'quantity' => 4]);

        $this->actingAs($this->supervisor)
            ->post('/gudang/adjust-stok', ['warehouse_id' => $main->id, 'item' => 'MDM001', 'quantity' => 1, 'reason' => 'Hitung'])
            ->assertSessionHasErrors('quantity');
        $this->post('/gudang/adjust-stok', ['warehouse_id' => $main->id, 'item' => 'MDM001', 'quantity' => 2, 'reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->post('/gudang/adjust-stok', ['warehouse_id' => $main->id, 'item' => 'MDM001', 'quantity' => -1, 'reason' => 'Hitung'])
            ->assertSessionHasErrors('quantity');
        $this->post('/gudang/adjust-stok', ['warehouse_id' => $main->id, 'item' => 'MDM001', 'quantity' => 4, 'reason' => 'Hitung'])
            ->assertSessionHasErrors('quantity');

        $this->post('/gudang/adjust-stok', ['warehouse_id' => $main->id, 'item' => 'MDM001', 'quantity' => 2, 'reason' => 'Stok lama tanpa SN sudah habis'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $this->quantity($main, $modem));
    }
}
