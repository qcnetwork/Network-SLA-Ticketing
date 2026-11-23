<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Item;
use App\Models\SerialUnit;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockPickupTest extends TestCase
{
    use RefreshDatabase;

    private User $warehouse;

    private User $esa;

    private User $fadil;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = $this->userWithRole(Role::GUDANG, ['name' => 'Bu Ita']);
        $this->esa = $this->userWithRole(Role::TEKNISI, ['name' => 'Esa']);
        $this->fadil = $this->userWithRole(Role::TEKNISI, ['name' => 'Fadil']);
    }

    public function test_a_teammate_collects_stock_for_another_technician(): void
    {
        $modem = Item::query()->create(['code' => 'MDM001', 'name' => 'ONT ZTE F609', 'tracks_serial' => true]);
        $clamp = Item::query()->create(['code' => 'AKS001', 'name' => 'CLAMP S']);
        SerialUnit::query()->create(['item_id' => $modem->id, 'serial_number' => 'ZTEG0001', 'warehouse_id' => Warehouse::main()->id]);
        Stock::query()->create(['warehouse_id' => Warehouse::main()->id, 'item_id' => $modem->id, 'quantity' => 1]);
        Stock::query()->create(['warehouse_id' => Warehouse::main()->id, 'item_id' => $clamp->id, 'quantity' => 50]);

        $this->actingAs($this->warehouse)->get('/gudang')->assertSee('Diambil oleh');
        $this->post('/gudang/kirim-sn', ['technician_id' => $this->esa->id, 'taken_by' => $this->fadil->id, 'serials' => [['sn' => 'ZTEG0001']]])
            ->assertSessionHasNoErrors()->assertSessionHas('success', '1 unit dikirim ke Esa, diambil Fadil');
        $this->post('/gudang/kirim', ['technician_id' => $this->esa->id, 'item' => 'AKS001', 'quantity' => 10])->assertSessionHasNoErrors();
        // Naming the recipient as the collector is the same as leaving it empty.
        $this->post('/gudang/kirim', ['technician_id' => $this->esa->id, 'taken_by' => $this->esa->id, 'item' => 'AKS001', 'quantity' => 5])->assertSessionHasNoErrors();

        // The stock is Esa's; only the collector is recorded.
        $own = Warehouse::of($this->esa);
        $this->assertSame($own->id, SerialUnit::query()->sole()->warehouse_id);
        $this->assertSame(0, Warehouse::of($this->fadil)->stocks()->count());
        [$sent, $self, $again] = StockMovement::query()->orderBy('id')->get()->all();
        $this->assertSame($this->fadil->id, $sent->taken_by);
        $this->assertNull($self->taken_by);
        $this->assertNull($again->taken_by);

        // Esa hears about stock collected in his name; nobody hears about collecting their own.
        $notice = $this->esa->notifications()->sole()->data;
        $this->assertSame('Stok atas nama Anda diambil Fadil', $notice['title']);
        $this->assertStringContainsString('ZTEG0001', $notice['body']);
        $this->assertSame(0, $this->fadil->notifications()->count());

        $this->get("/gudang/{$own->id}")->assertSee('diambil Fadil');
        $this->get("/gudang/unit/{$sent->serial_unit_id}")->assertSee('Dikirim, diambil Fadil');

        $this->get('/gudang/pengambilan')->assertOk()->assertSee('atas nama Esa')->assertSee('1</b> diambil orang lain', false);
        $this->get('/gudang/pengambilan?orang_lain=1')->assertSee('ZTEG0001')->assertDontSee('CLAMP S');
        $this->get("/gudang/pengambilan?pengambil={$this->fadil->id}")->assertSee('ZTEG0001')->assertDontSee('CLAMP S');
        $this->get("/gudang/pengambilan?pengambil={$this->esa->id}")->assertSee('CLAMP S')->assertDontSee('ZTEG0001');
        $this->get("/gudang/pengambilan?gudang={$own->id}")->assertSee('ZTEG0001')->assertSee('CLAMP S');
        $this->get("/gudang/pengambilan?pemilik={$this->esa->id}")->assertSee('ZTEG0001')->assertSee('CLAMP S');
        $this->get("/gudang/pengambilan?pemilik={$this->fadil->id}")->assertDontSee('ZTEG0001')->assertDontSee('CLAMP S');
    }

    public function test_the_collector_must_be_an_active_technician_or_helpdesk_and_the_log_is_for_the_warehouse_team(): void
    {
        Item::query()->create(['code' => 'AKS001', 'name' => 'CLAMP S']);
        $cs = $this->userWithRole(Role::CS);
        $this->actingAs($this->warehouse)->post('/gudang/kirim', ['technician_id' => $this->esa->id, 'taken_by' => $cs->id, 'item' => 'AKS001', 'quantity' => 1])
            ->assertSessionHasErrors('taken_by');

        $this->actingAs($this->esa)->get('/gudang/pengambilan')->assertForbidden();
    }
}
