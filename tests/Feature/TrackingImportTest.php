<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Item;
use App\Models\SerialUnit;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\TrackingImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class TrackingImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = ['NO', 'TANGGAL MASUK', 'TANGGAL PENGAMBILAN', 'NAMA BARANG', 'SN', 'MAC', 'TEKNISI', 'PEMASANGAN ALAT', 'STATUS', 'MIGRASI ALAT', 'ERROR/RETUR', 'KETERANGAN'];

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        // Laid out like the warehouse's TRACKING ALAT workbook: a sheet per device, blocks per delivery.
        $sheets = [
            'MODEM ML212X MYLINK' => [
                ['PAKET MASUK TGL 21 JANUARI 2026', '', 46043, 'MYLINK', '3PCS'],
                self::HEADER,
                [1, 46043, '', 'ML212X MYLINK', 'MLNK0001', '94:D2:BC:31:33:37', '', '', '', '', '', ''],
                [2, 46043, 46047, 'ML212X MYLINK', 'MLNK0002', '94D2BC313338', 'TIM BAIM', '', '', '', '', ''],
                [3, 46043, 46050, '', 'MLNK0003', '', 'TIM DIAN > TIM RASYID', 'Siti Jetaklengkong (2/2/2026)', 'PEMASANGAN BARU', '', '', ''],
                [4, 46043, 46060, 'ML212X MYLINK', 'MLNK0004', '', 'WANTO', 'Budi Kajen', 'MIGRASI', 'ZTE F609', 'RETUR', ''],
                [5, 46043, 46061, 'ML212X MYLINK', 'MLNK0005', '', 'CPTO', 'Ani Sragi', 'GANTI ALAT/PINJAMI', '', 'KEMBALI KE KANTOR 16/9/2026', ''],
                [6, 46043, '', 'ML212X MYLINK', 'PROJECT MJ NET', '', '', '', '', '', '', ''],
                [7, 46043, '', 'ML212X MYLINK', 'APPSCANNED', '', 'TIM BAIM', '', '', '', '', ''],
            ],
            'CEK MODEM' => [
                self::HEADER,
                // Seen again later: the newer row says where it is now.
                [1, 46043, 46100, 'ML212X MYLINK', 'MLNK0001', '', 'TIM BAIM (WANTO)', '', '', '', '', ''],
                [2, 46100, '', 'HSAIRPO XPW300', 'ZICG1748B93E', '987DDD48B93E', '', '', '', '', '', ''],
            ],
        ];
        $this->path = tempnam(sys_get_temp_dir(), 'tracking').'.xlsx';
        file_put_contents($this->path, Excel::raw(new class($sheets) implements Export, WithMultipleSheets
        {
            public function __construct(private array $sheets) {}

            public function sheets(): array
            {
                return collect($this->sheets)->map(fn ($rows, $title) => new class($rows, $title) implements FromArray, WithTitle
                {
                    public function __construct(private array $rows, private string $title) {}

                    public function array(): array
                    {
                        return $this->rows;
                    }

                    public function title(): string
                    {
                        return $this->title;
                    }
                })->values()->all();
            }
        }, ExcelFormat::XLSX));

        Item::query()->create(['code' => 'MDM024', 'name' => 'MODEM ML212X MYLINK']);
        Item::query()->create(['code' => 'MDM030', 'name' => 'MODEM XPON ONU ZIMMLINK MEGADATA', 'tracks_serial' => true]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function unit(string $serial): SerialUnit
    {
        return SerialUnit::query()->with('warehouse')->where('serial_number', $serial)->sole();
    }

    public function test_units_land_where_the_sheet_says_they_are(): void
    {
        $baim = $this->userWithRole(Role::TEKNISI, ['name' => 'BAIM']);
        $rasyid = $this->userWithRole(Role::TEKNISI, ['name' => 'RASYID']);
        $mylink = Item::query()->where('code', 'MDM024')->sole();
        SerialUnit::query()->create(['item_id' => $mylink->id, 'serial_number' => 'APPSCANNED', 'warehouse_id' => Warehouse::main()->id]);

        $report = app(TrackingImport::class)->import($this->path);

        $this->assertSame(['rows' => 9, 'units' => 6, 'invalid' => 1, 'duplicates' => 1, 'kept' => 1], array_intersect_key($report, array_flip(['rows', 'units', 'invalid', 'duplicates', 'kept'])));
        $this->assertSame(['MDM031 HSAIRPO XPW300'], $report['items']);
        $this->assertTrue($mylink->fresh()->tracks_serial);

        // Re-listed later with Baim: the newest row wins.
        $this->assertSame(Warehouse::of($baim)->id, $this->unit('MLNK0001')->warehouse_id);
        $this->assertSame('MLNK0001', $this->unit('MLNK0001')->serial_number);
        $this->assertSame(Warehouse::of($baim)->id, $this->unit('MLNK0002')->warehouse_id);
        $this->assertSame('94D2BC313338', $this->unit('MLNK0002')->mac);

        $installed = $this->unit('MLNK0003');
        $this->assertNull($installed->warehouse_id);
        $this->assertSame('Siti Jetaklengkong (2/2/2026)', $installed->installed_to);
        $this->assertSame('2026-01-28', $installed->taken_at->toDateString());
        $this->assertSame('Terpasang · Siti Jetaklengkong (2/2/2026)', $installed->location());
        $this->assertSame($mylink->id, $installed->item_id, 'a row without a name takes the sheet\'s device');

        $returned = $this->unit('MLNK0004');
        $this->assertNull($returned->warehouse_id);
        $this->assertSame('Keluar stok · RETUR', $returned->location());
        $this->assertSame('ZTE F609', $returned->replaced_device);
        $this->assertSame(Warehouse::main()->id, $this->unit('MLNK0005')->warehouse_id);
        $this->assertSame(Warehouse::main()->id, $this->unit('ZICG1748B93E')->warehouse_id);

        // Technicians without an account get an inactive one; "CPTO" is the same person as "CIPTO".
        $this->assertSame(['WANTO', 'CIPTO'], $report['technicians']);
        $this->assertFalse(User::query()->where('name', 'WANTO')->sole()->active);
        $this->assertSame(Role::TEKNISI, User::query()->where('name', 'CIPTO')->sole()->role);
        $this->assertNull(Warehouse::query()->where('user_id', $rasyid->id)->first(), 'the installed unit never sat with Rasyid');

        $stock = fn (Warehouse $warehouse) => (int) Stock::query()->where('warehouse_id', $warehouse->id)->where('item_id', $mylink->id)->value('quantity');
        $this->assertSame(2, $stock(Warehouse::of($baim)));
        $this->assertSame(2, $stock(Warehouse::main()), 'MLNK0005 back at the office plus the unit scanned in the app');

        // Importing again changes nothing and creates nothing new.
        $again = app(TrackingImport::class)->import($this->path);
        $this->assertSame([], $again['items']);
        $this->assertSame([], $again['technicians']);
        $this->assertSame(7, SerialUnit::query()->count());
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $report = app(TrackingImport::class)->import($this->path, dryRun: true);

        $this->assertSame(6 + 1, $report['units']);
        $this->assertSame(0, SerialUnit::query()->count());
        $this->assertSame(0, User::query()->count());
        $this->assertFalse(Item::query()->where('code', 'MDM031')->exists());
    }

    public function test_holder_names_are_read_from_free_text(): void
    {
        $import = app(TrackingImport::class);

        $this->assertSame('RASYID', $import->holderName('TIM DIAN > TIM RASYID'));
        $this->assertSame('BAIM', $import->holderName('TIM CIPTO-}BAIM'));
        $this->assertSame('BAIM', $import->holderName('TIM BAIM (WANTO)'));
        $this->assertSame('BAIM', $import->holderName('TIM BAIM - WANTO'));
        $this->assertSame('ALI', $import->holderName('ALI/CIPTO'));
        $this->assertSame('JAFAR', $import->holderName('TIM BAIM>T.JAKFAR'));
        $this->assertSame('SIDOL', $import->holderName('TIM SIDOEL'));
        $this->assertSame('ROSED', $import->holderName('JR ROSID'));
        $this->assertSame('PT IKM', $import->holderName('PT. IKM'));
        $this->assertNull($import->holderName('DIRETUR KE SELLER (DUNIA NET) 9 DESEMBER 2023'));
        $this->assertNull($import->holderName('EROR'));
        $this->assertNull($import->holderName(''));
    }

    public function test_the_command_reports_the_import(): void
    {
        $this->artisan('inventory:import-tracking', ['file' => $this->path, '--dry-run' => true])
            ->expectsOutputToContain('Dry run selesai')
            ->assertSuccessful();
        $this->artisan('inventory:import-tracking', ['file' => 'tidak-ada.xlsx'])->assertFailed();
    }
}
