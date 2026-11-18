<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Models\Item;
use App\Models\Partner;
use App\Models\Sale;
use App\Models\SerialUnit;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesTest extends TestCase
{
    use RefreshDatabase;

    private User $warehouse;

    private Item $adaptor;

    private Item $modem;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = $this->userWithRole(Role::GUDANG, ['name' => 'Admin Gudang']);
        $this->adaptor = Item::query()->create(['code' => 'ADP001', 'name' => 'ADAPTOR 12 V 1 A']);
        $this->modem = Item::query()->create(['code' => 'MDM001', 'name' => 'ONT ZTE F609', 'tracks_serial' => true]);
        $this->partner = Partner::query()->create(['name' => 'RT/RW Net Sumberejo', 'phone' => '0812']);

        $main = Warehouse::main();
        Stock::query()->create(['warehouse_id' => $main->id, 'item_id' => $this->adaptor->id, 'quantity' => 10]);
        Stock::query()->create(['warehouse_id' => $main->id, 'item_id' => $this->modem->id, 'quantity' => 2]);
        foreach (['ZTEG001', 'ZTEG002'] as $sn) {
            SerialUnit::query()->create(['item_id' => $this->modem->id, 'serial_number' => $sn, 'warehouse_id' => $main->id]);
        }
    }

    private function quantity(Item $item): int
    {
        return (int) Stock::query()->where('warehouse_id', Warehouse::main()->id)->where('item_id', $item->id)->value('quantity');
    }

    public function test_selling_to_a_partner_takes_stock_and_serials_out_of_the_main_warehouse(): void
    {
        $this->actingAs($this->warehouse)->get('/gudang/penjualan/baru')->assertOk()
            ->assertSee('RT/RW Net Sumberejo')->assertSee('ADP001');

        $response = $this->post('/gudang/penjualan', [
            'buyer_type' => 'mitra',
            'partner_id' => $this->partner->id,
            'sold_at' => today()->toDateString(),
            'paid' => '0',
            'lines' => [
                ['item' => 'ADP001', 'quantity' => 4, 'price' => 25000],
                ['item' => 'MDM001', 'price' => 350000, 'serials' => ['zteg001']],
            ],
        ])->assertSessionHasNoErrors();

        $sale = Sale::query()->sole();
        $response->assertRedirect(route('sales.show', $sale));
        $this->assertSame('PJ-'.today()->format('Ymd').'-001', $sale->sale_number);
        $this->assertSame('RT/RW Net Sumberejo', $sale->buyer_name);
        $this->assertSame(4 * 25000 + 350000, $sale->total);
        $this->assertSame(PaymentStatus::BELUM_LUNAS, $sale->payment_status);

        $this->assertSame(6, $this->quantity($this->adaptor));
        $this->assertSame(1, $this->quantity($this->modem));
        $sold = SerialUnit::query()->where('serial_number', 'ZTEG001')->sole();
        $this->assertNull($sold->warehouse_id);
        $this->assertSame($sale->id, $sold->sale_id);
        $this->assertStringContainsString('Terjual', $sold->location());
        $this->assertSame(2, StockMovement::query()->where('type', StockMovementType::JUAL)->where('sale_id', $sale->id)->count());

        $this->get(route('sales.show', $sale))->assertOk()->assertSee('ZTEG001')->assertSee('Rp 450.000');
        $this->get('/gudang/penjualan')->assertOk()->assertSee($sale->sale_number)->assertSee('Belum Lunas');
        $this->get('/gudang')->assertOk()->assertSee($sale->sale_number);

        $this->patch(route('sales.payment', $sale), ['payment_status' => 'LUNAS', 'payment_notes' => 'Transfer BRI'])->assertSessionHasNoErrors();
        $sale->refresh();
        $this->assertTrue($sale->isPaid());
        $this->assertSame($this->warehouse->id, $sale->paid_by);
        $this->assertSame('Transfer BRI', $sale->payment_notes);
    }

    public function test_a_buyer_who_is_not_a_partner_is_typed_in(): void
    {
        $this->actingAs($this->warehouse)->post('/gudang/penjualan', [
            'buyer_type' => 'lain',
            'buyer_name' => 'Pak Joko',
            'buyer_phone' => '0857',
            'sold_at' => today()->toDateString(),
            'paid' => '1',
            'lines' => [['item' => 'ADP001 · ADAPTOR 12 V 1 A', 'quantity' => 1, 'price' => 30000]],
        ])->assertSessionHasNoErrors();

        $sale = Sale::query()->sole();
        $this->assertNull($sale->partner_id);
        $this->assertSame('Pak Joko', $sale->buyer_name);
        $this->assertTrue($sale->isPaid());

        $this->get('/gudang/penjualan?mitra=lain')->assertOk()->assertSee('Pak Joko');
        $this->get('/gudang/penjualan?mitra='.$this->partner->id)->assertOk()->assertDontSee('Pak Joko');
        $this->get('/gudang/penjualan/export')->assertOk()->assertDownload();
    }

    public function test_a_sale_never_takes_more_than_the_main_warehouse_holds(): void
    {
        $technician = $this->userWithRole(Role::TEKNISI);
        SerialUnit::query()->where('serial_number', 'ZTEG002')->update(['warehouse_id' => Warehouse::of($technician)->id]);
        $base = ['buyer_type' => 'mitra', 'partner_id' => $this->partner->id, 'sold_at' => today()->toDateString(), 'paid' => '0'];

        $this->actingAs($this->warehouse)->post('/gudang/penjualan', $base + ['lines' => [['item' => 'ADP001', 'quantity' => 11, 'price' => 1]]])
            ->assertSessionHasErrors('lines');
        // A device held by a technician is not the main warehouse's to sell.
        $this->post('/gudang/penjualan', $base + ['lines' => [['item' => 'MDM001', 'price' => 1, 'serials' => ['ZTEG002']]]])
            ->assertSessionHasErrors('lines');
        // Nothing half-saved: the adaptor line before a bad SN line is rolled back too.
        $this->post('/gudang/penjualan', $base + ['lines' => [['item' => 'ADP001', 'quantity' => 2, 'price' => 1], ['item' => 'MDM001', 'price' => 1, 'serials' => ['NOPE']]]])
            ->assertSessionHasErrors('lines');
        $this->post('/gudang/penjualan', ['buyer_type' => 'lain', 'sold_at' => today()->toDateString(), 'paid' => '0', 'lines' => [['item' => 'ADP001', 'quantity' => 1, 'price' => 1]]])
            ->assertSessionHasErrors('buyer_name');

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(10, $this->quantity($this->adaptor));
    }

    public function test_partners_are_kept_by_the_warehouse_and_only_managers_sell(): void
    {
        $this->actingAs($this->warehouse)->post('/gudang/mitra', ['name' => 'Mitra Baru', 'phone' => '0899'])->assertSessionHasNoErrors();
        $partner = Partner::query()->where('name', 'Mitra Baru')->sole();
        $this->put(route('partners.update', $partner), ['name' => 'Mitra Baru Jaya'])->assertSessionHasNoErrors();
        $this->assertFalse($partner->refresh()->active);
        $this->get('/gudang/mitra')->assertOk()->assertSee('Mitra Baru Jaya');
        // An inactive partner is not offered, and cannot be sold to.
        $this->get('/gudang/penjualan/baru')->assertDontSee('Mitra Baru Jaya');
        $this->post('/gudang/penjualan', ['buyer_type' => 'mitra', 'partner_id' => $partner->id, 'sold_at' => today()->toDateString(), 'paid' => '0',
            'lines' => [['item' => 'ADP001', 'quantity' => 1, 'price' => 1]]])->assertSessionHasErrors('partner_id');

        foreach ([Role::TEKNISI, Role::HELPDESK, Role::CS] as $role) {
            $this->actingAs($this->userWithRole($role))->get('/gudang/penjualan')->assertForbidden();
        }
    }
}
