<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TelegramTicketList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramTopicsTest extends TestCase
{
    use RefreshDatabase;

    private const LOG_TOPIC = 11;

    private const LIST_TOPIC = 22;

    private User $cs;

    private int $nextMessageId = 100;

    /**
     * Description returned for editMessageText; null means the edit succeeds.
     */
    private ?string $editError = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cs = $this->userWithRole(Role::CS);
        Setting::write([
            'bot_token' => '123456:ABC-def',
            'chat_id' => '-1001234567890',
            'log_topic_id' => (string) self::LOG_TOPIC,
            'list_topic_id' => (string) self::LIST_TOPIC,
        ]);

        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/editMessageText')) {
                return Http::response($this->editError ? ['ok' => false, 'description' => $this->editError] : ['ok' => true, 'result' => true]);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => $this->nextMessageId++]]);
        });
    }

    private function createTicket(string $name): Ticket
    {
        $this->actingAs($this->cs)->post('/tickets', [
            'customer_name' => $name,
            'customer_phone' => '081234567890',
            'category' => Ticket::CATEGORIES[0],
            'priority' => 'SEDANG',
            'complaint' => 'Internet mati',
        ])->assertSessionHasNoErrors();

        return Ticket::query()->latest('id')->firstOrFail();
    }

    /**
     * @return array<Request>
     */
    private function listPosts(): array
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/sendMessage')
            && ($request['message_thread_id'] ?? null) === self::LIST_TOPIC)->map(fn ($pair) => $pair[0])->all();
    }

    /**
     * @return array<Request>
     */
    private function listEdits(): array
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/editMessageText'))->map(fn ($pair) => $pair[0])->all();
    }

    public function test_ticket_notifications_go_to_the_log_topic(): void
    {
        $ticket = $this->createTicket('Budi');
        $this->actingAs($this->cs)->post("/tickets/{$ticket->ticket_number}/actions/TERUSKAN_HELPDESK")->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage')
            && $request['message_thread_id'] === self::LOG_TOPIC
            && str_contains($request['text'], 'TIKET BARU MASUK'));
    }

    public function test_log_messages_use_the_general_chat_without_a_topic(): void
    {
        Setting::write(['log_topic_id' => '', 'list_topic_id' => '']);

        $ticket = $this->createTicket('Budi');
        $this->actingAs($this->cs)->post("/tickets/{$ticket->ticket_number}/actions/TERUSKAN_HELPDESK");

        Http::assertSent(fn (Request $request) => str_contains($request['text'], 'TIKET BARU MASUK') && ! isset($request['message_thread_id']));
        $this->assertSame([], $this->listPosts());
    }

    public function test_daily_master_list_is_posted_once_then_edited(): void
    {
        $ticket = $this->createTicket('Budi Santoso');

        $posts = $this->listPosts();
        $this->assertCount(1, $posts);
        $this->assertStringContainsString('MASTER LIST TIKET', $posts[0]['text']);
        $this->assertStringContainsString("{$ticket->ticket_number}</code> · Budi Santoso · 🎧 Proses CS", $posts[0]['text']);
        $this->assertSame('100', Setting::read('ticket_list_message_id'));

        $this->actingAs($this->cs)->post("/tickets/{$ticket->ticket_number}/actions/TERUSKAN_HELPDESK");

        $this->assertCount(1, $this->listPosts());
        $edit = collect($this->listEdits())->last();
        $this->assertSame(100, $edit['message_id']);
        $this->assertStringContainsString('🟡 Antrian Helpdesk', $edit['text']);
    }

    public function test_a_new_day_starts_a_new_list_message_and_keeps_yesterdays(): void
    {
        $this->createTicket('Budi');
        $this->assertCount(1, $this->listPosts());

        $this->travel(1)->days();
        $this->artisan('tickets:telegram-list')->assertSuccessful();

        $posts = $this->listPosts();
        $this->assertCount(2, $posts);
        $this->assertStringContainsString('Budi', $posts[1]['text']);
        $this->assertSame('101', Setting::read('ticket_list_message_id'));
        $this->assertSame(now()->toDateString(), Setting::read('ticket_list_date'));
    }

    public function test_list_holds_open_tickets_and_only_todays_closed_ones(): void
    {
        $open = Ticket::factory()->status(TicketStatus::PERBAIKAN)->create(['customer_name' => 'Masih Proses']);
        $closedToday = Ticket::factory()->status(TicketStatus::TUTUP)->create(['customer_name' => 'Tutup Hari Ini', 'closed_at' => now()]);
        Ticket::factory()->status(TicketStatus::TUTUP)->create(['customer_name' => 'Tutup Kemarin', 'closed_at' => now()->subDay()]);

        $list = app(TelegramTicketList::class);
        $text = $list->message($list->tickets(), now());

        $this->assertStringContainsString('Masih Proses · 🔧 Perbaikan (Teknisi)', $text);
        $this->assertStringContainsString('Tutup Hari Ini · ✅ Tutup / Selesai', $text);
        $this->assertStringNotContainsString('Tutup Kemarin', $text);
        $this->assertStringContainsString('Aktif: <b>1</b> · Tutup hari ini: <b>1</b>', $text);
        // Open tickets come before the closed ones.
        $this->assertLessThan(strpos($text, $closedToday->ticket_number), strpos($text, $open->ticket_number));
    }

    public function test_a_long_list_is_cut_to_fit_one_telegram_message(): void
    {
        Ticket::factory()->count(150)->create();

        $list = app(TelegramTicketList::class);
        $text = $list->message($list->tickets(), now());

        $this->assertLessThan(4096, mb_strlen(strip_tags($text)));
        $this->assertMatchesRegularExpression('/… dan \d+ tiket lainnya/', $text);
    }

    public function test_unchanged_list_is_not_reposted(): void
    {
        $this->createTicket('Budi');
        $this->editError = 'Bad Request: message is not modified';

        app(TelegramTicketList::class)->refresh();

        $this->assertCount(1, $this->listPosts());
    }

    public function test_deleted_list_message_is_replaced(): void
    {
        $this->createTicket('Budi');
        $this->editError = 'Bad Request: message to edit not found';

        app(TelegramTicketList::class)->refresh();

        $this->assertCount(2, $this->listPosts());
        $this->assertSame('101', Setting::read('ticket_list_message_id'));
    }

    public function test_settings_store_topics_and_restart_the_list_when_it_moves(): void
    {
        $this->createTicket('Budi');
        $this->actingAs($this->userWithRole(Role::SPV));

        $this->put('/settings', ['log_topic_id' => 'abc'])->assertSessionHasErrors('log_topic_id');

        $this->put('/settings', [
            'bot_token' => '123456:ABC-def', 'chat_id' => '-1001234567890',
            'log_topic_id' => '11', 'list_topic_id' => '33',
        ])->assertSessionHasNoErrors();

        $this->assertSame('33', Setting::read('list_topic_id'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage') && ($request['message_thread_id'] ?? null) === 33);
        $this->assertSame('101', Setting::read('ticket_list_message_id'));
    }
}
