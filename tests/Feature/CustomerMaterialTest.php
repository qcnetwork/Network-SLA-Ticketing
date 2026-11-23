<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Enums\UsagePurpose;
use App\Models\Customer;
use App\Models\CustomerMaterial;
use App\Models\CustomerMaterialLog;
use App\Models\Item;
use App\Models\MaterialUsage;
use App\Models\SerialUnit;
use App\Models\Stock;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerMaterialTest extends TestCase
{
    use RefreshDatabase;

    private User $warehouse;

    private User $technician;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = $this->userWithRole(Role::GUDANG);
        $this->technician = $this->userWithRole(Role::TEKNISI, ['name' => 'Dian']);
        $this->customer = Customer::query()->create(['code' => 'QC-0012', 'name' => 'Rika Sucianti', 'phone' => '6281234567890', 'address' => 'Jagung']);
        Item::query()->create(['code' => 'MDM001', 'name' => 'MODEM ZTE F670L', 'tracks_serial' => true, 'scans_mac' => true]);
        Item::query()->create(['code' => 'KBL001', 'name' => 'KABEL FO 1 CORE', 'unit' => 'meter']);

        // Gudang Utama → Dian: the modem by SN, the cable by quantity.
        $this->actingAs($this->warehouse)->post('/gudang/masuk-sn', ['item' => 'MDM001', 'serials' => [['sn' => 'ZTE-A', 'mac' => 'F42D06F5757C'], ['sn' => 'ZTE-B']]])->assertSessionHasNoErrors();
        $this->post('/gudang/masuk', ['item' => 'KBL001', 'quantity' => 500])->assertSessionHasNoErrors();
        $this->post('/gudang/kirim-sn', ['technician_id' => $this->technician->id, 'serials' => [['sn' => 'ZTE-A'], ['sn' => 'ZTE-B']]])->assertSessionHasNoErrors();
        $this->post('/gudang/kirim', ['item' => 'KBL001', 'quantity' => 200, 'technician_id' => $this->technician->id])->assertSessionHasNoErrors();
    }

    private function install(array $overrides = [])
    {
        return $this->actingAs($this->technician)->post('/gudang/pemakaian', array_merge([
            'warehouse_id' => Warehouse::of($this->technician)->id,
            'used_at' => today()->toDateString(),
            'purpose' => UsagePurpose::PEMASANGAN_BARU->value,
            'location' => 'Rika Sucianti Jagung',
            'customer_code' => 'qc-0012',
            'lines' => [['item' => 'MDM001', 'serials' => ['ZTE-A']], ['item' => 'KBL001', 'quantity' => 75]],
        ], $overrides));
    }

    public function test_material_installed_for_a_customer_id_lands_in_their_gudang_with_a_log_and_its_nodes(): void
    {
        $this->install()->assertSessionHasNoErrors();

        $usage = MaterialUsage::query()->sole();
        $this->assertSame('QC-0012', $usage->customer_code);
        $materials = CustomerMaterial::query()->ofCustomer('QC-0012')->installed()->get();
        $this->assertEqualsCanonicalizing(['ZTE-A', null], $materials->pluck('serial_number')->all());
        $this->assertSame(75, $materials->firstWhere('serial_number', null)->quantity);
        $this->assertSame('Dian', $materials->first()->technician);
        $this->assertSame([CustomerMaterialLog::PASANG, CustomerMaterialLog::PASANG], CustomerMaterialLog::query()->pluck('action')->all());
        $this->assertSame('Rika Sucianti (QC-0012)', CustomerMaterialLog::query()->value('to_label'));

        // The modem went Gudang Utama → Dian → Rika.
        $unit = SerialUnit::query()->where('serial_number', 'ZTE-A')->sole();
        $journey = $unit->journey();
        $this->assertSame(['gudang', 'teknisi', 'pelanggan'], array_column($journey, 'kind'));
        $this->assertSame(['Gudang Utama', 'Dian', 'Rika Sucianti'], array_column($journey, 'label'));

        $this->actingAs($this->warehouse)->get('/gudang/pelanggan')->assertOk()->assertSee('Rika Sucianti')->assertSee('ZTE-A');
        $this->assertSame(1, $this->get('/gudang/pelanggan?q=ZTE-A')->assertOk()->viewData('customers')->total());
        $this->assertSame(0, $this->get('/gudang/pelanggan?q=nobody')->assertOk()->viewData('customers')->total());
        $this->get('/gudang/pelanggan/QC-0012')->assertOk()
            ->assertSee('Terpasang Sekarang')->assertSee('SN ZTE-A')->assertSee('KABEL FO 1 CORE')->assertSee('Log Material');
        $this->get("/gudang/unit/{$unit->id}")->assertOk()->assertSee('Perjalanan Unit')->assertSee('Rika Sucianti');

        // An ID the directory does not have is refused, and a job without one stays out of Gudang Pelanggan.
        $this->install(['customer_code' => 'NOPE-1', 'lines' => [['item' => 'KBL001', 'quantity' => 1]]])->assertSessionHasErrors('customer_code');
        $this->install(['customer_code' => '', 'lines' => [['item' => 'KBL001', 'quantity' => 1]]])->assertSessionHasNoErrors();
        $this->assertSame(2, CustomerMaterial::query()->count());
    }

    public function test_a_ticket_resume_approved_by_the_warehouse_files_material_under_the_tickets_customer(): void
    {
        $ticket = Ticket::factory()->status(TicketStatus::PERBAIKAN)->assignedTo($this->technician)
            ->create(['repair_started_at' => now(), 'customer_id' => 'QC-0012', 'customer_name' => 'Rika Sucianti']);
        $this->actingAs($this->technician)->post("/tickets/{$ticket->ticket_number}/actions/LAPOR_PERBAIKAN_SELESAI", ['notes' => 'Ganti modem',
            'materials' => [['technician_id' => $this->technician->id, 'code' => 'MDM001', 'serial' => 'ZTE-B']]])->assertSessionHasNoErrors();
        $this->assertSame(0, CustomerMaterial::query()->count());

        $this->actingAs($this->warehouse)->post("/gudang/pengajuan/{$ticket->materialRequests()->sole()->id}/setujui")->assertSessionHasNoErrors();

        $material = CustomerMaterial::query()->sole();
        $this->assertSame(['QC-0012', 'ZTE-B', $ticket->id], [$material->customer_code, $material->serial_number, $material->ticket_id]);
        $this->get('/gudang/pelanggan/QC-0012')->assertOk()->assertSee($ticket->ticket_number);
    }

    public function test_removing_material_takes_it_back_to_the_office_or_writes_it_off(): void
    {
        $this->install()->assertSessionHasNoErrors();
        $modem = CustomerMaterial::query()->whereNotNull('serial_number')->sole();
        $cable = CustomerMaterial::query()->whereNull('serial_number')->sole();
        $main = Warehouse::main();
        $mainCable = fn () => (int) Stock::query()->where('warehouse_id', $main->id)->where('item_id', $cable->item_id)->value('quantity');
        $before = $mainCable();

        $this->actingAs($this->technician)->post(route('customer-materials.remove', $modem), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->warehouse)->post(route('customer-materials.remove', $modem), ['reason' => ''])->assertSessionHasErrors('reason');

        // The modem comes back: idle in Gudang Utama again, its trip ending there.
        $this->post(route('customer-materials.remove', $modem), ['reason' => 'Diganti modem baru', 'back_to_office' => '1'])->assertSessionHasNoErrors();
        $unit = SerialUnit::query()->where('serial_number', 'ZTE-A')->sole();
        $this->assertSame($main->id, $unit->warehouse_id);
        $this->assertNull($unit->destination());
        $this->assertSame(['gudang', 'teknisi', 'pelanggan', 'gudang'], array_column($unit->journey(), 'kind'));
        $this->assertNotNull($modem->fresh()->removed_at);

        // The cable is written off: main stock does not change.
        $this->post(route('customer-materials.remove', $cable), ['reason' => 'Putus'])->assertSessionHasNoErrors();
        $this->assertSame($before, $mainCable());
        $this->post(route('customer-materials.remove', $cable), ['reason' => 'lagi'])->assertSessionHasErrors('reason');

        $this->assertSame(0, CustomerMaterial::query()->installed()->count());
        $this->assertSame([CustomerMaterialLog::KEMBALI, CustomerMaterialLog::LEPAS], CustomerMaterialLog::query()->where('action', '!=', CustomerMaterialLog::PASANG)->orderBy('id')->pluck('action')->all());
        $this->get('/gudang/pelanggan/QC-0012')->assertOk()->assertSee('Pernah Terpasang')->assertSee('Diganti modem baru');
    }

    public function test_cancelling_the_job_cancels_it_at_the_customer_and_the_backfill_is_safe_to_rerun(): void
    {
        $this->install()->assertSessionHasNoErrors();
        $usage = MaterialUsage::query()->sole();

        // Running the backfill again adds nothing.
        $this->artisan('gudang-pelanggan:sync')->assertSuccessful();
        $this->assertSame(2, CustomerMaterial::query()->count());

        $this->actingAs($this->warehouse)->post(route('usages.cancel', $usage), ['cancel_reason' => 'Salah pelanggan'])->assertSessionHasNoErrors();
        $this->assertSame(0, CustomerMaterial::query()->installed()->count());
        $this->assertSame(2, CustomerMaterialLog::query()->where('action', CustomerMaterialLog::BATAL)->count());

        // A job from before Gudang Pelanggan existed, through its ticket's customer ID.
        $ticket = Ticket::factory()->create(['customer_id' => 'QC-0012']);
        $old = MaterialUsage::query()->create(['used_at' => '2026-10-01', 'purpose' => UsagePurpose::PEMASANGAN_BARU, 'location' => 'Rika', 'ticket_id' => $ticket->id]);
        $old->lines()->create(['item_id' => Item::query()->where('code', 'KBL001')->value('id'), 'item_name' => 'KABEL FO 1 CORE', 'quantity' => 30]);
        $this->artisan('gudang-pelanggan:sync')->expectsOutputToContain('1 material dari 1 pemakaian')->assertSuccessful();
        $this->assertSame('2026-10-01', CustomerMaterial::query()->installed()->sole()->installed_at->toDateString());
    }

    public function test_technicians_only_get_the_customer_picker(): void
    {
        $this->actingAs($this->technician)->get('/gudang/pelanggan')->assertForbidden();
        $this->get('/gudang/pelanggan/QC-0012')->assertForbidden();
        $this->getJson('/gudang/pelanggan/cari?q=rika')->assertOk()->assertJsonPath('0.code', 'QC-0012');

        $this->actingAs($this->warehouse)->get('/gudang/pelanggan/UNKNOWN-9')->assertNotFound();
        $this->get('/gudang/pelanggan/QC-0012')->assertOk()->assertSee('Tidak ada material terpasang');
    }
}
