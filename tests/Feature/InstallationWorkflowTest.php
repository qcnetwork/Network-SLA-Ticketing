<?php

namespace Tests\Feature;

use App\Enums\MaterialRequestStatus;
use App\Enums\Role;
use App\Enums\TicketKind;
use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ServicePackage;
use App\Models\Stock;
use App\Models\TelegramLog;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InstallationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $cs;

    private User $helpdesk;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(TicketAttachment::DISK);

        $this->cs = $this->userWithRole(Role::CS);
        $this->helpdesk = $this->userWithRole(Role::HELPDESK);
        $this->technician = $this->userWithRole(Role::TEKNISI);
        ServicePackage::query()->create(['name' => 'Home 20 Mbps', 'sort_order' => 10]);
    }

    private function order(array $overrides = []): Ticket
    {
        $this->actingAs($this->cs)->post('/psb', $overrides + [
            'customer_name' => 'Budi Santoso',
            'customer_phone' => '0812-1111-2222',
            'customer_address' => '-6.2088, 106.8456',
            'package' => 'Home 20 Mbps',
            'sales' => 'Andi',
            'photos' => [UploadedFile::fake()->image('rumah.jpg')],
        ])->assertSessionHasNoErrors();

        return Ticket::query()->installations()->latest('id')->firstOrFail();
    }

    private function assertNotified(User $user, string $text): void
    {
        $this->assertTrue($user->notifications()->get()->contains(fn ($notification) => str_contains($notification->data['body'], $text)),
            "{$user->name} tidak menerima notifikasi berisi \"{$text}\"");
    }

    private function act(User $user, Ticket $ticket, string $action, array $data = [])
    {
        return $this->actingAs($user)->post("/tickets/{$ticket->ticket_number}/actions/{$action}", $data);
    }

    public function test_cs_takes_an_order_with_the_house_photo(): void
    {
        $this->actingAs($this->cs)->get('/psb/baru')->assertOk()->assertSee('Home 20 Mbps');
        $this->post('/psb', ['customer_name' => 'Budi', 'customer_phone' => '08121111222', 'customer_address' => 'Jl. Mawar', 'package' => 'Home 20 Mbps'])
            ->assertSessionHasErrors('photos');
        $this->post('/psb', ['customer_name' => 'Budi', 'customer_phone' => '08121111222', 'customer_address' => 'Jl. Mawar', 'package' => 'Paket Lain',
            'photos' => [UploadedFile::fake()->image('rumah.jpg')]])->assertSessionHasErrors('package');

        $ticket = $this->order();

        $this->assertMatchesRegularExpression('/^PSB-\d{8}-0001$/', $ticket->ticket_number);
        $this->assertSame(TicketKind::PSB, $ticket->kind);
        $this->assertSame(TicketStatus::PSB_CEK_LOKASI, $ticket->status);
        $this->assertSame('Home 20 Mbps', $ticket->package);
        $this->assertSame('Andi', $ticket->sales);
        $this->assertTrue($ticket->hasLocation());
        $this->assertNull($ticket->customer_id);
        $this->assertCount(1, $ticket->attachments);
        $this->assertStringContainsString('ORDER PASANG BARU', TelegramLog::query()->latest('id')->value('message'));
        // Helpdesk is told to check the location.
        $this->assertStringContainsString('cek lokasi', $this->helpdesk->notifications()->sole()->data['body']);

        $this->get("/tickets/{$ticket->ticket_number}")->assertOk()->assertSee('Calon Pelanggan')->assertSee('Foto Depan Rumah');
        // Only Helpdesk decides on the location.
        $this->act($this->cs, $ticket, 'PSB_LANGSUNG_PASANG')->assertForbidden();
    }

    public function test_order_goes_through_survey_install_and_activation(): void
    {
        $adaptor = Item::query()->create(['code' => 'DC150', 'name' => 'DROPCORE 150 M']);
        Stock::query()->create(['warehouse_id' => Warehouse::of($this->technician)->id, 'item_id' => $adaptor->id, 'quantity' => 2]);
        $ticket = $this->order();

        // Coverage unsure: survey first.
        $this->act($this->helpdesk, $ticket, 'PSB_PERLU_SURVEY')->assertSessionHasErrors('notes');
        $this->act($this->helpdesk, $ticket, 'PSB_PERLU_SURVEY', ['notes' => 'Cek jalur tiang', 'odp' => 'ODP-12'])->assertSessionHasNoErrors();
        $ticket->refresh();
        $this->assertSame(TicketStatus::PSB_ANTRIAN_SURVEY, $ticket->status);
        $this->assertSame($this->helpdesk->id, $ticket->assigned_helpdesk_id);

        $this->act($this->helpdesk, $ticket, 'PSB_TUGAS_SURVEY', ['technician_ids' => [$this->technician->id]])->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::PSB_SURVEY, $ticket->fresh()->status);
        $this->assertNotified($this->technician, 'survey lokasi');

        // The survey crew opens the order and reports from site.
        $this->actingAs($this->technician)->get("/tickets/{$ticket->ticket_number}")->assertOk()->assertSee('Hasil Survey');
        $this->act($this->technician, $ticket, 'PSB_SURVEY_LAYAK', ['notes' => 'Tarik 120 m dari ODP-12'])->assertSessionHasNoErrors();
        $ticket->refresh();
        $this->assertSame(TicketStatus::PSB_ANTRIAN_PASANG, $ticket->status);
        $this->assertSame('Tarik 120 m dari ODP-12', $ticket->survey_notes);
        $this->assertTrue($ticket->technicians->isEmpty());
        // With the survey done the technician has no business on the order any more.
        $this->actingAs($this->technician)->get("/tickets/{$ticket->ticket_number}")->assertForbidden();

        $this->act($this->helpdesk, $ticket, 'PSB_TUGAS_PASANG', ['technician_ids' => [$this->technician->id]])->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::PSB_PEMASANGAN, $ticket->fresh()->status);
        $this->act($this->technician, $ticket, 'PSB_PASANG_SELESAI', ['notes' => 'x'])->assertSessionHasErrors('action');
        $this->act($this->technician, $ticket, 'PSB_MULAI_PASANG')->assertSessionHasNoErrors();
        $this->actingAs($this->technician)->get("/tickets/{$ticket->ticket_number}")->assertSee('Laporan Pemasangan')->assertSee('DROPCORE 150 M');
        $this->act($this->technician, $ticket, 'PSB_PASANG_SELESAI', [
            'notes' => 'ONT online, redaman -19 dBm',
            'modem' => 'ZTE F670L',
            'materials' => [['technician_id' => $this->technician->id, 'code' => 'DC150', 'quantity' => 1]],
        ])->assertSessionHasNoErrors()->assertRedirect('/psb');

        $ticket->refresh();
        $this->assertSame(TicketStatus::PSB_KONFIRMASI, $ticket->status);
        $this->assertSame('ZTE F670L', $ticket->modem);
        $this->assertSame(MaterialRequestStatus::PENDING, $ticket->materialRequests->sole()->status);
        $this->assertNotified($this->cs, 'konfirmasi');

        // Not live yet: back to the crew, then confirmed.
        $this->act($this->cs, $ticket, 'PSB_BELUM_AKTIF')->assertSessionHasErrors('notes');
        $this->act($this->cs, $ticket, 'PSB_BELUM_AKTIF', ['notes' => 'Lampu LOS merah'])->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::PSB_PEMASANGAN, $ticket->fresh()->status);
        $this->act($this->technician, $ticket, 'PSB_PASANG_SELESAI', ['notes' => 'Konektor diganti, ONT online'])->assertSessionHasNoErrors();

        $this->act($this->cs, $ticket, 'PSB_AKTIF', ['customer_code' => 'QC-0099'])->assertSessionHasNoErrors();
        $ticket->refresh();
        $this->assertSame(TicketStatus::PSB_SELESAI, $ticket->status);
        $this->assertSame('QC-0099', $ticket->customer_id);
        $this->assertNotNull($ticket->closed_at);
        $customer = Customer::query()->withCode('QC-0099')->sole();
        $this->assertSame('Budi Santoso', $customer->name);
        $this->assertSame('6281211112222', $customer->phone);
        $this->assertNotNull($customer->latitude);
        $this->actingAs($this->cs)->get('/psb?tab=selesai')->assertOk()->assertSee('QC-0099');
        $this->get("/tickets/{$ticket->ticket_number}")->assertOk()->assertSee('Pelanggan sudah aktif dengan ID QC-0099');
    }

    public function test_supervisor_manages_the_service_packages(): void
    {
        $supervisor = $this->userWithRole(Role::SPV);
        $this->actingAs($supervisor)->get('/settings')->assertOk()->assertSee('Paket Layanan');
        $this->get('/packages')->assertOk()->assertSee('Home 20 Mbps');
        $this->post('/packages', ['name' => 'Bisnis 50 Mbps'])->assertRedirect('/packages');
        $this->assertSame(['Home 20 Mbps', 'Bisnis 50 Mbps'], ServicePackage::activeNames());
        $this->actingAs($this->cs)->get('/packages')->assertForbidden();
    }

    public function test_order_without_survey_and_without_customer_id_gets_one(): void
    {
        $ticket = $this->order();
        $this->act($this->helpdesk, $ticket, 'PSB_LANGSUNG_PASANG', ['odp' => 'ODP-3'])->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::PSB_ANTRIAN_PASANG, $ticket->fresh()->status);
        $this->act($this->helpdesk, $ticket, 'PSB_TUGAS_PASANG', ['technician_ids' => [$this->technician->id]]);
        // Helpdesk records the visit for a crew reporting by phone.
        $this->act($this->helpdesk, $ticket, 'PSB_MULAI_PASANG')->assertSessionHasNoErrors();
        $this->act($this->helpdesk, $ticket, 'PSB_PASANG_SELESAI', ['notes' => 'Terpasang'])->assertSessionHasNoErrors();
        $this->act($this->cs, $ticket, 'PSB_AKTIF')->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertMatchesRegularExpression('/^CUST-\d{6}$/', $ticket->customer_id);
        $this->assertTrue(Customer::query()->withCode($ticket->customer_id)->exists());
        $this->assertSame('ODP-3', $ticket->odp);
    }

    public function test_odp_shareloc_gives_the_distance_to_the_house(): void
    {
        $ticket = $this->order();
        $this->act($this->helpdesk, $ticket, 'PSB_LANGSUNG_PASANG', [
            'odp' => 'ODP Tambakroto 03',
            'odp_location' => '-6.2100, 106.8456',
            'odp_distance' => 180,
        ])->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame('ODP Tambakroto 03', $ticket->odp);
        $this->assertEqualsWithDelta(-6.21, $ticket->odp_latitude, 0.00001);
        $this->assertSame(180, $ticket->odp_distance);
        $this->assertStringContainsString('ODP: ODP Tambakroto 03, jarak kabel ±180 m.', $ticket->histories->last()->notes);
        $this->actingAs($this->helpdesk)->get("/tickets/{$ticket->ticket_number}")->assertOk()
            ->assertSee('±180 m')->assertSee('Lihat Rute ODP ke Rumah')->assertDontSee('Garis lurus');

        // A later step with the ODP fields left blank keeps them; the data form can clear them.
        $this->act($this->helpdesk, $ticket, 'PSB_TUGAS_PASANG', ['technician_ids' => [$this->technician->id]]);
        $this->act($this->technician, $ticket, 'PSB_MULAI_PASANG');
        // The distance is Helpdesk's to fill: the crew does not get the field, and a sent value is ignored.
        $this->actingAs($this->technician)->get("/tickets/{$ticket->ticket_number}")->assertDontSee('Jarak Kabel ODP ke Rumah');
        $this->act($this->technician, $ticket, 'PSB_PASANG_SELESAI', ['notes' => 'Terpasang', 'odp_distance' => 210])->assertSessionHasNoErrors();
        $ticket->refresh();
        $this->assertSame('-6.2100, 106.8456', $ticket->odp_location);
        $this->assertSame(180, $ticket->odp_distance);
        $this->actingAs($this->cs)->put("/psb/{$ticket->ticket_number}/data", ['package' => 'Home 20 Mbps', 'sales' => 'Rina', 'odp' => 'ODP Tambakroto 03', 'odp_location' => '-6.2100, 106.8456'])
            ->assertSessionHasNoErrors();
        $this->assertSame(180, $ticket->fresh()->odp_distance);

        $this->actingAs($this->helpdesk)->put("/psb/{$ticket->ticket_number}/data", ['package' => 'Home 20 Mbps', 'sales' => 'Andi', 'odp' => 'ODP Tambakroto 03'])
            ->assertSessionHasNoErrors();
        $ticket->refresh();
        $this->assertNull($ticket->odp_location);
        $this->assertNull($ticket->odp_latitude);
        $this->assertNull($ticket->odp_distance);
    }

    public function test_failed_survey_and_cancelling_end_the_order(): void
    {
        $ticket = $this->order();
        $this->act($this->helpdesk, $ticket, 'PSB_PERLU_SURVEY', ['notes' => 'Jauh dari ODP']);
        $this->act($this->helpdesk, $ticket, 'PSB_TUGAS_SURVEY', ['technician_ids' => [$this->technician->id]]);
        $this->act($this->technician, $ticket, 'PSB_SURVEY_TIDAK_LAYAK', ['notes' => 'ODP penuh'])->assertSessionHasNoErrors();
        $ticket->refresh();
        $this->assertSame(TicketStatus::PSB_BATAL, $ticket->status);
        $this->assertStringContainsString('ODP penuh', $ticket->confirmation_notes);
        $this->act($this->cs, $ticket, 'PSB_BATAL', ['notes' => 'x'])->assertSessionHasErrors('action');

        $other = $this->order(['customer_name' => 'Sari']);
        $this->act($this->cs, $other, 'PSB_BATAL')->assertSessionHasErrors('notes');
        $this->act($this->cs, $other, 'PSB_BATAL', ['notes' => 'Calon pelanggan batal'])->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::PSB_BATAL, $other->fresh()->status);

        $this->actingAs($this->cs)->get('/psb?tab=batal')->assertOk()->assertSee('Calon pelanggan batal')->assertSee('ODP penuh');
    }

    public function test_installations_stay_out_of_complaint_pages_and_actions(): void
    {
        $ticket = $this->order();

        // The bell may mention the order, but the complaint board does not hold it.
        $this->assertSame(0, $this->actingAs($this->helpdesk)->get('/komplain')->assertOk()->viewData('total'));
        $this->get('/arsip?q=Budi')->assertOk()->assertDontSee($ticket->ticket_number);
        $this->get('/psb')->assertOk()->assertSee($ticket->ticket_number)->assertSee('Cek Lokasi');
        // Complaint steps do not apply to an installation, and the other way round.
        $this->act($this->helpdesk, $ticket, 'KLAIM_HELPDESK')->assertSessionHasErrors('action');
        $complaint = Ticket::factory()->status(TicketStatus::HELPDESK_CEK)->create();
        $this->act($this->helpdesk, $complaint, 'PSB_LANGSUNG_PASANG')->assertSessionHasErrors('action');

        // Notes work on installations too, and reach both desks.
        $this->act($this->helpdesk, $ticket, 'CATATAN_INFO_HELPDESK', ['notes' => 'Pelanggan minta pasang Sabtu', 'urgency_level' => 'INFO'])->assertSessionHasNoErrors();
        $this->assertNotified($this->cs, 'Pelanggan minta pasang Sabtu');

        // A technician only sees the PSB menu's visits assigned to them.
        $this->actingAs($this->technician)->get('/psb')->assertOk()->assertDontSee($ticket->ticket_number);
        $this->get('/psb/baru')->assertForbidden();
    }

    public function test_cs_and_helpdesk_edit_package_sales_and_odp(): void
    {
        $ticket = $this->order();
        $this->actingAs($this->helpdesk)->put("/psb/{$ticket->ticket_number}/data", ['package' => 'Home 20 Mbps', 'sales' => 'Rina', 'odp' => 'ODP-7'])
            ->assertSessionHasNoErrors();
        $ticket->refresh();
        $this->assertSame('Rina', $ticket->sales);
        $this->assertSame('ODP-7', $ticket->odp);
        $this->assertStringContainsString('Sales: "Andi" → "Rina"', $ticket->histories->last()->notes);

        $this->put("/psb/{$ticket->ticket_number}/data", ['package' => 'Tidak Ada', 'sales' => 'Rina', 'odp' => 'ODP-7'])->assertSessionHasErrors('package');
        $this->actingAs($this->technician)->put("/psb/{$ticket->ticket_number}/data", ['sales' => 'X'])->assertForbidden();
    }
}
