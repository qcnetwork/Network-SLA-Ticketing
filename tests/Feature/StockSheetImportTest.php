<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Models\Item;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockSheetImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class StockSheetImportTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        // Laid out like the "Sisa Stok" sheet of the warehouse admin workbook.
        $rows = [
            ['', 'LAPORAN SISA STOCK'],
            ['', 'NO', 'KODE', 'NAMA PRODUK', 'SATUAN', 'STOK', '', '', '', '', 'NOMINAL STOK'],
            ['', '', 'PRODUK', '', '', 'MINIMUM', 'AWAL', 'MASUK', 'KELUAR', 'AKHIR'],
            ['', 1, ' ADP001 ', ' ADAPTOR 12 V 1 A ', ' PCS ', 5, 35, 889, 424, 500],
            ['', 2, 'KBL009', 'KABEL FO 1 CORE COMMFIBER', 'ROLL', 0, 100, 121, 175, '#VALUE!'],
            ['', 3, 'SPL004', 'SPLITER 1:16 (KASET)', 'PCS', 0, 15, 0, 16, -1],
            ['', 4, 'MDM036', 'MODEM HSAIRPO XPW300', 'PCS', 0, 10, 5, 3, 12],
        ];
        $this->path = tempnam(sys_get_temp_dir(), 'sisa').'.xlsx';
        file_put_contents($this->path, Excel::raw(new class($rows) implements FromArray, WithTitle
        {
            public function __construct(private array $rows) {}

            public function array(): array
            {
                return $this->rows;
            }

            public function title(): string
            {
                return 'Sisa Stok';
            }
        }, ExcelFormat::XLSX));

        $this->actor = $this->userWithRole(Role::SPV);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function mainStock(string $code): int
    {
        return (int) Stock::query()->where('warehouse_id', Warehouse::main()->id)
            ->whereHas('item', fn ($query) => $query->where('code', $code))->value('quantity');
    }

    public function test_counted_items_get_their_closing_stock_and_devices_wait_for_sns(): void
    {
        $report = app(StockSheetImport::class)->import($this->path, $this->actor);

        $this->assertSame(['ADP001', 'KBL009', 'SPL004', 'MDM036'], $report['created']);
        $this->assertSame(500, $this->mainStock('ADP001'));
        $this->assertSame(46, $this->mainStock('KBL009'), 'AWAL + MASUK - KELUAR when AKHIR is an error');
        $this->assertSame(0, $this->mainStock('SPL004'), 'a negative balance becomes zero');
        $this->assertSame(['SPL004 SPLITER 1:16 (KASET) (-1)'], $report['negative']);

        $adaptor = Item::query()->where('code', 'ADP001')->sole();
        $this->assertSame(['ADAPTOR 12 V 1 A', 'pcs', 5], [$adaptor->name, $adaptor->unit, $adaptor->min_stock]);

        // The modem tracks SNs, so it stays empty until its units are scanned in.
        $this->assertTrue(Item::query()->where('code', 'MDM036')->sole()->tracks_serial);
        $this->assertSame(0, $this->mainStock('MDM036'));
        $this->assertSame(['MDM036 MODEM HSAIRPO XPW300 = 12'], $report['devices']);

        $this->assertSame(2, StockMovement::query()->where('type', StockMovementType::MASUK)->count());

        // Run again after the adaptor stock changed in the app: only the difference is recorded.
        app(\App\Services\InventoryService::class)->setMainStock($adaptor, 480, $this->actor);
        $again = app(StockSheetImport::class)->import($this->path, $this->actor);
        $this->assertSame([], $again['created']);
        $this->assertSame(500, $this->mainStock('ADP001'));
        $this->assertSame(1, $again['unchanged'], 'KBL009 already matched');
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $this->artisan('inventory:import-stock', ['file' => $this->path, '--dry-run' => true])
            ->expectsOutputToContain('Dry run selesai')
            ->assertSuccessful();

        $this->assertSame(0, Item::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
    }
}
