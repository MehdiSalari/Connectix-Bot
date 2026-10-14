<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WinbackLog;
use App\Models\WinbackSent;
use App\Models\WinbackSetting;
use App\Services\Notifications\WinbackBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * بازگشت مشتری — bot-side win-back trigger.
 *
 * Covers: dueClients() grouping by the Tehran day a client expires, and
 * trigger() creating a WinbackLog + WinbackSent row and sending the bot
 * message per fork semantics.
 */
class WinbackBotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.admin_ids' => ['100'],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        WinbackSetting::query()->create([
            'is_active' => true,
            'inactive_days' => 30,
            'message_text' => 'سلام {name} 👋',
            'coupon_code' => '',
            'cron_secret' => 'test-secret',
        ]);
    }

    private function makeClient(string $chatId, int $daysFromNow): void
    {
        $date = Carbon::now('Asia/Tehran')->addDays($daysFromNow)->format('Y-m-d H:i:s');

        DB::table('clients')->insert([
            'id' => $chatId,
            'chat_id' => $chatId,
            'username' => 'Client '.$chatId,
            'expire_date' => $date,
        ]);
    }

    public function test_due_clients_group_by_day(): void
    {
        $this->makeClient('1', 1);
        $this->makeClient('2', 1);
        $this->makeClient('3', 3);
        $this->makeClient('4', 40); // outside the window

        $now = Carbon::now('Asia/Tehran');
        $due = WinbackBotService::dueClients($now);

        $this->assertArrayHasKey(1, $due);
        $this->assertArrayHasKey(3, $due);
        $this->assertCount(2, $due[1]);
        $this->assertCount(1, $due[3]);
        $this->assertArrayNotHasKey(40, $due);
    }

    public function test_due_clients_is_empty_when_column_missing(): void
    {
        $now = Carbon::now('Asia/Tehran');
        $this->assertSame([], WinbackBotService::dueClients($now));
    }

    public function test_trigger_creates_log_and_sent_and_sends_message(): void
    {
        $client = [
            'id' => 'chat-1',
            'chat_id' => 'chat-1',
            'username' => 'Client One',
            'expire_date' => '2030-01-01 23:59',
            'days_left' => 1,
        ];

        $result = WinbackBotService::trigger('chat-1', $client, 'SAVE10');

        $this->assertTrue($result['ok']);
        $this->assertNotNull($result['log_id']);

        $this->assertSame(1, WinbackLog::query()->count());
        $this->assertSame('SENT', WinbackLog::query()->first()->status);
        $this->assertSame(1, WinbackSent::query()->count());

        $requests = Http::recorded();
        $this->assertCount(1, $requests);
    }

    public function test_trigger_is_idempotent_per_cycle(): void
    {
        $client = [
            'id' => 'chat-1',
            'chat_id' => 'chat-1',
            'username' => 'Client One',
            'expire_date' => '2030-01-01 23:59',
            'days_left' => 1,
        ];

        $first = WinbackBotService::trigger('chat-1', $client);
        $second = WinbackBotService::trigger('chat-1', $client);

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertSame(2, WinbackLog::query()->count());
        $this->assertSame(1, WinbackSent::query()->count());
    }

    public function test_settings_proxies_admin_service(): void
    {
        $settings = WinbackBotService::settings();

        $this->assertSame(30, $settings['inactive_days']);
        $this->assertSame('سلام {name} 👋', $settings['message_text']);
        $this->assertSame('', $settings['coupon_code']);
    }
}
