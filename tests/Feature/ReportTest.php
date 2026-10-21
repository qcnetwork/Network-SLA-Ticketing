<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Exports\MonthlyReportExport;
use App\Models\Ticket;
use App\Services\MonthlyReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_report_only_counts_tickets_created_that_day(): void
    {
        $today = Ticket::factory()->status(TicketStatus::TUTUP)->create(['created_at' => '2026-10-15 23:59:00']);
        $yesterday = Ticket::factory()->create(['created_at' => '2026-10-14 23:59:59']);
        $tomorrow = Ticket::factory()->create(['created_at' => '2026-10-16 00:00:00']);

        $this->actingAs($this->userWithRole(Role::SPV))->get('/reports?period=daily&date=2026-10-15')
            ->assertOk()
            ->assertSee('Laporan Harian')
            ->assertSee('Periode 15 Oktober 2026')
            ->assertSee($today->ticket_number)
            ->assertDontSee($yesterday->ticket_number)
            ->assertDontSee($tomorrow->ticket_number);

        $this->get('/reports?period=daily&date=15-10-2026')->assertSessionHasErrors('date');
    }

    /**
     * @param  array<string>  $actions  history actions in order
     */
    private function ticketResolvedBy(TicketStatus $status, array $actions): Ticket
    {
        $ticket = Ticket::factory()->status($status)->create(['created_at' => '2026-10-15 09:00:00']);
        foreach ($actions as $action) {
            $ticket->histories()->create(['actor_name' => 'Uji', 'action' => $action, 'to_status' => $status]);
        }

        return $ticket;
    }

    public function test_report_splits_tickets_by_who_closed_them_and_pending(): void
    {
        Excel::fake();
        $this->ticketResolvedBy(TicketStatus::TUTUP, ['TIKET_DIBUAT', 'SELESAI_EDUKASI_CS']);
        $this->ticketResolvedBy(TicketStatus::TUTUP, ['TIKET_DIBUAT', 'KLAIM_HELPDESK', 'SELESAI_REMOTE', 'KONFIRMASI_NORMAL']);
        // Remote fix did not hold; the technician's later repair is what closed it.
        $this->ticketResolvedBy(TicketStatus::TUTUP, ['SELESAI_REMOTE', 'KONFIRMASI_TERKENDALA', 'PERLU_TEKNISI', 'LAPOR_PERBAIKAN_SELESAI', 'KONFIRMASI_NORMAL']);
        // No customer reply: closed automatically, still credited to the technician.
        $this->ticketResolvedBy(TicketStatus::TUTUP, ['PERLU_TEKNISI', 'LAPOR_PERBAIKAN_SELESAI', 'TUTUP_OTOMATIS']);
        $this->ticketResolvedBy(TicketStatus::TUTUP, ['GABUNG_GAMAS', 'GAMAS_SELESAI', 'KONFIRMASI_NORMAL']);
        $this->ticketResolvedBy(TicketStatus::MENUNGGU_KONFIRMASI, ['SELESAI_REMOTE']);
        $this->ticketResolvedBy(TicketStatus::PROSES_CS, ['TIKET_DIBUAT']);
        // Resolved once, then reopened: it is back in progress, not closed.
        $this->ticketResolvedBy(TicketStatus::CEK_ULANG, ['SELESAI_REMOTE', 'KONFIRMASI_TERKENDALA']);

        $report = app(MonthlyReportService::class)->build(2026, 10);
        $closure = $report['closure']->keyBy('key');

        $this->assertSame(8, $report['total']);
        $this->assertSame([1, 13], [$closure['closed_cs']['count'], $closure['closed_cs']['percentage']]);
        $this->assertSame([1, 13], [$closure['closed_helpdesk']['count'], $closure['closed_helpdesk']['percentage']]);
        $this->assertSame([2, 25], [$closure['closed_teknisi']['count'], $closure['closed_teknisi']['percentage']]);
        $this->assertSame([1, 13], [$closure['closed_gamas']['count'], $closure['closed_gamas']['percentage']]);
        $this->assertSame([1, 13], [$closure['pending']['count'], $closure['pending']['percentage']]);
        $this->assertSame([2, 25], [$closure['in_progress']['count'], $closure['in_progress']['percentage']]);
        $this->assertFalse($closure->has('closed_other'));
        $this->assertSame(8, $report['closure']->sum('count'));

        $this->actingAs($this->userWithRole(Role::SPV))->get('/reports?year=2026&month=10')->assertOk()
            ->assertSee('Penyelesaian Tiket')
            ->assertSee('Ditutup oleh Teknisi (perbaikan lapangan)')
            ->assertSee('Pending: menunggu respon pelanggan');

        $this->get('/reports/export?year=2026&month=10')->assertOk();
        Excel::assertDownloaded('Laporan_Ticketing_2026_10.xlsx', function (MonthlyReportExport $export) {
            [$summary] = $export->sheets();

            return in_array(['Ditutup oleh CS (edukasi)', 1, '13%'], $summary->array(), true)
                && in_array(['Pending (Menunggu Respon Pelanggan)', 1], $summary->array(), true);
        });
    }

    public function test_closed_ticket_without_a_resolving_step_is_listed_as_other(): void
    {
        $this->ticketResolvedBy(TicketStatus::TUTUP, ['TIKET_DIBUAT']);

        $closure = app(MonthlyReportService::class)->build(2026, 10)['closure']->keyBy('key');

        $this->assertSame(1, $closure['closed_other']['count']);
    }

    public function test_daily_report_exports_that_day(): void
    {
        Excel::fake();
        Ticket::factory()->create(['created_at' => '2026-10-15 08:00:00']);
        Ticket::factory()->create(['created_at' => '2026-10-16 08:00:00']);

        $this->actingAs($this->userWithRole(Role::SPV))->get('/reports/export?period=daily&date=2026-10-15')->assertOk();

        Excel::assertDownloaded('Laporan_Ticketing_Harian_2026_10_15.xlsx', function (MonthlyReportExport $export) {
            [$summary] = $export->sheets();

            return $summary->title() === 'Ringkasan Harian' && $summary->array()[4] === ['Total Tiket Masuk', 1];
        });
    }
}
