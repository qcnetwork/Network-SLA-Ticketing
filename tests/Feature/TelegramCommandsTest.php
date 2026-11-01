<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Setting;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'webhook-secret-123';

    private const MEMBER = 111;

    private const GROUP = -1001234567890;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::write(['bot_token' => '123456:ABC-def', 'chat_id' => (string) self::GROUP, 'webhook_secret' => self::SECRET]);
    }

    /**
     * Fake the Bot API: MEMBER is in the group, everyone else has left; DMs succeed unless $dmBlocked.
     */
    private function fakeTelegram(bool $dmBlocked = false): void
    {
        Http::fake(function (Request $request) use ($dmBlocked) {
            $method = basename(parse_url($request->url(), PHP_URL_PATH));

            return match ($method) {
                'getChatMember' => Http::response(['ok' => true, 'result' => ['status' => $request['user_id'] === self::MEMBER ? 'member' : 'left']]),
                'getMe' => Http::response(['ok' => true, 'result' => ['username' => 'ticketingqcnet_bot']]),
                'sendMessage' => $dmBlocked && $request['chat_id'] > 0
                    ? Http::response(['ok' => false, 'description' => "Forbidden: bot can't initiate conversation with a user"], 403)
                    : Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
                default => Http::response(['ok' => true, 'result' => true]),
            };
        });
    }

    private function command(string $text, int $from = self::MEMBER, ?int $chat = null)
    {
        $chat ??= $from;

        return $this->postJson('/api/telegram/webhook', ['update_id' => 1, 'message' => [
            'message_id' => 42,
            'from' => ['id' => $from, 'is_bot' => false, 'first_name' => 'Tes'],
            'chat' => ['id' => $chat, 'type' => $chat === $from ? 'private' : 'supergroup'],
            'text' => $text,
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);
    }

    /**
     * @return array<int, array<string, mixed>> payloads of every sendMessage call
     */
    private function sentMessages(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => str_ends_with($request->url(), '/sendMessage'))
            ->map(fn (Request $request) => $request->data())->values()->all();
    }

    public function test_the_webhook_refuses_requests_without_the_secret(): void
    {
        $this->fakeTelegram();
        $update = ['message' => ['message_id' => 1, 'from' => ['id' => self::MEMBER], 'chat' => ['id' => self::MEMBER, 'type' => 'private'], 'text' => '/detailtiket X']];

        $this->postJson('/api/telegram/webhook', $update)->assertForbidden();
        $this->postJson('/api/telegram/webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_a_group_member_gets_the_details_in_their_dm(): void
    {
        $this->fakeTelegram();
        $ticket = Ticket::factory()->create(['customer_name' => 'Bu Rina', 'customer_phone' => '6281299990000']);

        $this->command('/detailtiket '.strtolower($ticket->ticket_number))->assertNoContent();

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);
        $this->assertSame(self::MEMBER, $messages[0]['chat_id']);
        $this->assertStringContainsString($ticket->ticket_number, $messages[0]['text']);
        $this->assertStringContainsString('6281299990000', $messages[0]['text']);
    }

    public function test_asking_in_the_group_sends_the_details_to_dm_and_only_a_pointer_to_the_group(): void
    {
        $this->fakeTelegram();
        $ticket = Ticket::factory()->create(['customer_phone' => '6281299990000']);

        $this->command("/detailtiket@ticketingqcnet_bot {$ticket->ticket_number}", chat: self::GROUP)->assertNoContent();

        [$dm, $groupReply] = $this->sentMessages();
        $this->assertSame(self::MEMBER, $dm['chat_id']);
        $this->assertStringContainsString('6281299990000', $dm['text']);
        $this->assertSame(self::GROUP, $groupReply['chat_id']);
        $this->assertStringContainsString('dikirim lewat DM', $groupReply['text']);
        $this->assertStringNotContainsString('6281299990000', $groupReply['text']);
    }

    public function test_the_group_is_told_to_start_the_bot_when_a_dm_is_not_possible(): void
    {
        $this->fakeTelegram(dmBlocked: true);
        $ticket = Ticket::factory()->create(['customer_phone' => '6281299990000']);

        $this->command("/detailtiket {$ticket->ticket_number}", chat: self::GROUP)->assertNoContent();

        $groupReply = collect($this->sentMessages())->firstWhere('chat_id', self::GROUP);
        $this->assertStringContainsString('@ticketingqcnet_bot', $groupReply['text']);
        $this->assertStringContainsString('Start', $groupReply['text']);
        $this->assertStringNotContainsString('6281299990000', $groupReply['text']);
    }

    public function test_someone_outside_the_group_gets_nothing(): void
    {
        $this->fakeTelegram();
        $ticket = Ticket::factory()->create(['customer_phone' => '6281299990000']);

        $this->command("/detailtiket {$ticket->ticket_number}", from: 999)->assertNoContent();

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('hanya untuk anggota grup', $messages[0]['text']);
        $this->assertStringNotContainsString('6281299990000', $messages[0]['text']);
    }

    public function test_unknown_tickets_and_missing_numbers_get_a_hint(): void
    {
        $this->fakeTelegram();

        $this->command('/detailtiket TCK-19990101-0001')->assertNoContent();
        $this->command('/detailtiket')->assertNoContent();
        $this->command('/detailtiket@other_bot TCK-19990101-0001', chat: self::GROUP)->assertNoContent();

        $texts = array_column($this->sentMessages(), 'text');
        $this->assertCount(2, $texts);
        $this->assertStringContainsString('tidak ditemukan', $texts[0]);
        $this->assertStringContainsString('/detailtiket TCK-', $texts[1]);
    }

    public function test_supervisor_enables_the_commands_with_a_fresh_secret(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        $supervisor = $this->userWithRole(Role::SPV);

        $this->actingAs($supervisor)->post('https://ticketing.test/settings/telegram-commands')->assertSessionHasNoErrors();

        $secret = Setting::read('webhook_secret');
        $this->assertNotSame(self::SECRET, $secret);
        $this->assertSame('https://ticketing.test/api/telegram/webhook', Setting::read('webhook_url'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/setWebhook')
            && $request['url'] === 'https://ticketing.test/api/telegram/webhook' && $request['secret_token'] === $secret);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/setMyCommands'));
    }

    public function test_commands_cannot_be_enabled_over_plain_http(): void
    {
        Http::fake();
        $this->actingAs($this->userWithRole(Role::SPV))->post('http://ticketing.test/settings/telegram-commands')
            ->assertSessionHasErrors('telegram');
        Http::assertNothingSent();
    }
}
