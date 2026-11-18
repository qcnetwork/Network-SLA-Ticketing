<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Enums\TicketStatus;
use App\Enums\UsagePurpose;
use App\Models\Item;
use App\Models\MaterialUsage;
use App\Models\SerialUnit;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class MaterialUsageTest extends TestCase
{
    use RefreshDatabase;

    private User $warehouse;

    private User $technician;

    private Warehouse $own;

    private Item $cable;

    private Item $modem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = $this->userWithRole(Role::GUDANG);
        $this->technician = $this->userWithRole(Role::TEKNISI, ['name' => 'Dian']);
        $this->own = Warehouse::of($this->technician);
        $this->cable = Item::query()->create(['code' => 'KBL001', 'name' => 'KABEL FO 1 CORE (Meter)', 'unit' => 'meter']);
        $this->modem = Item::query()->create(['code' => 'MDM001', 'name' => 'MODEM ZTE F670L', 'tracks_serial' => true]);
        Stock::query()->create(['warehouse_id' => $this->own->id, 'item_id' => $this->cable->id, 'quantity' => 500]);
        Stock::query()->create(['warehouse_id' => $this->own->id, 'item_id' => $this->modem->id, 'quantity' => 1]);
        SerialUnit::query()->create(['item_id' => $this->modem->id, 'serial_number' => 'ZTEGD5D06F27', 'mac' => 'F42D06F5757C', 'warehouse_id' => $this->own->id]);
    }

    private function held(Item $item, ?Warehouse $warehouse = null): int
    {
        return (int) Stock::query()->where('warehouse_id', ($warehouse ?? $this->own)->id)->where('item_id', $item->id)->value('quantity');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'warehouse_id' => $this->own->id,
            'used_at' => today()->toDateString(),
            'purpose' => UsagePurpose::PEMASANGAN_BARU->value,
            'project' => 'PSB POP KAJEN',
            'location' => 'Rika Sucianti Jagung',
            'team' => 'DIAN - AMIN',
            'removed_device' => 'PROMO FREE',
            'notes' => 'ALAT PINJAMI',
            'lines' => [
                ['item' => 'KBL001', 'quantity' => 75],
                ['item' => 'MDM001', 'serials' => ['ztegd5d06f27']],
            ],
        ], $overrides);
    }

    public function test_the_warehouse_records_where_material_went_and_stock_leaves(): void
    {
        $this->actingAs($this->warehouse)->get('/gudang/pemakaian/baru')->assertOk()->assertSee('Gudang Dian')->assertSee('KBL001');

        $this->post('/gudang/pemakaian', $this->payload())->assertSessionHasNoErrors();

        $usage = MaterialUsage::query()->sole();
        $this->assertSame(UsagePurpose::PEMASANGAN_BARU, $usage->purpose);
        $this->assertSame('Rika Sucianti Jagung', $usage->location);
        $this->assertCount(2, $usage->lines);
        $this->assertSame('F42D06F5757C', $usage->lines->firstWhere('serial_number', 'ZTEGD5D06F27')->mac);
        $this->assertSame(425, $this->held($this->cable));
        $this->assertSame(0, $this->held($this->modem));
        $this->assertStringContainsString('Terpakai', SerialUnit::query()->sole()->location());
        $this->assertSame(2, StockMovement::query()->where('type', StockMovementType::PAKAI)->where('material_usage_id', $usage->id)->count());

        $this->get(route('usages.show', $usage))->assertOk()->assertSee('ZTEGD5D06F27')->assertSee('PSB POP KAJEN');
        $this->get('/gudang/pemakaian?q=ztegd5d')->assertOk()->assertSee('Rika Sucianti Jagung');
        $this->get('/gudang/pemakaian?keperluan=GANTI_ALAT')->assertOk()->assertDontSee('Rika Sucianti Jagung');
        $this->get('/gudang/pemakaian/export')->assertOk()->assertDownload();

        // More than the technician holds is refused, and nothing is half-saved.
        $this->post('/gudang/pemakaian', $this->payload(['location' => 'Lain', 'lines' => [['item' => 'KBL001', 'quantity' => 999]]]))
            ->assertSessionHasErrors('lines');
        $this->assertSame(1, MaterialUsage::query()->count());
    }

    public function test_a_technician_records_from_their_own_stock_only(): void
    {
        $other = Warehouse::of($this->userWithRole(Role::TEKNISI, ['name' => 'Rasyid']));
        Stock::query()->create(['warehouse_id' => $other->id, 'item_id' => $this->cable->id, 'quantity' => 10]);

        $this->actingAs($this->technician)->get('/gudang/pemakaian/baru')->assertOk()->assertSee('Gudang Dian')->assertDontSee('Gudang Rasyid');
        $this->post('/gudang/pemakaian', $this->payload(['warehouse_id' => $other->id, 'lines' => [['item' => 'KBL001', 'quantity' => 1]]]))->assertForbidden();
        // Scans are only checked against their own stock.
        $this->getJson('/gudang/cek-sn?sn=ZTEGD5D06F27&warehouse='.$other->id)->assertForbidden();
        $this->getJson('/gudang/cek-sn?sn=ZTEGD5D06F27&warehouse='.$this->own->id)->assertOk();

        $this->post('/gudang/pemakaian', $this->payload())->assertSessionHasNoErrors();
        $this->assertSame(425, $this->held($this->cable));
        $this->assertSame(10, $this->held($this->cable, $other));

        $hidden = MaterialUsage::query()->create(['used_at' => today(), 'purpose' => UsagePurpose::LAINNYA, 'location' => 'Tempat Rasyid']);
        $hidden->lines()->create(['item_name' => 'KABEL', 'warehouse_id' => $other->id, 'quantity' => 1]);
        $this->get('/gudang/pemakaian')->assertOk()->assertSee('Rika Sucianti Jagung')->assertDontSee('Tempat Rasyid');
        $this->get(route('usages.show', $hidden))->assertForbidden();
        // Only the warehouse can undo a usage.
        $this->post(route('usages.cancel', MaterialUsage::query()->where('location', 'Rika Sucianti Jagung')->sole()), ['cancel_reason' => 'x'])->assertForbidden();
    }

    public function test_cancelling_a_usage_puts_the_stock_and_the_device_back(): void
    {
        $this->actingAs($this->warehouse)->post('/gudang/pemakaian', $this->payload())->assertSessionHasNoErrors();
        $usage = MaterialUsage::query()->sole();

        $this->post(route('usages.cancel', $usage), ['cancel_reason' => 'Salah pilih teknisi'])->assertSessionHasNoErrors();

        $this->assertTrue($usage->refresh()->isCancelled());
        $this->assertSame(500, $this->held($this->cable));
        $this->assertSame(1, $this->held($this->modem));
        $this->assertSame($this->own->id, SerialUnit::query()->sole()->warehouse_id);
        $this->get('/gudang/pemakaian')->assertDontSee('Rika Sucianti Jagung');
        $this->get('/gudang/pemakaian?status=batal')->assertSee('Rika Sucianti Jagung');
        $this->post(route('usages.cancel', $usage), ['cancel_reason' => 'lagi'])->assertSessionHasErrors('cancel_reason');
    }

    public function test_material_approved_from_a_ticket_lands_in_the_usage_log(): void
    {
        $ticket = Ticket::factory()->status(TicketStatus::PERBAIKAN)->assignedTo($this->technician)->create(['customer_name' => 'Pak Harun']);
        $inventory = app(InventoryService::class);
        $inventory->requestForTicket($ticket, [['technician_id' => $this->technician->id, 'code' => 'KBL001', 'quantity' => 30]], $this->technician);

        $inventory->approveRequest($ticket->materialRequests()->sole(), $this->warehouse);

        $usage = MaterialUsage::query()->sole();
        $this->assertSame(UsagePurpose::FREE_PERBAIKAN, $usage->purpose);
        $this->assertSame($ticket->id, $usage->ticket_id);
        $this->assertStringContainsString('Pak Harun', $usage->location);
        $this->assertSame(30, $usage->lines->sole()->quantity);
        $this->assertSame(470, $this->held($this->cable));

        // It cannot be undone from the log; the warehouse corrects it with a return instead.
        $this->actingAs($this->warehouse)->post(route('usages.cancel', $usage), ['cancel_reason' => 'x'])->assertSessionHasErrors('cancel_reason');
    }

    public function test_the_old_sheet_is_imported_as_history_without_touching_stock(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('PEMASANGAN ALAT');
        $sheet->fromArray([
            ['LAPORAN PEMASANGAN'],
            ['NO', 'TANGGAL', 'TEKNISI', 'CEK LIST', 'PROJECT', 'NAMA BARANG', 'REPORT', 'QTY', 'STOK TEKNISI', 'CATATAN', 'SN', 'MAC', 'LOKASI PEMASANGAN', 'ALAT CABUTAN', 'CATATAN TAMBAHAN'],
            [1, 46100, 'ATOK', 1, 'FREE PENGEMBANGAN', 'KABEL LAN', null, 23, 'ATOK', null, null, null, 'PARKIRAN GEMBONG'],
            [2, 46196, 'DIAN', 1, 'PEMASANGAN BARU', 'KABEL FO 1 CORE (Meter)', 'PSB POP KAJEN', 75, 'DIAN', null, null, null, 'RIKA SUCIANTI JAGUNG', 'PROMO FREE', 'ALAT PINJAMI'],
            [3, 46196, 'DIAN', 1, 'PEMASANGAN BARU', 'MODEM ZTE F670L', 'PSB POP KAJEN', 1, 'DIAN', null, 'ZTEGD5D06F27', 'F42D06F5757C', 'RIKA SUCIANTI JAGUNG', 'PROMO FREE', 'ALAT PINJAMI'],
            [4, 46197, 'JR ROSED', 1, 'GANTI ALAT/PINJAMI/ TUKAR', 'MODEM HSAIRPO', null, 1, 'JR ROSED', null, 'ZICG277FECD4', null, null],
        ]);
        // A formula like the sheet's =UPPER(P12), set explicitly: an export earlier in the run may leave a string-only value binder behind.
        $sheet->setCellValueExplicit('M6', '=UPPER("iwan turono kuripan")', DataType::TYPE_FORMULA);
        $path = tempnam(sys_get_temp_dir(), 'usage').'.xlsx';
        (new Xlsx($book))->save($path);

        $this->artisan('gudang:impor-pemakaian', ['file' => $path, '--limit' => 3])->assertSuccessful();

        // The latest three rows, as two jobs; the oldest row is left out.
        $this->assertSame(2, MaterialUsage::query()->count());
        $install = MaterialUsage::query()->where('location', 'RIKA SUCIANTI JAGUNG')->sole();
        $this->assertSame(UsagePurpose::PEMASANGAN_BARU, $install->purpose);
        $this->assertSame('PSB POP KAJEN', $install->project);
        $this->assertSame('DIAN', $install->holder_name);
        $this->assertTrue($install->isImported());
        $this->assertCount(2, $install->lines);
        $this->assertSame($this->cable->id, $install->lines->firstWhere('item_name', 'KABEL FO 1 CORE (Meter)')->item_id);
        $this->assertSame(UsagePurpose::GANTI_ALAT, MaterialUsage::query()->where('location', 'IWAN TURONO KURIPAN')->sole()->purpose);
        $this->assertSame(500, $this->held($this->cable));
        $this->assertSame($this->own->id, SerialUnit::query()->sole()->warehouse_id);

        $this->artisan('gudang:impor-pemakaian', ['file' => $path, '--limit' => 3])->assertSuccessful();
        $this->assertSame(2, MaterialUsage::query()->count());
        @unlink($path);
    }
}
