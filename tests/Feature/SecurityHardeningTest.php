<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Exports\MonthlyReportExport;
use App\Models\Setting;
use App\Models\TelegramLog;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Services\MonthlyReportService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_telegram_call_never_stores_the_bot_token(): void
    {
        $token = '123456:SECRET-token';
        Setting::write(['bot_token' => $token, 'chat_id' => '-100123']);
        Http::fake(fn () => throw new ConnectionException("cURL error 6: Could not resolve host for https://api.telegram.org/bot{$token}/sendMessage"));

        $result = app(TelegramService::class)->send('Tes');

        $this->assertFalse($result['success']);
        $this->assertStringNotContainsString($token, $result['error']);
        $this->assertStringNotContainsString($token, TelegramLog::query()->latest('id')->value('error'));
    }

    public function test_report_export_writes_formula_like_text_as_plain_text(): void
    {
        Ticket::factory()->create(['customer_name' => '=HYPERLINK("http://evil.test","klik")']);
        $report = app(MonthlyReportService::class)->build(now()->year, now()->month);

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, Excel::raw(new MonthlyReportExport($report), \Maatwebsite\Excel\Excel::XLSX));
        $cell = IOFactory::load($path)->getSheetByName('Data Tiket Lengkap')->getCell('C2');
        unlink($path);

        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertSame('=HYPERLINK("http://evil.test","klik")', $cell->getValue());
    }

    public function test_only_the_primary_supervisor_can_edit_the_primary_account(): void
    {
        $primary = $this->userWithRole(Role::SPV, ['username' => User::PRIMARY_SUPERVISOR]);
        $otherSupervisor = $this->userWithRole(Role::SPV);
        $update = ['name' => $primary->name, 'role' => 'SPV', 'active' => '1', 'password' => 'takeover123'];

        $this->actingAs($otherSupervisor)->get("/users/{$primary->id}/edit")->assertForbidden();
        $this->put("/users/{$primary->id}", $update)->assertForbidden();
        $this->assertFalse(password_verify('takeover123', $primary->fresh()->password));

        $this->actingAs($primary)->put("/users/{$primary->id}", $update)->assertSessionHasNoErrors();
        $this->assertTrue(password_verify('takeover123', $primary->fresh()->password));
    }

    public function test_every_page_sends_browser_security_headers(): void
    {
        $response = $this->get('/login')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("object-src 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_attachments_are_sandboxed_and_odd_names_still_open(): void
    {
        Storage::fake(TicketAttachment::DISK);
        $helpdesk = $this->userWithRole(Role::HELPDESK);
        $ticket = Ticket::factory()->create();

        $this->actingAs($helpdesk)->post("/tickets/{$ticket->ticket_number}/actions/CATATAN_DOKUMENTASI", [
            'notes' => 'Foto ODP',
            'photos' => [UploadedFile::fake()->image("foto\tODP ✓.jpg")],
        ])->assertSessionHasNoErrors();
        $attachment = $ticket->attachments()->sole();

        $response = $this->get(route('tickets.attachment', [$ticket, $attachment]))->assertOk();
        $this->assertStringStartsWith('sandbox', $response->headers->get('Content-Security-Policy'));
    }

    public function test_telegram_feed_drops_attributes_from_formatting_tags(): void
    {
        TelegramLog::query()->create(['message' => '<b onmouseover="alert(1)">Tebal</b> <script>alert(2)</script>', 'simulated' => true]);

        $this->actingAs($this->userWithRole(Role::HELPDESK))->get('/telegram-logs')
            ->assertOk()
            ->assertSee('<b>Tebal</b>', false)
            ->assertDontSee('onmouseover', false)
            ->assertDontSee('<script>alert(2)', false);
    }

    public function test_settings_never_send_the_bot_token_back_and_a_blank_field_keeps_it(): void
    {
        Setting::write(['bot_token' => '123456:SECRET-token']);
        $supervisor = $this->userWithRole(Role::SPV);

        $this->actingAs($supervisor)->get('/settings')->assertOk()->assertDontSee('SECRET-token');

        $this->put('/settings', ['bot_token' => '', 'chat_id' => '-100123'])->assertSessionHasNoErrors();
        $this->assertSame('123456:SECRET-token', Setting::read('bot_token'));

        $this->put('/settings', ['bot_token' => '', 'chat_id' => '-100123', 'clear_bot_token' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('', (string) Setting::read('bot_token'));
    }

    public function test_weak_passwords_and_the_primary_username_are_refused(): void
    {
        $supervisor = $this->userWithRole(Role::SPV);
        $account = ['name' => 'Teknisi Baru', 'role' => 'TEKNISI'];

        $this->actingAs($supervisor)->post('/users', $account + ['username' => 'baru', 'password' => '123456'])
            ->assertSessionHasErrors('password');
        $this->post('/users', $account + ['username' => 'baru', 'password' => 'abcdefgh'])->assertSessionHasErrors('password');
        $this->post('/users', $account + ['username' => User::PRIMARY_SUPERVISOR, 'password' => 'qcnet2026'])
            ->assertSessionHasErrors('username');

        $this->post('/users', $account + ['username' => 'baru', 'password' => 'qcnet2026'])->assertSessionHasNoErrors();
    }

    public function test_password_change_signs_out_other_sessions_but_keeps_the_current_one(): void
    {
        $technician = $this->userWithRole(Role::TEKNISI, ['password' => 'lama123']);
        $this->actingAs($technician)->get('/dashboard')->assertOk();

        // A supervisor resets the password from another browser.
        $technician->update(['password' => 'baru1234']);
        $this->get('/dashboard')->assertRedirect('/login');

        // Changing your own password keeps you signed in on the device you used.
        $cs = $this->userWithRole(Role::CS, ['password' => 'lama123']);
        $this->actingAs($cs)->get('/dashboard')->assertOk();
        $this->put('/profile/password', ['current_password' => 'lama123', 'password' => 'baru1234', 'password_confirmation' => 'baru1234'])
            ->assertSessionHasNoErrors();
        $this->get('/dashboard')->assertOk();
    }
}
