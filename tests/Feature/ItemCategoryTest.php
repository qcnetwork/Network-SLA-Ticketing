<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class ItemCategoryTest extends TestCase
{
    use RefreshDatabase;

    private User $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = $this->userWithRole(Role::GUDANG);
    }

    private function category(string $name): ItemCategory
    {
        return ItemCategory::query()->where('name', $name)->sole();
    }

    private function stockOf(Item $item, int $quantity): void
    {
        Stock::query()->create(['warehouse_id' => Warehouse::main()->id, 'item_id' => $item->id, 'quantity' => $quantity]);
    }

    public function test_the_default_categories_are_there_and_devices_require_an_sn(): void
    {
        $this->assertCount(count(ItemCategory::DEFAULTS), ItemCategory::all());
        $this->assertTrue($this->category('Switch')->requires_serial);
        $this->assertTrue($this->category('Router & Mikrotik')->requires_serial);
        $this->assertFalse($this->category('Kabel')->requires_serial);
    }

    public function test_an_item_in_a_device_category_always_tracks_its_sn(): void
    {
        $switch = $this->category('Switch');

        // The SN box is unticked (a disabled checkbox is not posted), yet the category requires it.
        $this->actingAs($this->warehouse)->post('/gudang/barang', [
            'code' => 'swt001', 'name' => 'SWITCH TP-LINK 8 PORT', 'unit' => 'unit', 'item_category_id' => $switch->id, 'min_stock' => 2,
        ])->assertSessionHasNoErrors();

        $item = Item::query()->where('code', 'SWT001')->sole();
        $this->assertTrue($item->tracks_serial);
        $this->assertSame($switch->id, $item->item_category_id);
        $this->assertSame(2, $item->min_stock);

        // So it can only come in by scanning.
        $this->post('/gudang/masuk', ['item' => 'SWT001', 'quantity' => 3])->assertSessionHasErrors('item');
        $this->post('/gudang/masuk-sn', ['item' => 'SWT001', 'serials' => [['sn' => 'SW-0001'], ['sn' => 'SW-0002']]])
            ->assertSessionHasNoErrors()->assertSessionHas('success', '2 unit SWT001 masuk Gudang Utama');

        // Nor can it be taken off SN while units sit in the warehouse.
        $this->put("/gudang/barang/{$item->id}", ['code' => 'SWT001', 'name' => $item->name, 'unit' => 'unit', 'active' => 1])
            ->assertSessionHasErrors('tracks_serial');
        $this->assertTrue($item->fresh()->tracks_serial);
    }

    public function test_counted_stock_stays_as_loose_stock_when_an_item_moves_to_sn(): void
    {
        $item = Item::query()->create(['code' => 'MKT001', 'name' => 'MIKROTIK HAP LITE']);
        $this->stockOf($item, 4);

        // The item switches to SN; the 4 units it already counted stay, without SN.
        $this->actingAs($this->warehouse)->put("/gudang/barang/{$item->id}", [
            'code' => 'MKT001', 'name' => $item->name, 'unit' => 'unit', 'active' => 1, 'item_category_id' => $this->category('Router & Mikrotik')->id,
        ])->assertSessionHasNoErrors();
        $this->assertTrue($item->fresh()->tracks_serial);
        $this->get('/gudang?q=MKT001')->assertOk()->assertSee('4 unit stok lama tanpa SN');
    }

    public function test_categories_are_managed(): void
    {
        $this->actingAs($this->warehouse)->post('/gudang/kategori', ['name' => 'CCTV', 'icon' => 'monitor', 'requires_serial' => 1])
            ->assertSessionHasNoErrors();
        $cctv = $this->category('CCTV');
        $this->assertTrue($cctv->requires_serial);
        $this->post('/gudang/kategori', ['name' => 'CCTV', 'icon' => 'monitor'])->assertSessionHasErrors('name');
        $this->post('/gudang/kategori', ['name' => 'Lain', 'icon' => 'not-an-icon'])->assertSessionHasErrors('icon');

        // Requiring an SN later turns it on for the category's items; counted stock stays as loose stock.
        $cable = $this->category('Kabel');
        $drop = Item::query()->create(['code' => 'KBL001', 'name' => 'DROPCORE 1 CORE', 'item_category_id' => $cable->id]);
        $this->stockOf($drop, 100);
        $this->put("/gudang/kategori/{$cable->id}", ['name' => 'Kabel', 'icon' => 'spline', 'requires_serial' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($cable->fresh()->requires_serial);
        $this->assertTrue($drop->fresh()->tracks_serial);

        $empty = Item::query()->create(['code' => 'CAM001', 'name' => 'KAMERA', 'item_category_id' => $this->category('Alat Kerja')->id]);
        $this->put('/gudang/kategori/'.$this->category('Alat Kerja')->id, ['name' => 'Alat Kerja', 'icon' => 'wrench', 'requires_serial' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($empty->fresh()->tracks_serial);

        // A category that still holds items is kept.
        $this->delete("/gudang/kategori/{$cable->id}")->assertSessionHasErrors('category');
        $this->delete("/gudang/kategori/{$cctv->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($cctv);

        $this->actingAs($this->userWithRole(Role::TEKNISI))->post('/gudang/kategori', ['name' => 'X', 'icon' => 'package'])->assertForbidden();
    }

    public function test_imported_items_are_sorted_into_categories(): void
    {
        $workbook = Excel::raw(new class implements FromArray
        {
            public function array(): array
            {
                return [['KODE PRODUK', 'NAMA PRODUK'], ['SWT010', 'SWITCH HUB 5 PORT'], ['PTC001', 'PATCHCORD SC/UPC 3M'], ['KLM001', 'KLEM KABEL'], ['ZZZ001', 'BARANG ANEH']];
            }
        }, ExcelFormat::XLSX);

        $this->actingAs($this->warehouse)->post('/gudang/barang/impor', ['file' => UploadedFile::fake()->createWithContent('kode.xlsx', $workbook)])
            ->assertSessionHasNoErrors();

        $item = fn (string $code) => Item::query()->with('category')->where('code', $code)->sole();
        $this->assertSame('Switch', $item('SWT010')->category->name);
        $this->assertTrue($item('SWT010')->tracks_serial);
        $this->assertSame('Fiber Optik & Pasif', $item('PTC001')->category->name);
        $this->assertSame('Aksesoris & Habis Pakai', $item('KLM001')->category->name);
        $this->assertNull($item('ZZZ001')->category);
        $this->assertFalse($item('ZZZ001')->tracks_serial);
    }

    public function test_the_warehouse_filters_by_category_and_flags_low_stock(): void
    {
        $adaptor = Item::query()->create(['code' => 'ADP001', 'name' => 'ADAPTOR 12 V 1 A', 'item_category_id' => $this->category('Kelistrikan')->id, 'min_stock' => 10]);
        $cable = Item::query()->create(['code' => 'KBL001', 'name' => 'DROPCORE 1 CORE', 'item_category_id' => $this->category('Kabel')->id, 'min_stock' => 5]);
        $patch = Item::query()->create(['code' => 'PTC001', 'name' => 'PATCHCORD SC', 'item_category_id' => $this->category('Fiber Optik & Pasif')->id, 'min_stock' => 3]);
        $this->stockOf($adaptor, 4);
        $this->stockOf($cable, 200);

        // The warehouse starts from category cards; the low-stock card names the items.
        $this->actingAs($this->warehouse)->get('/gudang')->assertOk()
            ->assertSee('Kelistrikan')->assertSee('Kabel')
            ->assertSee('Stok Menipis (2)')->assertSee('PATCHCORD SC');
        $this->get('/gudang/'.Warehouse::main()->id.'/stok/'.$this->category('Kelistrikan')->id)->assertOk()
            ->assertSee('ADAPTOR 12 V 1 A')->assertSee('Menipis (min 10)');

        // The receive form's item list and the low-stock card still name every item, so check the stock table itself.
        $stock = $this->get('/gudang?kategori='.$this->category('Kabel')->id)->assertOk()->viewData('stocks');
        $this->assertSame(['KBL001'], collect($stock->items())->pluck('item.code')->all());

        $items = $this->get('/gudang/barang?kategori='.$this->category('Kelistrikan')->id)->assertOk()->viewData('items');
        $this->assertSame(['ADP001'], collect($items->items())->pluck('code')->all());
    }

    public function test_category_guesses(): void
    {
        $this->assertSame('Modem / ONT', ItemCategory::guessName('MDM031', 'FIBERHOME HG6145D2'));
        $this->assertSame('Router & Mikrotik', ItemCategory::guessName('RTR001', 'MIKROTIK RB951'));
        $this->assertSame('Kabel', ItemCategory::guessName('KBL001', 'KABEL SFP'), 'a cable, not an SFP module');
        $this->assertSame('OLT & SFP', ItemCategory::guessName('SFP001', 'SFP 1,25G 20KM NUFIBER'));
        $this->assertSame('Fiber Optik & Pasif', ItemCategory::guessName('ADS001', 'ADAPTER SC/UPC'));
        $this->assertSame('Kelistrikan', ItemCategory::guessName('ADP001', 'ADAPTOR 12 V 1 A'));
        $this->assertSame('Aksesoris & Habis Pakai', ItemCategory::guessName('CTI001', 'CABLE TIE 20 CM'));
        $this->assertNull(ItemCategory::guessName('XYZ001', 'FRONT PANEL'));
    }

    public function test_an_unused_item_can_be_deleted(): void
    {
        $item = Item::query()->create(['code' => 'TYPO01', 'name' => 'SALAH INPUT']);

        $this->actingAs($this->warehouse)->delete("/gudang/barang/{$item->id}")->assertSessionHasNoErrors();

        $this->assertModelMissing($item);
    }

    public function test_an_item_with_stock_or_history_cannot_be_deleted(): void
    {
        $stocked = Item::query()->create(['code' => 'KBL009', 'name' => 'DROPCORE']);
        $this->stockOf($stocked, 5);

        $this->actingAs($this->warehouse)->delete("/gudang/barang/{$stocked->id}")->assertSessionHasErrors('item');

        $this->assertModelExists($stocked);
    }

    public function test_a_technician_cannot_delete_items(): void
    {
        $item = Item::query()->create(['code' => 'TYPO02', 'name' => 'SALAH INPUT']);

        $this->actingAs($this->userWithRole(Role::TEKNISI))->delete("/gudang/barang/{$item->id}")->assertForbidden();

        $this->assertModelExists($item);
    }
}
