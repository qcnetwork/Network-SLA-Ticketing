<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Item;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Services\InventoryService;
use App\Services\SheetSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SheetSyncTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://script.google.com/macros/s/AKfyTEST123/exec';

    private function receiveAdaptors(int $quantity): void
    {
        $item = Item::query()->firstOrCreate(['code' => 'ADP001'], ['name' => 'ADAPTOR 12 V 1 A', 'unit' => 'pcs']);
        app(InventoryService::class)->receive($item, $quantity, $this->userWithRole(Role::GUDANG), 'Paket A');
    }

    public function test_nothing_is_sent_until_a_sheet_is_set_up(): void
    {
        Http::fake();
        $this->receiveAdaptors(5);

        $this->assertSame(0, app(SheetSync::class)->push());
        Http::assertNothingSent();
        $this->assertNull(StockMovement::query()->sole()->sheet_synced_at);
    }

    public function test_movements_and_touched_stock_are_sent_once(): void
    {
        Setting::write(['sheets_url' => self::URL, 'sheets_secret' => 'rahasia']);
        $this->receiveAdaptors(5);
        $this->receiveAdaptors(3);
        Http::fake([self::URL => Http::response(['ok' => true])]);

        $this->assertSame(2, app(SheetSync::class)->push());

        Http::assertSent(function (Request $request) {
            $movement = $request['movements'][0];

            return $request['secret'] === 'rahasia'
                && count($request['movements']) === 2
                && $movement['kode'] === 'ADP001' && $movement['jumlah'] === 5 && $movement['ke'] === 'Gudang Utama' && $movement['keterangan'] === 'Paket A'
                && $request['stock'] === [['gudang' => 'Gudang Utama', 'kode' => 'ADP001', 'barang' => 'ADAPTOR 12 V 1 A', 'kategori' => '', 'stok' => 8, 'satuan' => 'pcs', 'sn' => '']];
        });
        $this->assertSame(0, app(SheetSync::class)->pendingCount());

        // Already in the sheet: the next push has nothing to send.
        $this->assertSame(0, app(SheetSync::class)->push());
        Http::assertSentCount(1);
    }

    public function test_a_failed_send_is_kept_for_the_next_try(): void
    {
        Setting::write(['sheets_url' => self::URL, 'sheets_secret' => 'salah']);
        $this->receiveAdaptors(5);
        Http::fake([self::URL => Http::response(['ok' => false, 'error' => 'Kode rahasia tidak cocok'])]);

        $this->assertSame(0, app(SheetSync::class)->push());
        $this->assertSame(1, app(SheetSync::class)->pendingCount());

        $this->artisan('inventory:sync-sheet')->expectsOutputToContain('1 belum')->assertFailed();
    }

    public function test_the_supervisor_sets_up_the_sheet_and_runs_a_full_sync(): void
    {
        $spv = $this->userWithRole(Role::SPV);
        $this->receiveAdaptors(5);

        $this->actingAs($spv)->put('/settings/sheets', ['sheets_url' => 'https://contoh.com/hook'])->assertSessionHasErrors('sheets_url');
        $this->put('/settings/sheets', ['sheets_url' => self::URL])->assertSessionHasNoErrors();
        $this->assertSame(40, strlen((string) Setting::read('sheets_secret')));
        $this->get('/settings')->assertOk()->assertSee(Setting::read('sheets_secret'))->assertSee('1 mutasi belum terkirim');

        Http::fake([self::URL => Http::response(['ok' => true])]);
        $this->post('/settings/sheets-sync')->assertSessionHasNoErrors()->assertSessionHas('success');
        Http::assertSent(fn (Request $request) => $request['ping'] ?? false);
        Http::assertSent(fn (Request $request) => ($request['snapshot'] ?? false) && count($request['stock']) === 1);
        $this->assertSame(0, app(SheetSync::class)->pendingCount());

        $this->actingAs($this->userWithRole(Role::GUDANG))->put('/settings/sheets', ['sheets_url' => self::URL])->assertForbidden();
    }
}
