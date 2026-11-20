<?php

namespace Tests\Feature;

use App\Enums\ItemKind;
use App\Enums\Role;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ItemClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private User $warehouse;

    private ItemCategory $modems;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = $this->userWithRole(Role::GUDANG);
        $this->modems = ItemCategory::query()->where('name', 'Modem / ONT')->sole();
        $this->modems->update(['code_prefix' => 'MDM']);
    }

    private function newItem(array $fields)
    {
        return $this->actingAs($this->warehouse)->post('/gudang/barang', $fields + ['unit' => 'unit', 'item_category_id' => $this->modems->id]);
    }

    public function test_a_new_model_gets_its_category_code_jenis_merk_and_sifat(): void
    {
        Item::query()->create(['code' => 'MDM082', 'name' => 'LAMA']);
        Item::query()->create(['code' => 'MDM019AZN', 'name' => 'LAMA AZNET']);

        $this->newItem(['name' => 'MODEM FIBERHOME HG6145D2', 'variant' => 'ONT GPON', 'brand' => 'Fiberhome', 'identifier' => 'SN_MAC'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Barang MDM083 · MODEM FIBERHOME HG6145D2 ditambahkan');
        $item = Item::query()->where('code', 'MDM083')->sole();
        $this->assertSame(['ONT GPON', 'Fiberhome'], [$item->variant, $item->brand]);
        $this->assertSame(ItemKind::PERANGKAT_AKTIF, $item->kind);
        $this->assertTrue($item->tracks_serial);
        $this->assertTrue($item->scans_mac);

        // Another series of the same brand is its own item; a differently typed brand joins the existing one.
        $this->newItem(['name' => 'MODEM FIBERHOME HG6145F3', 'variant' => 'ont gpon', 'brand' => 'FIBERHOME', 'identifier' => 'SN'])->assertSessionHasNoErrors();
        $second = Item::query()->where('code', 'MDM084')->sole();
        $this->assertSame(['ONT GPON', 'Fiberhome'], [$second->variant, $second->brand]);
        $this->assertFalse($second->scans_mac);

        // A category that requires SN never takes "dihitung jumlah".
        $this->newItem(['name' => 'MODEM X', 'identifier' => 'JUMLAH'])->assertSessionHasNoErrors();
        $this->assertTrue(Item::query()->where('name', 'MODEM X')->sole()->tracks_serial);

        // Without a prefix the code has to be typed.
        $tools = ItemCategory::query()->where('name', 'Alat Kerja')->sole();
        $this->newItem(['name' => 'TANG CRIMPING', 'item_category_id' => $tools->id, 'identifier' => 'JUMLAH'])->assertSessionHasErrors('code');

        $listed = $this->get('/gudang/barang?sifat=PERANGKAT_AKTIF')->assertOk()->viewData('items');
        $this->assertSame(['MDM083', 'MDM084', 'MDM085'], collect($listed->items())->pluck('code')->all());
    }

    public function test_stock_is_browsed_by_jenis_then_merk_then_model(): void
    {
        $model = fn (string $code, ?string $variant, ?string $brand) => Item::query()->create([
            'code' => $code, 'name' => "MODEM {$code}", 'item_category_id' => $this->modems->id, 'variant' => $variant, 'brand' => $brand, 'tracks_serial' => true,
        ]);
        $model('MDM101', 'ONT GPON', 'Fiberhome');
        $model('MDM102', 'ONT GPON', 'ZTE');
        $model('MDM103', 'ONT XPON', 'Zimmlink');
        $model('MDM104', null, null);
        $this->actingAs($this->warehouse);
        foreach (['MDM101' => ['A1', 'A2'], 'MDM102' => ['B1'], 'MDM103' => ['C1'], 'MDM104' => ['D1']] as $code => $serials) {
            $this->post('/gudang/masuk-sn', ['item' => $code, 'serials' => array_map(fn ($sn) => ['sn' => $sn], $serials)])->assertSessionHasNoErrors();
        }
        $main = Warehouse::main();
        $page = "/gudang/{$main->id}/stok/{$this->modems->id}";

        // The warehouse page starts from category cards.
        $this->get('/gudang')->assertOk()->assertSee($page, false);

        $jenis = $this->get($page)->assertOk()->assertViewHas('level', 'jenis');
        $this->assertSame(['ONT GPON', 'ONT XPON', '-'], $jenis->viewData('groups')->pluck('key')->all());
        $this->assertSame(3, $jenis->viewData('groups')->firstWhere('key', 'ONT GPON')['units']);

        $merk = $this->get("{$page}?jenis=ONT+GPON")->assertOk()->assertViewHas('level', 'merk')->assertSee('Fiberhome');
        $this->assertSame(['Fiberhome', 'ZTE'], $merk->viewData('groups')->pluck('key')->all());

        $this->get("{$page}?jenis=ONT+GPON&merk=Fiberhome")->assertOk()->assertViewHas('level', 'model')
            ->assertSee('MODEM MDM101')->assertSee('A2')->assertDontSee('MODEM MDM102');
        // Items with no jenis sit under "Belum ditentukan".
        $this->get("{$page}?jenis=-")->assertOk()->assertViewHas('level', 'model')->assertSee('MODEM MDM104');
        $this->get("{$page}?semua=1")->assertOk()->assertSee('MODEM MDM103')->assertSee('MODEM MDM104');

        // A technician only browses their own warehouse.
        $this->actingAs($this->userWithRole(Role::TEKNISI))->get($page)->assertForbidden();
    }

    public function test_names_are_read_into_jenis_and_merk(): void
    {
        $classifier = new ItemClassifier;
        $this->assertSame(['ONT XPON', 'Zimmlink'], [$classifier->variant('Modem / ONT', 'MODEM XPON ONU ZIMMLINK HTU'), $classifier->brand('MODEM XPON ONU ZIMMLINK HTU')]);
        $this->assertSame(['ONT GPON', 'ZTE'], [$classifier->variant('Modem / ONT', 'GPON ONT F609 (PON BIRU)'), $classifier->brand('GPON ONT F609 (PON BIRU)')]);
        $this->assertSame(['ONT GPON', 'Huawei'], [$classifier->variant('Modem / ONT', 'HUAWEI HG8245H5'), $classifier->brand('HUAWEI HG8245H5')]);
        $this->assertSame('China Unicom', $classifier->brand('CHINA UNICOM / HUAWEI HS8346R3'));
        $this->assertNull($classifier->variant('Kabel', 'KABEL XPON'));
        $this->assertSame(ItemKind::KABEL, $classifier->kind('Kabel'));
        $this->assertSame(ItemKind::PERANGKAT_AKTIF, $classifier->kind('Router & Mikrotik'));
    }
}
