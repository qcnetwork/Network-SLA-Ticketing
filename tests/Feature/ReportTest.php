<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Exports\MonthlyReportExport;
use App\Models\Ticket;
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
