<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\UsagePurpose;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\SerialUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackingTest extends TestCase
{
    use RefreshDatabase;

    private User $warehouse;

    private User $technician;

    private Item $modem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = $this->userWithRole(Role::GUDANG);
        $this->technician = $this->userWithRole(Role::TEKNISI, ['name' => 'Tim Dian']);
        $this->modem = Item::query()->create([
            'code' => 'MDM049', 'name' => 'MODEM FIBERHOME HG6145D2', 'tracks_serial' => true, 'scans_mac' => true,
            'item_category_id' => ItemCategory::query()->where('name', 'Modem / ONT')->value('id'), 'variant' => 'ONT GPON', 'brand' => 'Fiberhome',
        ]);
        $this->actingAs($this->warehouse)->post('/gudang/masuk-sn', ['item' => 'MDM049', 'serials' => [
            ['sn' => 'FH-IN-STOCK'], ['sn' => 'FH-USED', 'mac' => '28:6D:DA:0C:D4:60'], ['sn' => 'FH-SOLD'],
        ]])->assertSessionHasNoErrors();
    }

    /**
     * A unit as the TRACKING ALAT import leaves it: out of stock with what the sheet said.
     */
    private function imported(string $sn, array $fields): SerialUnit
    {
        return SerialUnit::query()->create(['item_id' => $this->modem->id, 'serial_number' => $sn, 'imported_at' => now()] + $fields);
    }

    public function test_units_that_left_stock_say_where_they_went(): void
    {
        $inventory = app(InventoryService::class);
        // Sent to a technician, who then installs it on a job recorded in the app.
        $this->post('/gudang/kirim-sn', ['technician_id' => $this->technician->id, 'serials' => [['sn' => 'FH-USED']]])->assertSessionHasNoErrors();
        $sent = SerialUnit::query()->where('serial_number', 'FH-USED')->sole();
        $this->assertSame(['Tim Dian', today()->toDateString()], [$sent->holder_name, $sent->taken_at->toDateString()]);
        $inventory->recordUsage(
            ['purpose' => UsagePurpose::PEMASANGAN_BARU->value, 'location' => 'Rahayu Tambakroto', 'removed_device' => 'ZTE F609'],
            [['item' => 'MDM049', 'serials' => ['FH-USED']]], Warehouse::of($this->technician), $this->warehouse,
        );
        $inventory->sell(['buyer_name' => 'Ladju Net'], [['item' => 'MDM049', 'price' => 250000, 'serials' => ['FH-SOLD']]], $this->warehouse);
        $this->imported('FH-SHEET', ['holder_name' => 'TIM BAIM', 'installed_to' => 'Baldes Salakbrojo', 'install_status' => 'MIGRASI', 'taken_at' => '2026-06-18']);
        $this->imported('FH-PARTNER', ['holder_name' => 'PT IKM', 'condition' => 'Mitra PT IKM']);
        $this->imported('FH-RETUR', ['holder_name' => 'ALI', 'installed_to' => 'Kristi Tanjungkulon', 'condition' => 'RETUR']);

        $used = SerialUnit::query()->where('serial_number', 'FH-USED')->sole();
        $this->assertSame(['Tim Dian', 'Rahayu Tambakroto', 'PEMASANGAN BARU', 'ZTE F609'], [$used->holder_name, $used->installed_to, $used->install_status, $used->replaced_device]);
        $this->assertSame('pelanggan', $used->destination());

        $page = $this->get('/gudang/tracking')->assertOk()->assertSee('FH-USED')->assertSee('Rahayu Tambakroto')->assertDontSee('FH-IN-STOCK');
        $this->assertSame(['pelanggan' => 2, 'mitra' => 2, 'retur' => 1, 'lainnya' => 0], $page->viewData('counts')->all());
        $this->assertSame(5, $page->viewData('total'));

        $codes = fn (string $query) => collect($this->get("/gudang/tracking?{$query}")->assertOk()->viewData('units')->items())->pluck('serial_number')->sort()->values()->all();
        $this->assertSame(['FH-SHEET', 'FH-USED'], $codes('tujuan=pelanggan'));
        $this->assertSame(['FH-PARTNER', 'FH-SOLD'], $codes('tujuan=mitra'));
        $this->assertSame(['FH-USED'], $codes('q=286dda0c'));
        $this->assertSame(['FH-SHEET'], $codes('q=salakbrojo'));
        $this->assertSame(['FH-SHEET'], $codes('status=MIGRASI'));
        $this->assertSame(['FH-RETUR'], $codes('teknisi=ALI'));
        $this->assertSame(['FH-SHEET'], $codes('dari=2026-06-01&sampai=2026-06-30'));

        $this->get('/gudang/tracking/export?tujuan=pelanggan')->assertOk()->assertDownload();

        // One unit's story, from receiving to the job.
        $this->get("/gudang/unit/{$used->id}")->assertOk()->assertSee('FH-USED')->assertSee('Kirim ke Teknisi')->assertSee('Rahayu Tambakroto');
        $sheet = SerialUnit::query()->where('serial_number', 'FH-SHEET')->sole();
        $this->get("/gudang/unit/{$sheet->id}")->assertOk()->assertSee('Diambil TIM BAIM')->assertSee('data TRACKING ALAT');

        // Only the warehouse team sees it.
        $this->actingAs($this->technician)->get('/gudang/tracking')->assertForbidden();
    }

    public function test_the_category_page_shows_used_units_beside_stock(): void
    {
        $this->imported('FH-SHEET', ['holder_name' => 'TIM BAIM', 'installed_to' => 'Baldes Salakbrojo']);
        $main = Warehouse::main();
        $page = "/gudang/{$main->id}/stok/{$this->modem->item_category_id}";

        $this->get("{$page}?jenis=ONT+GPON&merk=Fiberhome")->assertOk()->assertSee('1 terpakai');
        $used = $this->get("{$page}?tampil=terpakai")->assertOk()->assertViewHas('used', true)->assertViewHas('level', 'jenis');
        $this->assertSame(1, $used->viewData('groups')->firstWhere('key', 'ONT GPON')['units']);
        $this->get("{$page}?tampil=terpakai&jenis=ONT+GPON&merk=Fiberhome")->assertOk()->assertSee('FH-SHEET')->assertSee('Baldes Salakbrojo')->assertDontSee('FH-IN-STOCK');
    }

    public function test_a_cancelled_usage_clears_where_the_unit_went(): void
    {
        $usage = app(InventoryService::class)->recordUsage(
            ['purpose' => UsagePurpose::PEMASANGAN_BARU->value, 'location' => 'Salah input'], [['item' => 'MDM049', 'serials' => ['FH-USED']]], Warehouse::main(), $this->warehouse,
        );
        app(InventoryService::class)->cancelUsage($usage, $this->warehouse, 'Salah barang');

        $unit = SerialUnit::query()->where('serial_number', 'FH-USED')->sole();
        $this->assertSame(Warehouse::main()->id, $unit->warehouse_id);
        $this->assertNull($unit->installed_to);
        $this->assertNull($unit->destination());
    }
}
