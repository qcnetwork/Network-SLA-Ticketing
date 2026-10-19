<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\TelegramLog;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfirmationFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private function awaitingSince(string $resolvedAt, array $attributes = []): Ticket
    {
        return Ticket::factory()->status(TicketStatus::MENUNGGU_KONFIRMASI)
            ->create(['resolved_at' => $resolvedAt] + $attributes);
    }

    public function test_tickets_without_response_for_two_days_are_closed_automatically(): void
    {
        $this->travelTo('2026-10-12 10:00');
        $expired = $this->awaitingSince('2026-10-10 09:59', ['customer_name' => 'Pelanggan Lama']);
        $fresh = $this->awaitingSince('2026-10-10 10:01');
        $other = Ticket::factory()->status(TicketStatus::HELPDESK_CEK)->create(['resolved_at' => '2026-10-01 10:00']);

        $this->artisan('tickets:auto-close')->expectsOutput('1 tiket ditutup otomatis.')->assertSuccessful();

        $expired->refresh();
        $this->assertSame(TicketStatus::TUTUP, $expired->status);
        $this->assertSame('2026-10-12 10:00', $expired->closed_at->format('Y-m-d H:i'));
        $this->assertSame('TUTUP_OTOMATIS', $expired->histories->last()->action->value);
        $this->assertNull($expired->histories->last()->user_id);
        $this->assertSame(TicketStatus::MENUNGGU_KONFIRMASI, $fresh->fresh()->status);
        $this->assertSame(TicketStatus::HELPDESK_CEK, $other->fresh()->status);
        $this->assertStringContainsString('Pelanggan Lama', TelegramLog::query()->latest('id')->value('message'));

        $this->artisan('tickets:auto-close')->expectsOutput('0 tiket ditutup otomatis.');
        $this->assertSame(1, TelegramLog::query()->count());

        // Auto-close is a system action and can't be triggered from the ticket page.
        $this->actingAs($this->userWithRole(Role::SPV))
            ->post("/tickets/{$fresh->ticket_number}/actions/TUTUP_OTOMATIS")->assertNotFound();
    }

    public function test_reminder_lists_tickets_awaiting_confirmation(): void
    {
        $this->travelTo('2026-10-12 09:00');

        $this->artisan('tickets:remind-confirmations')->expectsOutput('Tidak ada tiket yang menunggu konfirmasi.');
        $this->assertSame(0, TelegramLog::query()->count());

        $this->awaitingSince('2026-10-11 15:30', ['customer_name' => 'Pelanggan <A>']);
        $this->awaitingSince('2026-10-12 08:00');
        Ticket::factory()->status(TicketStatus::TUTUP)->create(['customer_name' => 'Sudah Tutup']);

        $this->artisan('tickets:remind-confirmations')->expectsOutput('Reminder dikirim untuk 2 tiket.');

        $message = TelegramLog::query()->sole()->message;
        $this->assertStringContainsString('2 tiket', $message);
        $this->assertStringContainsString('Pelanggan &lt;A&gt;', $message);
        $this->assertStringContainsString('tutup otomatis 13/10 15:30', $message);
        $this->assertStringNotContainsString('Sudah Tutup', $message);
    }
}
