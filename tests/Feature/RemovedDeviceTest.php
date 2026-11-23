<?php

namespace Tests\Feature;

use App\Enums\RemovedDeviceStatus;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Enums\TicketStatus;
use App\Models\CustomerMaterial;
use App\Models\Item;
use App\Models\RemovedDevice;
use App\Models\SerialUnit;
use App\Models\StockMovement;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class RemovedDeviceTest extends TestCase
{
    use RefreshDatabase;

    private User $technician;

    private User $warehouse;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->technician = $this->userWithRole(Role::TEKNISI, ['name' => 'Tim Baim']);
        $this->warehouse = $this->userWithRole(Role::GUDANG, ['name' => 'Admin Gudang']);
        $this->ticket = Ticket::factory()->status(TicketStatus::PERBAIKAN)->assignedTo($this->technician)
            ->create(['repair_started_at' => now(), 'customer_id' => 'CUST-1', 'customer_name' => 'Bu Sari']);
    }

    private function report(User $by, array $data)
    {
        return $this->actingAs($by)->post("/tickets/{$this->ticket->ticket_number}/actions/LAPOR_PERBAIKAN_SELESAI", ['notes' => 'Ganti modem'] + $data);
    }

    private function reportModem(): RemovedDevice
    {
        $this->report($this->technician, ['removed_any' => 'ya', 'removed_devices' => [
            ['name' => 'Modem ZTE F609', 'serial' => 'zteg 1234', 'mac' => 'aa:bb:cc:dd:ee:ff', 'condition' => 'RUSAK', 'quantity' => 1],
        ]])->assertSessionHasNoErrors();

        return RemovedDevice::query()->sole();
    }

    public function test_the_repair_resume_must_say_whether_a_device_was_removed(): void
    {
        $this->report($this->technician, ['removed_any' => null])->assertSessionHasErrors('removed_any');
        $this->report($this->technician, ['removed_any' => 'ya', 'removed_devices' => [['name' => '']]])->assertSessionHasErrors('removed_devices');
        $this->assertSame(TicketStatus::PERBAIKAN, $this->ticket->fresh()->status);

        $this->report($this->technician, ['removed_any' => 'tidak'])->assertSessionHasNoErrors();
        $this->assertSame(0, RemovedDevice::query()->count());
    }

    public function test_a_removed_modem_is_carried_by_the_technician_and_leaves_the_customer(): void
    {
        $installed = CustomerMaterial::query()->create(['customer_code' => 'CUST-1', 'item_name' => 'ONT ZTE F609', 'serial_number' => 'ZTEG1234', 'quantity' => 1, 'unit' => 'pcs', 'installed_at' => now()->subYear()]);
        $this->travelTo(now()->setTime(10, 0));

        $device = $this->reportModem();

        $this->assertSame(RemovedDeviceStatus::DIBAWA, $device->status);
        $this->assertSame('ZTEG1234', $device->serial_number);
        $this->assertSame('AABBCCDDEEFF', $device->mac);
        $this->assertSame($this->technician->id, $device->technician_id);
        $this->assertSame('CUST-1', $device->customer_code);
        $this->assertTrue($device->due_at->equalTo(now()->addDays(3)));
        $this->assertNotNull($installed->fresh()->removed_at);
        $this->assertSame($installed->id, $device->customer_material_id);
        $this->assertStringContainsString('Alat dicabut (wajib diserahkan ke Gudang paling lambat', $this->ticket->fresh()->histories->last()->notes);
        $this->assertStringContainsString('Modem ZTE F609 · SN ZTEG1234', $this->ticket->fresh()->histories->last()->notes);

        $this->actingAs($this->warehouse)->get("/tickets/{$this->ticket->ticket_number}")->assertOk()->assertSee('Alat Dicabut')->assertSee('Dibawa teknisi');
        $this->get('/gudang/'.Warehouse::of($this->technician)->id)->assertOk()->assertSee('Alat Cabutan, Serahkan ke Gudang (1)');
    }

    public function test_helpdesk_reporting_for_the_crew_puts_the_device_on_the_technician(): void
    {
        $helpdesk = $this->userWithRole(Role::HELPDESK);
        $this->report($helpdesk, ['removed_any' => 'ya', 'removed_devices' => [['name' => 'Router TP-Link']]])->assertSessionHasNoErrors();

        $device = RemovedDevice::query()->sole();
        $this->assertSame($this->technician->id, $device->technician_id);
        $this->assertSame($helpdesk->id, $device->reported_by);
        $this->assertSame('TIDAK_TAHU', $device->field_condition);
    }

    public function test_who_sees_the_report_and_who_handles_devices(): void
    {
        $device = $this->reportModem();
        $other = $this->userWithRole(Role::TEKNISI, ['name' => 'Tim Dian']);

        $this->actingAs($this->technician)->get('/gudang/cabutan')->assertOk()->assertSee('Alat Cabutan Saya')->assertSee('Modem ZTE F609');
        $this->actingAs($other)->get('/gudang/cabutan')->assertOk()->assertDontSee('Modem ZTE F609');
        $this->actingAs($this->userWithRole(Role::HELPDESK))->get('/gudang/cabutan')->assertOk()->assertSee('Modem ZTE F609')->assertSee('Tim Baim')->assertDontSee('Terima di Gudang');
        $this->post("/gudang/cabutan/{$device->id}/terima")->assertForbidden();
        $this->actingAs($this->technician)->post("/gudang/cabutan/{$device->id}/terima")->assertForbidden();
        $this->actingAs($this->userWithRole(Role::CS))->get('/gudang/cabutan')->assertForbidden();
        $this->actingAs($this->userWithRole(Role::SPV))->get('/gudang/cabutan')->assertOk()->assertSee('Terima di Gudang');
        $this->actingAs($this->warehouse)->get('/gudang/cabutan')->assertOk()->assertSee('Per Teknisi');

        Excel::fake();
        $this->get('/gudang/cabutan/export')->assertOk();
        Excel::assertDownloaded('alat-cabutan-'.now()->format('Ymd-His').'.xlsx');
    }

    public function test_a_fit_device_goes_back_into_stock_and_a_broken_one_does_not(): void
    {
        $modem = Item::query()->create(['code' => 'MDM001', 'name' => 'ONT ZTE F609', 'tracks_serial' => true]);
        $device = $this->reportModem();

        $this->actingAs($this->warehouse)->post("/gudang/cabutan/{$device->id}/cek", ['result' => 'layak', 'item' => 'MDM001'])->assertSessionHasErrors('removed_device');
        $this->post("/gudang/cabutan/{$device->id}/terima")->assertSessionHasNoErrors();
        $this->assertSame(RemovedDeviceStatus::MENUNGGU_CEK, $device->fresh()->status);
        $this->post("/gudang/cabutan/{$device->id}/cek", ['result' => 'layak'])->assertSessionHasErrors('item');

        $this->post("/gudang/cabutan/{$device->id}/cek", ['result' => 'layak', 'item' => 'MDM001 · ONT ZTE F609', 'serial_number' => 'ZTEG1234', 'check_notes' => 'Reset pabrik OK'])
            ->assertSessionHasNoErrors();
        $device->refresh();
        $this->assertSame(RemovedDeviceStatus::LAYAK, $device->status);
        $unit = SerialUnit::query()->where('serial_number', 'ZTEG1234')->sole();
        $this->assertSame(Warehouse::main()->id, $unit->warehouse_id);
        $this->assertSame($modem->id, $unit->item_id);
        $this->assertStringStartsWith('BEKAS · CABUTAN', $unit->condition);
        $this->assertSame($unit->id, $device->serial_unit_id);
        $movement = StockMovement::query()->sole();
        $this->assertSame(StockMovementType::RETUR, $movement->type);
        $this->assertNull($movement->from_warehouse_id);

        // A broken router from another ticket stays out of stock.
        $this->ticket = Ticket::factory()->status(TicketStatus::PERBAIKAN)->assignedTo($this->technician)->create(['repair_started_at' => now()]);
        $this->report($this->technician, ['removed_any' => 'ya', 'removed_devices' => [['name' => 'Router mati']]])->assertSessionHasNoErrors();
        $router = RemovedDevice::query()->latest('id')->first();
        $this->actingAs($this->warehouse)->post("/gudang/cabutan/{$router->id}/terima")->assertSessionHasNoErrors();
        $this->post("/gudang/cabutan/{$router->id}/cek", ['result' => 'rusak', 'check_notes' => 'Port LAN mati'])->assertSessionHasNoErrors();
        $this->assertSame(RemovedDeviceStatus::RUSAK, $router->fresh()->status);
        $this->assertSame(1, StockMovement::query()->count());
    }

    public function test_devices_without_an_sn_like_an_htb_or_adaptors_come_back_by_quantity(): void
    {
        $htb = Item::query()->create(['code' => 'HTB001', 'name' => 'HTB NETLINK', 'unit' => 'pcs']);
        $adaptor = Item::query()->create(['code' => 'ADP001', 'name' => 'ADAPTOR 12V 1A', 'unit' => 'pcs']);
        $this->report($this->technician, ['removed_any' => 'ya', 'removed_devices' => [
            ['name' => 'HTB Netlink', 'quantity' => 2, 'condition' => 'NORMAL'],
            ['name' => 'Adaptor 12V', 'quantity' => 1, 'condition' => 'RUSAK'],
        ]])->assertSessionHasNoErrors();
        [$htbs, $adaptors] = RemovedDevice::query()->orderBy('id')->get()->all();
        $this->assertSame(2, $htbs->quantity);
        $this->assertNull($htbs->serial_number);

        $this->actingAs($this->warehouse);
        foreach ([$htbs, $adaptors] as $device) {
            $this->post("/gudang/cabutan/{$device->id}/terima")->assertSessionHasNoErrors();
        }
        $this->post("/gudang/cabutan/{$htbs->id}/cek", ['result' => 'layak', 'item' => 'HTB001'])->assertSessionHasNoErrors();
        $this->post("/gudang/cabutan/{$adaptors->id}/cek", ['result' => 'rusak', 'check_notes' => 'Tidak keluar tegangan'])->assertSessionHasNoErrors();

        $held = fn (Item $item) => (int) \App\Models\Stock::query()->where('warehouse_id', Warehouse::main()->id)->where('item_id', $item->id)->value('quantity');
        $this->assertSame(2, $held($htb));
        $this->assertSame(0, $held($adaptor));
        $this->assertSame(0, SerialUnit::query()->count());
    }

    public function test_a_device_that_never_comes_back_is_closed_with_a_reason(): void
    {
        $device = $this->reportModem();
        $this->actingAs($this->warehouse)->post("/gudang/cabutan/{$device->id}/tidak-kembali", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/gudang/cabutan/{$device->id}/tidak-kembali", ['reason' => 'Ditinggal di pelanggan'])->assertSessionHasNoErrors();
        $this->assertSame(RemovedDeviceStatus::HILANG, $device->fresh()->status);
        $this->assertSame('Ditinggal di pelanggan', $device->fresh()->check_notes);
    }

    public function test_the_warehouse_is_reminded_once_a_day_about_devices_past_three_days(): void
    {
        $this->reportModem();
        $this->artisan('removed-devices:remind')->assertSuccessful();
        $this->assertSame(0, $this->warehouse->notifications()->count());

        $this->travel(3)->days();
        $this->travel(1)->hours();
        $this->artisan('removed-devices:remind')->assertSuccessful();
        $notice = $this->warehouse->notifications()->sole()->data;
        $this->assertSame('1 alat cabutan belum kembali ke gudang', $notice['title']);
        $this->assertStringContainsString('Modem ZTE F609 (ZTEG1234) · Tim Baim', $notice['body']);
        $this->assertSame(1, $this->technician->notifications()->where('data->title', 'Serahkan alat cabutan ke gudang')->count());

        // Not again within the day, but again the next day while it is still out.
        $this->travel(2)->hours();
        $this->artisan('removed-devices:remind');
        $this->assertSame(1, $this->warehouse->notifications()->count());
        $this->travel(1)->days();
        $this->artisan('removed-devices:remind');
        $this->assertSame(2, $this->warehouse->notifications()->count());

        $this->actingAs($this->warehouse)->get('/gudang')->assertSee('1 alat cabutan');
        $this->get('/gudang/cabutan?status=terlambat')->assertOk()->assertSee('Terlambat');
    }
}
