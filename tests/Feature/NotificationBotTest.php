<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BroadcastLog;
use App\Models\Client;
use App\Services\Notifications\NotificationBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * یادآور انقضا — bot-side notification reminder sender.
 *
 * Covers: dueClients() returning clients within notification_days grouped by
 * day, and sendReminder() creating a BroadcastLog row and sending the bot
 * message per fork semantics.
 */
class NotificationBotTest extends TestCase
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

        \App\Models\NotificationSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'is_active'                => true,
                'expiry_days_before'       => '3,1',
                'usage_percent_thresholds' => '80,90',
                'updated_at'               => now(),
            ]
        );
    }

    private function makeClient(string $chatId, int $daysFromNow): void
    {
        // Carbon already yields Gregorian dates; JalaliCalendar::toGregorian
        // expects Jalali input (and returns an assoc array), so pass through.
        $target = Carbon::now('Asia/Tehran')->addDays($daysFromNow);

        Client::query()->create([
            'id'         => $chatId,
            'chat_id'    => $chatId,
            'username'   => 'Client ' . $chatId,
            'expire_date' => $target->format('Y-m-d') . ' 23:59',
        ]);
    }

    public function test_due_clients_within_notification_days_grouped_by_day(): void
    {
        $this->makeClient('1', 1);
        $this->makeClient('2', 3);
        $this->makeClient('3', 5); // outside window (max is 3)

        $now = Carbon::now('Asia/Tehran');
        $due = NotificationBotService::dueClients($now);

        $this->assertArrayHasKey(1, $due);
        $this->assertArrayHasKey(3, $due);
        $this->assertCount(1, $due[1]);
        $this->assertCount(1, $due[3]);
        $this->assertArrayNotHasKey(5, $due);
    }

    public function test_due_clients_returns_empty_when_column_missing(): void
    {
        Schema::table('clients', function ($table) {
            $table->dropColumn('expire_date');
        });

        $now = Carbon::now('Asia/Tehran');
        $this->assertSame([], NotificationBotService::dueClients($now));
    }

    public function test_send_reminder_creates_broadcast_log_and_sends_message(): void
    {
        $client = [
            'id'         => 'chat-1',
            'chat_id'    => 'chat-1',
            'username'   => 'Client One',
            'expire_date' => '2030-01-01 23:59',
            'days_left'  => 1,
        ];

        $result = NotificationBotService::sendReminder('chat-1', $client);

        $this->assertTrue($result['ok']);
        $this->assertNotNull($result['broadcast_log_id']);
        $this->assertSame(1, BroadcastLog::query()->count());

        $requests = Http::recorded();
        $this->assertCount(1, $requests);
    }

    public function test_send_now_delegates_to_send_reminder(): void
    {
        $client = [
            'id'         => 'chat-1',
            'chat_id'    => 'chat-1',
            'username'   => 'Client One',
            'expire_date' => '2030-01-01 23:59',
            'days_left'  => 3,
        ];

        $result = NotificationBotService::sendNow('chat-1', $client);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, BroadcastLog::query()->count());
    }

    public function test_settings_proxies_admin_service(): void
    {
        $settings = NotificationBotService::settings();

        $this->assertTrue((bool) $settings['is_active']);
        $this->assertSame('3,1', $settings['expiry_days_before']);
        $this->assertSame('80,90', $settings['usage_percent_thresholds']);
    }
}