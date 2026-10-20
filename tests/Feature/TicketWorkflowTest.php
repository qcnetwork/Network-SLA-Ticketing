<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\TelegramLog;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Services\MonthlyReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $cs;

    private User $helpdesk;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cs = $this->userWithRole(Role::CS);
        $this->helpdesk = $this->userWithRole(Role::HELPDESK);
        $this->technician = $this->userWithRole(Role::TEKNISI);
    }

    /**
     * Creates a ticket as CS and, unless told otherwise, forwards it to the helpdesk queue.
     */
    private function createTicket(array $overrides = [], bool $forward = true): Ticket
    {
        $this->actingAs($this->cs)->post('/tickets', $overrides + [
            'customer_name' => 'Smoke Customer',
            'customer_phone' => '0812-3456-7890',
            'category' => Ticket::CATEGORIES[0],
            'priority' => 'SEDANG',
            'complaint' => 'Internet mati sejak pagi',
        ])->assertSessionHasNoErrors();

        $ticket = Ticket::query()->latest('id')->firstOrFail();
        if ($forward) {
            $this->act($this->cs, $ticket, 'TERUSKAN_HELPDESK')->assertSessionHasNoErrors();
        }

        return $ticket->fresh();
    }

    private function act(User $user, Ticket $ticket, string $action, array $data = [])
    {
        return $this->actingAs($user)->post("/tickets/{$ticket->ticket_number}/actions/{$action}", $data);
    }

    public function test_cs_creates_ticket_that_stays_with_cs_until_forwarded(): void
    {
        $ticket = $this->createTicket(forward: false);

        $this->assertMatchesRegularExpression('/^TCK-\d{8}-0001$/', $ticket->ticket_number);
        $this->assertSame(TicketStatus::PROSES_CS, $ticket->status);
        $this->assertSame('6281234567890', $ticket->customer_phone);
        $this->assertSame($this->cs->id, $ticket->created_by_id);
        $this->assertCount(1, $ticket->histories);
        // The helpdesk group is not pinged while CS is still coordinating with the customer.
        $this->assertSame(0, TelegramLog::query()->count());

        // Helpdesk cannot pick up the ticket and CS cannot ping the group before it is forwarded.
        $this->act($this->helpdesk, $ticket, 'KLAIM_HELPDESK')->assertSessionHasErrors('action');
        $this->act($this->helpdesk, $ticket, 'TERUSKAN_HELPDESK')->assertForbidden();
        $this->act($this->cs, $ticket, 'CATATAN_URGENSI_CS', ['notes' => 'Follow up'])->assertSessionHasErrors('action');
        $this->actingAs($this->cs)->get("/tickets/{$ticket->ticket_number}")->assertOk()
            ->assertSee('Teruskan ke Antrian Helpdesk')->assertDontSee('Catatan Urgensi');
        $this->actingAs($this->helpdesk)->get("/tickets/{$ticket->ticket_number}")->assertOk()->assertDontSee('Klaim & Mulai Cek', false);

        $this->act($this->cs, $ticket, 'TERUSKAN_HELPDESK', ['notes' => 'Lampu LOS merah'])->assertSessionHasNoErrors();
        $ticket->refresh();
        $this->assertSame(TicketStatus::ANTRIAN_HELPDESK, $ticket->status);
        $this->assertSame('TERUSKAN_HELPDESK', $ticket->histories->last()->action->value);
        $message = TelegramLog::query()->sole()->message;
        $this->assertStringContainsString('TIKET BARU MASUK', $message);
        $this->assertStringContainsString('Lampu LOS merah', $message);

        $this->act($this->cs, $ticket, 'TERUSKAN_HELPDESK')->assertSessionHasErrors('action');
        $this->act($this->helpdesk, $ticket, 'KLAIM_HELPDESK')->assertSessionHasNoErrors();

        $this->assertSame('TCK-'.now()->format('Ymd').'-0002', $this->createTicket()->ticket_number);
    }

    public function test_cs_can_close_ticket_by_education_before_forwarding(): void
    {
        $ticket = $this->createTicket(forward: false);

        $this->act($this->cs, $ticket, 'SELESAI_EDUKASI_CS', ['notes' => 'Restart modem, normal'])->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame(TicketStatus::TUTUP, $ticket->status);
        $this->assertSame(0, TelegramLog::query()->count());
    }

    public function test_ticket_creation_is_validated_and_role_limited(): void
    {
        $this->actingAs($this->cs)->post('/tickets', ['customer_name' => '', 'customer_phone' => 'abc'])
            ->assertSessionHasErrors(['customer_name', 'customer_phone', 'complaint', 'category', 'priority']);

        $this->actingAs($this->technician)->post('/tickets', [
            'customer_name' => 'No', 'customer_phone' => '08123456789', 'category' => Ticket::CATEGORIES[0],
            'priority' => 'SEDANG', 'complaint' => 'Not allowed',
        ])->assertForbidden();
    }

    public function test_remote_resolution_flow_until_closed(): void
    {
        $ticket = $this->createTicket();

        $this->act($this->cs, $ticket, 'KLAIM_HELPDESK')->assertForbidden();
        $this->act($this->helpdesk, $ticket, 'KLAIM_HELPDESK')->assertSessionHasNoErrors();
        $this->assertSame($this->helpdesk->id, $ticket->fresh()->assigned_helpdesk_id);

        $this->act($this->helpdesk, $ticket, 'SELESAI_REMOTE', ['notes' => 'Reboot ONT'])->assertSessionHasNoErrors();
        $this->act($this->helpdesk, $ticket, 'KONFIRMASI_NORMAL')->assertForbidden();
        $this->act($this->cs, $ticket, 'KONFIRMASI_NORMAL', ['notes' => 'Sudah normal'])->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame(TicketStatus::TUTUP, $ticket->status);
        $this->assertSame('Reboot ONT', $ticket->remote_resolution_notes);
        $this->assertNotNull($ticket->closed_at);
        $this->assertSame(
            ['TIKET_DIBUAT', 'TERUSKAN_HELPDESK', 'KLAIM_HELPDESK', 'SELESAI_REMOTE', 'KONFIRMASI_NORMAL'],
            $ticket->histories->pluck('action')->map->value->all(),
        );

        $this->act($this->cs, $ticket, 'CATATAN_URGENSI_CS', ['notes' => 'Late'])->assertSessionHasErrors('action');
    }

    public function test_cs_can_close_queued_ticket_after_customer_education(): void
    {
        $ticket = $this->createTicket();

        $this->actingAs($this->cs)->get("/tickets/{$ticket->ticket_number}")->assertOk()->assertSee('Selesai dengan Edukasi');
        $this->act($this->helpdesk, $ticket, 'SELESAI_EDUKASI_CS', ['notes' => 'x'])->assertForbidden();
        $this->act($this->cs, $ticket, 'SELESAI_EDUKASI_CS')->assertSessionHasErrors('notes');
        $this->act($this->cs, $ticket, 'SELESAI_EDUKASI_CS', ['notes' => 'Cabut tancap adaptor ONT'])->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame(TicketStatus::TUTUP, $ticket->status);
        $this->assertSame('Cabut tancap adaptor ONT', $ticket->confirmation_notes);
        $this->assertNotNull($ticket->closed_at);
        $this->assertSame('SELESAI_EDUKASI_CS', $ticket->histories->last()->action->value);

        // Once Helpdesk has claimed the ticket, CS can no longer close it on their own.
        $claimed = $this->createTicket();
        $this->act($this->helpdesk, $claimed, 'KLAIM_HELPDESK');
        $this->act($this->cs, $claimed, 'SELESAI_EDUKASI_CS', ['notes' => 'Late'])->assertSessionHasErrors('action');
        $this->assertSame(TicketStatus::HELPDESK_CEK, $claimed->fresh()->status);
    }

    public function test_helpdesk_check_records_customer_modem(): void
    {
        $ticket = $this->createTicket();
        $this->act($this->helpdesk, $ticket, 'KLAIM_HELPDESK');

        $this->actingAs($this->helpdesk)->get("/tickets/{$ticket->ticket_number}")->assertOk()->assertSee('Modem / ONT Pelanggan');
        $this->act($this->helpdesk, $ticket, 'PERLU_TEKNISI', ['technician_ids' => [$this->technician->id], 'modem' => ' ZTE F609 '])
            ->assertSessionHasNoErrors();

        $this->assertSame('ZTE F609', $ticket->fresh()->modem);
        $this->assertStringContainsString('ZTE F609', TelegramLog::query()->latest('id')->value('message'));
        $this->actingAs($this->technician)->get("/tickets/{$ticket->ticket_number}")->assertOk()->assertSee('ZTE F609');

        // A later check without a modem value keeps what was recorded before.
        $this->act($this->technician, $ticket, 'MULAI_PERBAIKAN');
        $this->act($this->technician, $ticket, 'LAPOR_PERBAIKAN_SELESAI', ['notes' => 'Ganti konektor']);
        $this->act($this->cs, $ticket, 'KONFIRMASI_TERKENDALA');
        $this->act($this->helpdesk, $ticket, 'SELESAI_REMOTE', ['notes' => 'Reboot', 'modem' => ''])->assertSessionHasNoErrors();
        $this->assertSame('ZTE F609', $ticket->fresh()->modem);
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $ticket = $this->createTicket();

        $this->act($this->helpdesk, $ticket, 'SELESAI_REMOTE')->assertSessionHasErrors('action');
        $this->act($this->cs, $ticket, 'KONFIRMASI_NORMAL')->assertSessionHasErrors('action');
        $this->act($this->helpdesk, $ticket, 'TIDAK_ADA')->assertNotFound();
        $this->act($this->helpdesk, $ticket, 'TIKET_DIBUAT')->assertNotFound();
        $this->assertSame(TicketStatus::ANTRIAN_HELPDESK, $ticket->fresh()->status);
    }

    public function test_technician_repair_flow_and_recheck_cycle(): void
    {
        $ticket = $this->createTicket();
        $this->act($this->helpdesk, $ticket, 'KLAIM_HELPDESK');

        $this->act($this->helpdesk, $ticket, 'PERLU_TEKNISI')->assertSessionHasErrors('technician_ids');
        $this->act($this->helpdesk, $ticket, 'PERLU_TEKNISI', ['technician_ids' => [$this->cs->id]])->assertSessionHasErrors('technician_ids');
        $this->act($this->helpdesk, $ticket, 'PERLU_TEKNISI', ['technician_ids' => [$this->technician->id], 'notes' => 'Cek ODP'])
            ->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::PERBAIKAN, $ticket->fresh()->status);

        $this->act($this->technician, $ticket, 'LAPOR_PERBAIKAN_SELESAI', ['notes' => 'Too early'])->assertSessionHasErrors('action');
        $this->act($this->technician, $ticket, 'MULAI_PERBAIKAN')->assertSessionHasNoErrors();
        $this->act($this->technician, $ticket, 'MULAI_PERBAIKAN')->assertSessionHasErrors('action');
        $this->act($this->technician, $ticket, 'LAPOR_PERBAIKAN_SELESAI', ['notes' => 'Ganti konektor'])
            ->assertRedirect('/dashboard');

        $this->act($this->cs, $ticket, 'KONFIRMASI_TERKENDALA', ['notes' => 'Masih putus'])->assertSessionHasNoErrors();
        $ticket->refresh();
        $this->assertSame(TicketStatus::CEK_ULANG, $ticket->status);
        $this->assertNull($ticket->resolved_at);

        // Regression: the second repair cycle must start fresh (old app kept repairStartedAt).
        $this->act($this->helpdesk, $ticket, 'PERLU_TEKNISI', ['technician_ids' => [$this->technician->id]])->assertSessionHasNoErrors();
        $this->assertNull($ticket->fresh()->repair_started_at);
        $this->act($this->technician, $ticket, 'LAPOR_PERBAIKAN_SELESAI', ['notes' => 'Skip start'])->assertSessionHasErrors('action');
        $this->act($this->technician, $ticket, 'MULAI_PERBAIKAN')->assertSessionHasNoErrors();
        $this->act($this->technician, $ticket, 'LAPOR_PERBAIKAN_SELESAI', ['notes' => 'Splicing ulang'])->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::MENUNGGU_KONFIRMASI, $ticket->fresh()->status);

        $this->createTicket();
        $report = app(MonthlyReportService::class)->build(now()->year, now()->month);
        $this->assertSame(2, $report['total']);
        $this->assertSame(1, $report['rechecked']);
    }

    public function test_repair_can_be_assigned_to_several_technicians(): void
    {
        $partner = $this->userWithRole(Role::TEKNISI);
        $outsider = $this->userWithRole(Role::TEKNISI);
        $ticket = $this->createTicket();
        $this->act($this->helpdesk, $ticket, 'KLAIM_HELPDESK');

        $this->act($this->helpdesk, $ticket, 'PERLU_TEKNISI', ['technician_ids' => [$this->technician->id, $this->cs->id]])
            ->assertSessionHasErrors('technician_ids');
        $this->assertSame(TicketStatus::HELPDESK_CEK, $ticket->fresh()->status);

        // A blank dropdown row left in the form is ignored.
        $this->act($this->helpdesk, $ticket, 'PERLU_TEKNISI', ['technician_ids' => [$this->technician->id, '', $partner->id]])
            ->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$this->technician->id, $partner->id], $ticket->technicians()->pluck('users.id')->all());
        $message = TelegramLog::query()->latest('id')->value('message');
        $this->assertStringContainsString($this->technician->name, $message);
        $this->assertStringContainsString($partner->name, $message);

        $this->actingAs($this->technician)->get("/tickets/{$ticket->ticket_number}")->assertOk();
        $this->actingAs($outsider)->get("/tickets/{$ticket->ticket_number}")->assertForbidden();
        $this->act($outsider, $ticket, 'MULAI_PERBAIKAN')->assertForbidden();

        $this->act($partner, $ticket, 'MULAI_PERBAIKAN')->assertSessionHasNoErrors();
        $this->act($this->technician, $ticket, 'LAPOR_PERBAIKAN_SELESAI', ['notes' => 'Ganti konektor'])->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::MENUNGGU_KONFIRMASI, $ticket->fresh()->status);

        $report = app(MonthlyReportService::class)->build(now()->year, now()->month);
        $this->assertSame(1, $report['technician_assigned']);
        $this->assertEqualsCanonicalizing([$this->technician->name, $partner->name], $report['technicians']->pluck('name')->all());
    }

    public function test_helpdesk_can_record_repair_progress_for_the_technician(): void
    {
        Storage::fake(TicketAttachment::DISK);
        $ticket = $this->createTicket();
        $this->act($this->helpdesk, $ticket, 'KLAIM_HELPDESK');
        $this->act($this->helpdesk, $ticket, 'PERLU_TEKNISI', ['technician_ids' => [$this->technician->id]]);

        $this->act($this->cs, $ticket, 'MULAI_PERBAIKAN')->assertForbidden();
        $this->actingAs($this->helpdesk)->get("/tickets/{$ticket->ticket_number}")->assertSee('Teknisi Sudah di Lokasi');

        $this->act($this->helpdesk, $ticket, 'MULAI_PERBAIKAN')->assertSessionHasNoErrors();
        $started = $ticket->histories()->reorder()->latest('id')->value('notes');
        $this->assertStringContainsString("Teknisi [{$this->technician->name}]", $started);
        $this->assertStringContainsString("Dicatat oleh Helpdesk {$this->helpdesk->name}", $started);
        $this->assertStringContainsString($this->technician->name, TelegramLog::query()->latest('id')->value('message'));

        $this->act($this->helpdesk, $ticket, 'LAPOR_PERBAIKAN_SELESAI', [
            'notes' => 'Ganti konektor di roset',
            'photos' => [UploadedFile::fake()->image('roset.jpg')],
        ])->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame(TicketStatus::MENUNGGU_KONFIRMASI, $ticket->status);
        $this->assertSame('Ganti konektor di roset', $ticket->technician_notes);
        $report = $ticket->histories()->reorder()->latest('id')->first();
        $this->assertStringContainsString("Teknisi [{$this->technician->name}] menyelesaikan perbaikan: Ganti konektor di roset", $report->notes);
        $this->assertSame($this->helpdesk->id, $report->user_id);
        $this->assertCount(1, $report->attachments);
    }

    public function test_helpdesk_can_reassign_technicians_during_repair(): void
    {
        $partner = $this->userWithRole(Role::TEKNISI);
        $replacement = $this->userWithRole(Role::TEKNISI);
        $ticket = $this->createTicket();
        $this->act($this->helpdesk, $ticket, 'KLAIM_HELPDESK');

        // Only a ticket already in repair can be reassigned.
        $this->act($this->helpdesk, $ticket, 'UBAH_TEKNISI', ['technician_ids' => [$partner->id], 'notes' => 'x'])->assertSessionHasErrors('action');

        $this->act($this->helpdesk, $ticket, 'PERLU_TEKNISI', ['technician_ids' => [$this->technician->id]]);
        $this->act($this->technician, $ticket, 'MULAI_PERBAIKAN');

        $this->act($this->technician, $ticket, 'UBAH_TEKNISI', ['technician_ids' => [$partner->id], 'notes' => 'x'])->assertForbidden();
        $this->act($this->helpdesk, $ticket, 'UBAH_TEKNISI', ['technician_ids' => [$partner->id]])->assertSessionHasErrors('notes');
        $this->act($this->helpdesk, $ticket, 'UBAH_TEKNISI', ['technician_ids' => [$this->technician->id], 'notes' => 'Sama'])
            ->assertSessionHasErrors('technician_ids');

        // Adding a helper keeps the repair that is already under way.
        $this->act($this->helpdesk, $ticket, 'UBAH_TEKNISI', ['technician_ids' => [$this->technician->id, $partner->id], 'notes' => 'Butuh tenaga tambahan'])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($ticket->fresh()->repair_started_at);
        $this->assertStringContainsString($partner->name, $ticket->histories()->reorder()->latest('id')->value('notes'));

        // Replacing the whole crew moves access to the new technician, who starts over.
        $this->act($this->helpdesk, $ticket, 'UBAH_TEKNISI', ['technician_ids' => [$replacement->id], 'notes' => 'Teknisi sakit'])
            ->assertSessionHasNoErrors();
        $ticket->refresh();
        $this->assertSame(TicketStatus::PERBAIKAN, $ticket->status);
        $this->assertNull($ticket->repair_started_at);
        $this->assertSame([$replacement->id], $ticket->technicians()->pluck('users.id')->all());
        $this->assertStringContainsString('Teknisi sakit', $ticket->histories()->reorder()->latest('id')->value('notes'));
        $this->actingAs($this->technician)->get("/tickets/{$ticket->ticket_number}")->assertForbidden();
        $this->act($replacement, $ticket, 'MULAI_PERBAIKAN')->assertSessionHasNoErrors();
    }

    public function test_cs_urgency_note_can_raise_priority(): void
    {
        $ticket = $this->createTicket(['priority' => 'RENDAH']);

        $this->act($this->cs, $ticket, 'CATATAN_URGENSI_CS', ['notes' => ''])->assertSessionHasErrors('notes');
        $this->act($this->cs, $ticket, 'CATATAN_URGENSI_CS', [
            'notes' => 'Pelanggan follow up <b>lagi</b>', 'urgency_level' => 'KRITIS', 'upgrade_priority' => '1',
        ])->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame(TicketPriority::TINGGI, $ticket->priority);
        $this->assertSame(TicketStatus::ANTRIAN_HELPDESK, $ticket->status);
        $this->assertSame('KRITIS', $ticket->histories->last()->urgency_level);

        $message = TelegramLog::query()->latest('id')->value('message');
        $this->assertStringContainsString('URGENSI KRITIS', $message);
        $this->assertStringContainsString('&lt;b&gt;lagi&lt;/b&gt;', $message);
    }

    public function test_only_supervisor_deletes_ticket_with_its_history_and_photos(): void
    {
        Storage::fake(TicketAttachment::DISK);
        $ticket = $this->createTicket();
        $this->act($this->helpdesk, $ticket, 'CATATAN_DOKUMENTASI', [
            'notes' => 'Foto ONT',
            'photos' => [UploadedFile::fake()->image('ont.jpg')],
        ])->assertSessionHasNoErrors();
        $photoPath = $ticket->attachments()->value('path');

        foreach ([$this->cs, $this->helpdesk, $this->technician] as $user) {
            $this->actingAs($user)->delete("/tickets/{$ticket->ticket_number}")->assertForbidden();
        }
        $this->actingAs($this->cs)->get("/tickets/{$ticket->ticket_number}")->assertDontSee('Hapus Tiket');

        $supervisor = $this->userWithRole(Role::SPV);
        $this->actingAs($supervisor)->get("/tickets/{$ticket->ticket_number}")->assertSee('Hapus Tiket');
        $this->delete("/tickets/{$ticket->ticket_number}")
            ->assertRedirect(route('dashboard'))->assertSessionHas('success');

        $this->assertModelMissing($ticket);
        $this->assertDatabaseMissing('ticket_histories', ['ticket_id' => $ticket->id]);
        $this->assertDatabaseMissing('ticket_attachments', ['ticket_id' => $ticket->id]);
        Storage::disk(TicketAttachment::DISK)->assertMissing($photoPath);
    }

    public function test_documentation_note_stores_photos_privately_without_telegram(): void
    {
        Storage::fake(TicketAttachment::DISK);
        $ticket = $this->createTicket();

        $this->act($this->helpdesk, $ticket, 'CATATAN_DOKUMENTASI')->assertSessionHasErrors('photos');
        $this->act($this->helpdesk, $ticket, 'CATATAN_DOKUMENTASI', ['photos' => [UploadedFile::fake()->create('virus.pdf')]])
            ->assertSessionHasErrors('photos.0');
        $this->act($this->helpdesk, $ticket, 'CATATAN_DOKUMENTASI', [
            'notes' => 'Redaman ODP -27 dBm',
            'photos' => [UploadedFile::fake()->image('odp.jpg'), UploadedFile::fake()->image('ont.png')],
        ])->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame(TicketStatus::ANTRIAN_HELPDESK, $ticket->status);
        $this->assertFalse(TelegramLog::query()->where('message', 'like', '%Redaman ODP%')->exists());

        $history = $ticket->histories->last();
        $this->assertSame('CATATAN_DOKUMENTASI', $history->action->value);
        $this->assertSame('Redaman ODP -27 dBm', $history->notes);
        $this->assertSame(['odp.jpg', 'ont.png'], $history->attachments->pluck('original_name')->all());

        $attachment = $history->attachments->first();
        Storage::disk(TicketAttachment::DISK)->assertExists($attachment->path);

        $this->actingAs($this->cs)->get("/tickets/{$ticket->ticket_number}")
            ->assertOk()->assertSee(route('tickets.attachment', [$ticket, $attachment]), false);
        $this->get("/tickets/{$ticket->ticket_number}/attachments/{$attachment->id}")->assertOk();
        $this->actingAs($this->technician)->get("/tickets/{$ticket->ticket_number}/attachments/{$attachment->id}")
            ->assertForbidden();

        $otherTicket = $this->createTicket();
        $this->get("/tickets/{$otherTicket->ticket_number}/attachments/{$attachment->id}")->assertNotFound();
    }

    public function test_documentation_note_accepts_videos_with_their_own_size_limit(): void
    {
        Storage::fake(TicketAttachment::DISK);
        $ticket = $this->createTicket();

        $this->act($this->helpdesk, $ticket, 'CATATAN_DOKUMENTASI', ['photos' => [UploadedFile::fake()->image('odp.jpg')->size(6000)]])
            ->assertSessionHasErrors(['photos.0' => 'Ukuran foto maksimal 5 MB']);
        $this->act($this->helpdesk, $ticket, 'CATATAN_DOKUMENTASI', ['photos' => [UploadedFile::fake()->create('panjang.mp4', 52000, 'video/mp4')]])
            ->assertSessionHasErrors(['photos.0' => 'Ukuran video maksimal 50 MB']);

        $upload = UploadedFile::fake()->create('redaman.mp4', 4, 'video/mp4');
        file_put_contents($upload->getPathname(), str_repeat('0', 4096));
        $this->act($this->helpdesk, $ticket, 'CATATAN_DOKUMENTASI', ['photos' => [$upload]])->assertSessionHasNoErrors();

        $video = $ticket->histories->last()->attachments->sole();
        $this->assertTrue($video->isVideo());
        $url = route('tickets.attachment', [$ticket, $video]);

        $this->actingAs($this->cs)->get("/tickets/{$ticket->ticket_number}")->assertSee('<video src="'.$url.'"', false);
        $this->get($url, ['Range' => 'bytes=0-1023'])->assertStatus(206)->assertHeader('Content-Type', 'video/mp4');
    }

    public function test_helpdesk_and_assigned_technician_can_correct_the_complaint_category(): void
    {
        $ticket = $this->createTicket(['category' => 'Internet Lambat / Ping Tinggi']);
        $this->act($this->cs, $ticket, 'UBAH_KATEGORI', ['category' => 'Lainnya'])->assertForbidden();
        $this->actingAs($this->helpdesk)->get("/tickets/{$ticket->ticket_number}")->assertSee('Ubah Kategori Kendala');

        $this->act($this->helpdesk, $ticket, 'UBAH_KATEGORI', ['category' => 'Internet Lambat / Ping Tinggi'])->assertSessionHasErrors('category');
        $this->act($this->helpdesk, $ticket, 'UBAH_KATEGORI', ['category' => 'Bukan kategori'])->assertSessionHasErrors('category');

        $this->act($this->helpdesk, $ticket, 'KLAIM_HELPDESK');
        $this->act($this->helpdesk, $ticket, 'PERLU_TEKNISI', ['technician_ids' => [$this->technician->id]]);
        $this->act($this->technician, $ticket, 'UBAH_KATEGORI', [
            'category' => 'LOS Merah / Kabel Putus',
            'notes' => 'Kabel drop putus di tiang',
        ])->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame('LOS Merah / Kabel Putus', $ticket->category);
        $this->assertSame(TicketStatus::PERBAIKAN, $ticket->status);
        $this->assertSame(
            "Kategori kendala diubah oleh {$this->technician->name}: \"Internet Lambat / Ping Tinggi\" → \"LOS Merah / Kabel Putus\". Alasan: Kabel drop putus di tiang",
            $ticket->histories()->reorder()->latest('id')->value('notes'),
        );

        $other = $this->createTicket();
        $this->act($this->technician, $other, 'UBAH_KATEGORI', ['category' => 'Lainnya'])->assertForbidden();
    }

    public function test_closed_ticket_category_cannot_be_changed(): void
    {
        $ticket = $this->createTicket();
        $this->act($this->cs, $ticket, 'SELESAI_EDUKASI_CS', ['notes' => 'Restart modem']);

        $this->act($this->helpdesk, $ticket, 'UBAH_KATEGORI', ['category' => 'Lainnya'])->assertSessionHasErrors('category');
        $this->assertSame(Ticket::CATEGORIES[0], $ticket->refresh()->category);
    }

    public function test_technician_repair_report_can_include_photos(): void
    {
        Storage::fake(TicketAttachment::DISK);
        $ticket = $this->createTicket();
        $this->act($this->helpdesk, $ticket, 'KLAIM_HELPDESK');
        $this->act($this->helpdesk, $ticket, 'PERLU_TEKNISI', ['technician_ids' => [$this->technician->id]]);
        $this->act($this->technician, $ticket, 'MULAI_PERBAIKAN');

        $this->act($this->technician, $ticket, 'LAPOR_PERBAIKAN_SELESAI', [
            'notes' => 'Ganti konektor',
            'photos' => [UploadedFile::fake()->image('konektor.jpg')],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['konektor.jpg'], $ticket->histories->last()->attachments->pluck('original_name')->all());
    }

    public function test_whatsapp_link_and_ticket_pages_render(): void
    {
        $ticket = $this->createTicket();

        $this->actingAs($this->cs)->get("/tickets/{$ticket->ticket_number}/whatsapp?type=tiket_dibuat")
            ->assertRedirectContains('https://wa.me/6281234567890?text=');
        $this->get("/tickets/{$ticket->ticket_number}/whatsapp?type=unknown")->assertNotFound();
        $this->actingAs($this->technician)->get("/tickets/{$ticket->ticket_number}/whatsapp")->assertForbidden();

        $this->actingAs($this->helpdesk)->get("/tickets/{$ticket->ticket_number}")
            ->assertOk()->assertSee('Klaim & Mulai Cek', false);
        $this->get('/dashboard?view=table&search=Smoke')->assertOk()->assertSee($ticket->ticket_number);
        $this->get('/reports')->assertOk()->assertSee('Smoke Customer');
        $this->get('/telegram-logs')->assertOk()->assertSee('TIKET BARU MASUK');
    }

    public function test_notifications_can_be_disabled(): void
    {
        $this->actingAs($this->userWithRole(Role::SPV))->put('/settings', []);

        $this->createTicket();

        $this->assertSame(0, TelegramLog::query()->count());
    }
}
