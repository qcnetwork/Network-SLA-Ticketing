<?php

namespace Tests\Feature;

use App\Enums\MaterialRequestStatus;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Enums\TicketAction;
use App\Enums\TicketStatus;
use App\Models\Item;
use App\Models\Stock;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $warehouse;

    private User $technician;

    private Item $adaptor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = $this->userWithRole(Role::GUDANG);
        $this->technician = $this->userWithRole(Role::TEKNISI, ['name' => 'Tim Baim']);
        $this->adaptor = Item::query()->create(['code' => 'ADP001', 'name' => 'ADAPTOR 12 V 1 A']);
    }

    private function quantity(Warehouse $warehouse, Item $item): int
    {
        return (int) Stock::query()->where('warehouse_id', $warehouse->id)->where('item_id', $item->id)->value('quantity');
    }

    public function test_stock_comes_in_goes_to_a_technician_and_comes_back(): void
    {
        $this->actingAs($this->warehouse)->post('/gudang/masuk', ['item' => 'adp001', 'quantity' => 10, 'notes' => 'Paket 2 Nov'])
            ->assertSessionHasNoErrors();
        $this->post('/gudang/kirim', ['item' => 'ADP001 · ADAPTOR 12 V 1 A', 'quantity' => 4, 'technician_id' => $this->technician->id])
            ->assertSessionHasNoErrors();

        $own = Warehouse::of($this->technician);
        $this->assertSame('Gudang Tim Baim', $own->name);
        $this->assertSame(6, $this->quantity(Warehouse::main(), $this->adaptor));
        $this->assertSame(4, $this->quantity($own, $this->adaptor));

        $this->post("/gudang/{$own->id}/retur", ['item' => 'ADP001', 'quantity' => 1])->assertSessionHasNoErrors();
        $this->assertSame(7, $this->quantity(Warehouse::main(), $this->adaptor));
        $this->assertSame(3, $this->quantity($own, $this->adaptor));

        $this->post('/gudang/kirim', ['item' => 'ADP001', 'quantity' => 50, 'technician_id' => $this->technician->id])
            ->assertSessionHasErrors('quantity');
        $this->post('/gudang/masuk', ['item' => 'XYZ999', 'quantity' => 1])->assertSessionHasErrors('item');

        $this->get('/gudang')->assertOk()->assertSee('Gudang Utama')->assertSee('Tim Baim')->assertSee('Paket 2 Nov');
        $this->get("/gudang/{$own->id}")->assertOk()->assertSee('ADAPTOR 12 V 1 A');
    }

    public function test_technician_uses_material_in_the_repair_resume(): void
    {
        Stock::query()->create(['warehouse_id' => Warehouse::of($this->technician)->id, 'item_id' => $this->adaptor->id, 'quantity' => 3]);
        $ticket = Ticket::factory()->status(TicketStatus::PERBAIKAN)->assignedTo($this->technician)->create(['repair_started_at' => now()]);

        $this->actingAs($this->technician)->get("/tickets/{$ticket->ticket_number}")->assertOk()
            ->assertSee('Resume Perbaikan')->assertSee('ADAPTOR 12 V 1 A');

        $this->post("/tickets/{$ticket->ticket_number}/actions/LAPOR_PERBAIKAN_SELESAI", [
            'notes' => 'Ganti adaptor',
            'materials' => [['technician_id' => $this->technician->id, 'code' => 'ADP001', 'quantity' => 5]],
        ])->assertSessionHasErrors('materials');
        $this->assertSame(TicketStatus::PERBAIKAN, $ticket->fresh()->status);
        $this->assertSame(3, $this->quantity(Warehouse::of($this->technician), $this->adaptor));

        $this->post("/tickets/{$ticket->ticket_number}/actions/LAPOR_PERBAIKAN_SELESAI", [
            'notes' => 'Ganti adaptor',
            'materials' => [['technician_id' => $this->technician->id, 'code' => 'ADP001', 'quantity' => 2, 'notes' => 'adaptor lama mati']],
        ])->assertSessionHasNoErrors();

        // The resume only asks the warehouse: the technician's stock stays put until it is approved.
        $ticket->refresh();
        $this->assertSame(TicketStatus::MENUNGGU_KONFIRMASI, $ticket->status);
        $this->assertSame(3, $this->quantity(Warehouse::of($this->technician), $this->adaptor));
        $this->assertTrue($ticket->materials->isEmpty());
        $request = $ticket->materialRequests->sole();
        $this->assertSame(MaterialRequestStatus::PENDING, $request->status);
        $this->assertStringContainsString('ADP001 ADAPTOR 12 V 1 A × 2 pcs dari Gudang Tim Baim (adaptor lama mati)', $ticket->histories->last()->notes);

        // The two adaptors are spoken for: another resume or a return can only use the one left.
        $next = Ticket::factory()->status(TicketStatus::PERBAIKAN)->assignedTo($this->technician)->create(['repair_started_at' => now()]);
        $this->post("/tickets/{$next->ticket_number}/actions/LAPOR_PERBAIKAN_SELESAI", [
            'notes' => 'Ganti adaptor',
            'materials' => [['technician_id' => $this->technician->id, 'code' => 'ADP001', 'quantity' => 2]],
        ])->assertSessionHasErrors('materials');
        $own = Warehouse::of($this->technician);
        $this->actingAs($this->warehouse)->post("/gudang/{$own->id}/retur", ['item' => 'ADP001', 'quantity' => 2])->assertSessionHasErrors('quantity');

        $this->get('/gudang')->assertOk()->assertSee('Pengajuan Material')->assertSee($ticket->ticket_number);
        // The warehouse sees only tickets with material to approve, and the approval node runs on after closing.
        $this->get('/dashboard?view=table')->assertOk()->assertSee($ticket->ticket_number)->assertDontSee($next->ticket_number);
        $this->get("/tickets/{$next->ticket_number}")->assertForbidden();
        $ticket->update(['status' => TicketStatus::TUTUP, 'closed_at' => now()]);
        $this->get("/tickets/{$ticket->ticket_number}")->assertOk()->assertSee('Approval Material')->assertSee('menunggu ACC Gudang');
        $this->actingAs($this->userWithRole(Role::HELPDESK))->get("/tickets/{$ticket->ticket_number}")->assertSee('Approval Material');
        $this->actingAs($this->userWithRole(Role::CS))->get("/tickets/{$ticket->ticket_number}")->assertDontSee('Approval Material');
        $this->actingAs($this->warehouse);
        $this->get("/tickets/{$ticket->ticket_number}")->assertSee('Material Terpakai')->assertSee('Menunggu ACC Gudang');

        $this->post("/gudang/pengajuan/{$request->id}/setujui", ['review_notes' => 'Sesuai'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->quantity($own, $this->adaptor));
        $material = $ticket->fresh()->materials->sole();
        $this->assertSame(StockMovementType::PAKAI, $material->type);
        $this->assertSame(2, $material->quantity);
        $this->assertSame(MaterialRequestStatus::APPROVED, $request->fresh()->status);
        $history = $ticket->fresh()->histories->last();
        $this->assertSame(TicketAction::MATERIAL_DISETUJUI, $history->action);
        $this->assertStringContainsString('Catatan Gudang: Sesuai', $history->notes);

        // A decision is final.
        $this->post("/gudang/pengajuan/{$request->id}/tolak", ['review_notes' => 'Salah'])->assertSessionHasErrors('material_request');
        $this->assertSame(1, $this->quantity($own, $this->adaptor));
        $this->get('/gudang')->assertDontSee('Pengajuan Material');
    }

    public function test_warehouse_rejects_material_and_stock_stays(): void
    {
        $own = Warehouse::of($this->technician);
        Stock::query()->create(['warehouse_id' => $own->id, 'item_id' => $this->adaptor->id, 'quantity' => 3]);
        $ticket = Ticket::factory()->status(TicketStatus::PERBAIKAN)->assignedTo($this->technician)->create(['repair_started_at' => now()]);
        $this->actingAs($this->technician)->post("/tickets/{$ticket->ticket_number}/actions/LAPOR_PERBAIKAN_SELESAI", [
            'notes' => 'Ganti adaptor',
            'materials' => [['technician_id' => $this->technician->id, 'code' => 'ADP001', 'quantity' => 3]],
        ])->assertSessionHasNoErrors();
        $request = $ticket->materialRequests()->sole();

        // Only the warehouse decides, and turning it down needs a reason.
        $this->post("/gudang/pengajuan/{$request->id}/setujui")->assertForbidden();
        $this->actingAs($this->userWithRole(Role::HELPDESK))->post("/gudang/pengajuan/{$request->id}/tolak", ['review_notes' => 'x'])->assertForbidden();
        $this->actingAs($this->warehouse)->post("/gudang/pengajuan/{$request->id}/tolak")->assertSessionHasErrors('review_notes');

        $this->post("/gudang/pengajuan/{$request->id}/tolak", ['review_notes' => 'Adaptor masih bagus, tidak perlu ganti'])->assertSessionHasNoErrors();
        $this->assertSame(3, $this->quantity($own, $this->adaptor));
        $this->assertSame(MaterialRequestStatus::REJECTED, $request->fresh()->status);
        $this->assertTrue($ticket->materials()->doesntExist());
        $history = $ticket->fresh()->histories->last();
        $this->assertSame(TicketAction::MATERIAL_DITOLAK, $history->action);
        $this->assertStringContainsString('Adaptor masih bagus', $history->notes);

        // The crew sends it again, even after the ticket is closed; another technician cannot.
        $ticket->update(['status' => TicketStatus::TUTUP, 'closed_at' => now()]);
        $resubmit = "/tickets/{$ticket->ticket_number}/material";
        $outsider = $this->userWithRole(Role::TEKNISI);
        $this->actingAs($outsider)->get("/tickets/{$ticket->ticket_number}")->assertForbidden();
        $this->post($resubmit, ['materials' => [['technician_id' => $this->technician->id, 'code' => 'ADP001', 'quantity' => 1]]])->assertForbidden();

        $this->actingAs($this->technician)->get("/gudang/{$own->id}")->assertSee('Material Ditolak, Ajukan Ulang')->assertSee($ticket->ticket_number);
        $this->get("/tickets/{$ticket->ticket_number}")->assertOk()->assertSee('Ajukan Ulang Material')->assertSee('Adaptor masih bagus');
        $this->post($resubmit, ['materials' => []])->assertSessionHasErrors('materials');
        $this->post($resubmit, ['materials' => [['technician_id' => $this->technician->id, 'code' => 'ADP001', 'quantity' => 1, 'notes' => 'cuma satu']]])
            ->assertRedirect('/gudang')->assertSessionHasNoErrors();

        $this->assertSame(3, $this->quantity($own, $this->adaptor));
        $this->assertSame(MaterialRequestStatus::PENDING, $ticket->fresh()->materialRequests->last()->status);
        $history = $ticket->fresh()->histories->last();
        $this->assertSame(TicketAction::MATERIAL_DIAJUKAN_ULANG, $history->action);
        $this->assertStringContainsString('ADP001 ADAPTOR 12 V 1 A × 1 pcs dari Gudang Tim Baim (cuma satu)', $history->notes);
        // Nothing to fix any more, so the ticket closes to the technician again and a second resubmit is refused.
        $this->get("/tickets/{$ticket->ticket_number}")->assertForbidden();
        $this->post($resubmit, ['materials' => [['technician_id' => $this->technician->id, 'code' => 'ADP001', 'quantity' => 1]]])->assertForbidden();

        // The rejected adaptors are free again; the resubmitted one stays held.
        $this->actingAs($this->warehouse)->post("/gudang/{$own->id}/retur", ['item' => 'ADP001', 'quantity' => 3])->assertSessionHasErrors('quantity');
        $this->post("/gudang/{$own->id}/retur", ['item' => 'ADP001', 'quantity' => 2])->assertSessionHasNoErrors();
    }

    public function test_one_resume_draws_on_several_crew_warehouses(): void
    {
        $partner = $this->userWithRole(Role::TEKNISI, ['name' => 'Tim Dian']);
        $cable = Item::query()->create(['code' => 'KBL003', 'name' => 'KABEL LAN GLOBAL', 'unit' => 'meter']);
        Stock::query()->create(['warehouse_id' => Warehouse::of($this->technician)->id, 'item_id' => $this->adaptor->id, 'quantity' => 2]);
        Stock::query()->create(['warehouse_id' => Warehouse::of($partner)->id, 'item_id' => $cable->id, 'quantity' => 30]);
        $outsider = $this->userWithRole(Role::TEKNISI, ['name' => 'Tim Zaki']);
        Stock::query()->create(['warehouse_id' => Warehouse::of($outsider)->id, 'item_id' => $cable->id, 'quantity' => 30]);
        $ticket = Ticket::factory()->status(TicketStatus::PERBAIKAN)->assignedTo($this->technician, $partner)->create(['repair_started_at' => now()]);
        $helpdesk = $this->userWithRole(Role::HELPDESK);
        $url = "/tickets/{$ticket->ticket_number}/actions/LAPOR_PERBAIKAN_SELESAI";

        // Any technician's warehouse can be drawn on, crew first, without showing how much is left.
        $this->actingAs($this->technician)->get("/tickets/{$ticket->ticket_number}")->assertOk()
            ->assertViewHas('stockHolders', [
                ['id' => $this->technician->id, 'name' => 'Tim Baim · ditugaskan'],
                ['id' => $partner->id, 'name' => 'Tim Dian · ditugaskan'],
                ['id' => $outsider->id, 'name' => 'Tim Zaki'],
            ])
            ->assertViewHas('crewStock', fn (array $stock) => $stock[$outsider->id] === [['code' => 'KBL003', 'name' => 'KABEL LAN GLOBAL', 'serial' => false, 'units' => []]]);
        // Each line needs a technician's warehouse.
        $this->actingAs($helpdesk)->post($url, ['notes' => 'Ganti adaptor', 'materials' => [['code' => 'KBL003', 'quantity' => 5]]])
            ->assertSessionHasErrors('materials');
        $this->post($url, ['notes' => 'Ganti adaptor', 'materials' => [['technician_id' => $helpdesk->id, 'code' => 'KBL003', 'quantity' => 5]]])
            ->assertSessionHasErrors('materials');
        $this->post($url, ['notes' => 'Ganti adaptor', 'materials' => [['technician_id' => $outsider->id, 'code' => 'KBL003', 'quantity' => 50]]])
            ->assertSessionHasErrors(['materials' => "Stok KBL003 di Gudang {$outsider->name} tidak cukup"]);
        // The partner does not carry adaptors.
        $this->post($url, ['notes' => 'Ganti adaptor', 'materials' => [['technician_id' => $partner->id, 'code' => 'ADP001', 'quantity' => 1]]])
            ->assertSessionHasErrors('materials');

        $this->post($url, ['notes' => 'Ganti adaptor dan tarik kabel', 'materials' => [
            ['technician_id' => $this->technician->id, 'code' => 'ADP001', 'quantity' => 1],
            ['technician_id' => $partner->id, 'code' => 'KBL003', 'quantity' => 12],
            ['technician_id' => $outsider->id, 'code' => 'KBL003', 'quantity' => 5],
        ]])->assertSessionHasNoErrors();
        $this->actingAs($this->warehouse)->post("/gudang/pengajuan/{$ticket->materialRequests()->sole()->id}/setujui")->assertSessionHasNoErrors();

        $this->assertSame(1, $this->quantity(Warehouse::of($this->technician), $this->adaptor));
        $this->assertSame(18, $this->quantity(Warehouse::of($partner), $cable));
        $this->assertSame(25, $this->quantity(Warehouse::of($outsider), $cable));
        $notes = $ticket->fresh()->histories->last()->notes;
        $this->assertStringContainsString('stok teknisi berkurang', $notes);
        $this->assertStringContainsString('ADP001 ADAPTOR 12 V 1 A × 1 pcs dari Gudang Tim Baim', $notes);
        $this->assertStringContainsString('KBL003 KABEL LAN GLOBAL × 12 meter dari Gudang Tim Dian', $notes);
    }

    public function test_items_are_imported_from_the_warehouse_code_list(): void
    {
        // A real workbook laid out like "KODE BARANG GUDANG.xlsx".
        $workbook = Excel::raw(new class implements FromArray
        {
            public function array(): array
            {
                return [['NO', 'KODE PRODUK', 'NAMA PRODUK'], [1, 'ADP001', 'ADAPTOR 12 V 1 A (baru)'], [2, 'kbl003', 'KABEL LAN GLOBAL'], [3, '', '']];
            }
        }, ExcelFormat::XLSX);

        $this->actingAs($this->warehouse)->post('/gudang/barang/impor', ['file' => UploadedFile::fake()->createWithContent('kode.xlsx', $workbook)])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Impor selesai: 1 barang baru, 1 diperbarui');

        $this->assertSame('ADAPTOR 12 V 1 A (baru)', $this->adaptor->fresh()->name);
        $this->assertTrue(Item::query()->where('code', 'KBL003')->exists());
    }

    public function test_access_by_role(): void
    {
        $own = Warehouse::of($this->technician);
        $other = Warehouse::of($this->userWithRole(Role::TEKNISI));

        $this->actingAs($this->technician)->get('/gudang')->assertRedirect("/gudang/{$own->id}");
        $this->get("/gudang/{$other->id}")->assertForbidden();
        $this->post('/gudang/masuk', ['item' => 'ADP001', 'quantity' => 1])->assertForbidden();
        $this->get('/gudang/barang')->assertForbidden();

        $this->actingAs($this->userWithRole(Role::HELPDESK))->get('/gudang')->assertOk()->assertDontSee('Barang Masuk');
        $this->actingAs($this->userWithRole(Role::CS))->get('/gudang')->assertForbidden();
    }
}
