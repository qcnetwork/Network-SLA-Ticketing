<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\CustomerImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class CustomerImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = ['No', 'ID', 'Account', 'ID Pelanggan', 'Tanggal Registrasi', 'Nama Pelanggan', 'Alamat', 'Tlp', 'Tlp2', 'Status Langganan', 'Titik Koordinat Lokasi'];

    /** The billing system's ".xls" export: an HTML table, phone cells included as text. */
    private function htmlExport(array $rows): string
    {
        $html = "<div class=\"card\">\n<table><thead><tr>".implode('', array_map(fn ($h) => "<th>{$h}</th>", self::HEADER)).'</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>'.implode('', array_map(fn ($v) => "<td>\n\t\t{$v}\t\t</td>", $row)).'</tr>';
        }
        $path = tempnam(sys_get_temp_dir(), 'pelanggan').'.xls';
        file_put_contents($path, $html.'</tbody></table></div>');

        return $path;
    }

    /** The .xlsx export: a title row and blank rows above the header. */
    private function xlsxExport(array $rows): string
    {
        $grid = [['Data Seluruh Pelanggan'], [], [], self::HEADER, ...$rows];
        $path = tempnam(sys_get_temp_dir(), 'pelanggan').'.xlsx';
        file_put_contents($path, Excel::raw(new class($grid) implements FromArray
        {
            public function __construct(private array $grid) {}

            public function array(): array
            {
                return $this->grid;
            }
        }, ExcelFormat::XLSX));

        return $path;
    }

    public function test_it_imports_both_export_formats_into_the_directory(): void
    {
        $html = $this->htmlExport([
            [1, 1001, 3847, '2026.001.1.1.01', '08-November-2026', '  BUDI  CONTOH SATU ', 'Jl. Contoh No. 1', '08111000001', '', 'On', '-6,9512340, 109,6312340'],
            [2, 1002, 3847, '2026.002.1.1.02', '08-November-2026', 'Tanpa Nomor', 'Desa Contoh', '0', '81100000002', 'On', 'https://maps.app.goo.gl/abc'],
            [3, 1003, 3847, '', '08-November-2026', 'Tanpa ID', '', '0812', '', 'On', ''],
        ]);
        $xlsx = $this->xlsxExport([
            [1, 2001, 6000, 2600000001, 46335, 'Andi Contoh Dua', 'Perum Contoh Blok A', '08122000003', '', 'On', '-6.9500000, 109.6500000'],
            [2, 2002, 6000, '2026.0003.1.1.03', 46335, 'Siti Contoh Tiga', 'Desa Contoh', '', '', 'On', '6°59\'00.0"S 109°38\'00.0"E'],
        ]);

        $report = app(CustomerImport::class)->import([$html, $xlsx]);

        $this->assertSame(['rows' => 5, 'skipped' => 1, 'created' => 4, 'filled' => 0, 'unchanged' => 0, 'no_phone' => 1, 'no_location' => 1], $report);

        $budi = Customer::query()->withCode('2026.001.1.1.01')->firstOrFail();
        $this->assertSame('BUDI CONTOH SATU', $budi->name);
        $this->assertSame('628111000001', $budi->phone);
        $this->assertSame([-6.951234, 109.631234], [$budi->latitude, $budi->longitude]);

        // A stub in Tlp falls back to Tlp2, which had lost its leading 0.
        $this->assertSame('6281100000002', Customer::query()->withCode('2026.002.1.1.02')->value('phone'));
        $this->assertNull(Customer::query()->withCode('2026.002.1.1.02')->value('latitude'));

        $this->assertSame('628122000003', Customer::query()->withCode('2600000001')->value('phone'));
        $siti = Customer::query()->withCode('2026.0003.1.1.03')->firstOrFail();
        $this->assertSame('', $siti->phone);
        $this->assertEqualsWithDelta(-6.9833, $siti->latitude, 0.0001);
    }

    public function test_existing_customers_keep_their_details_and_only_gain_what_is_missing(): void
    {
        Customer::query()->create(['code' => '2600000001', 'name' => 'Andi (dari tiket)', 'phone' => '6281111111111', 'address' => null]);
        $xlsx = $this->xlsxExport([
            [1, 2001, 6000, 2600000001, 46335, 'Andi Contoh Dua', 'Perum Contoh Blok A', '08122000003', '', 'On', '-6.96, 109.66'],
        ]);

        $report = app(CustomerImport::class)->import([$xlsx]);
        app(CustomerImport::class)->import([$xlsx]);

        $this->assertSame(1, $report['filled']);
        $this->assertSame(1, Customer::query()->count());
        $customer = Customer::query()->sole();
        $this->assertSame('Andi (dari tiket)', $customer->name);
        $this->assertSame('6281111111111', $customer->phone);
        $this->assertSame('Perum Contoh Blok A', $customer->address);
        $this->assertSame(-6.96, $customer->latitude);
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $xlsx = $this->xlsxExport([[1, 2001, 6000, 2600000001, 46335, 'Andi', '', '08122000003', '', 'On', '']]);

        $this->artisan('customers:import', ['files' => [$xlsx], '--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Customer::query()->count());
    }
}
