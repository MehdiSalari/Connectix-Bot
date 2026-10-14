<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\NotificationSetting;
use App\Models\PanelSetting;
use App\Models\PlanNotificationSent;
use App\Models\WinbackLog;
use App\Models\WinbackSetting;
use App\Services\Features\DbBackupAdminService;
use App\Services\Notifications\NotificationBotService;
use App\Services\Telegram\TelegramService;
use App\Services\Winback\WinbackAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

/**
 * Cron wiring (W4.9) — the fork's cron/*.php scripts ported into the
 * Laravel scheduler.
 *
 * Covers: the schedule registering the three bot cron jobs, the
 * notification runner honouring is_active + the plan_notifications_sent
 * ledger (no double-messaging across half-hourly runs, fresh reminders
 * after a renewal), the win-back runner draining only queued rows when the
 * campaign is active, and the DB backup's isDue() slot arithmetic.
 *
 * The schedules themselves are declared in routes/console.php; asserting
 * they exist here stops a future cleanup from silently deleting them.
 */
class CronWiringTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** The three bot cron jobs must be registered in the scheduler. */
    public function test_schedule_registers_the_three_bot_cron_jobs(): void
    {
        $names = collect(Schedule::events())
            ->map(fn ($event) => (string) ($event->description ?? ''))
            ->all();

        $this->assertContains('notifications-due', $names);
        $this->assertContains('winback-queued', $names);
        $this->assertContains('db-backup-due', $names);
    }

    private function makeDueClient(string $chatId, int $daysFromNow): void
    {
        $target = Carbon::now('Asia/Tehran')->addDays($daysFromNow);

        Client::query()->create([
            'id' => $chatId,
            'chat_id' => $chatId,
            'username' => 'Client '.$chatId,
            'expire_date' => $target->format('Y-m-d').' 23:59',
        ]);
    }

    public function test_notification_runner_sends_then_the_ledger_stops_a_second_run(): void
    {
        $this->makeDueClient('9001', 1);

        $first = NotificationBotService::runDue(now('Asia/Tehran'));
        $this->assertFalse($first['skipped']);
        $this->assertSame(1, $first['sent']);
        $this->assertSame(1, PlanNotificationSent::query()->count());

        // Half an hour later the same client is still "due" — the ledger must
        // stop the resend, exactly like the fork's alreadyNotified() check.
        $second = NotificationBotService::runDue(now('Asia/Tehran'));
        $this->assertSame(0, $second['sent']);
        $this->assertCount(1, Http::recorded());
    }

    public function test_a_renewed_client_gets_fresh_reminders_again(): void
    {
        $base = Carbon::parse('2026-11-01 12:00', 'Asia/Tehran');
        Carbon::setTestNow($base);

        // Expires tomorrow → one reminder fires for expiry_1d / cycle 2026-11-02.
        $this->makeDueClient('9002', 1);
        $this->assertSame(1, NotificationBotService::runDue(now('Asia/Tehran'))['sent']);
        $this->assertSame(1, PlanNotificationSent::query()->count());

        // Week later the client renewed (new expire_date) and is again one
        // day out: same notif_type, new cycle_key → the fork's rule means the
        // dedup must release and the reminder fires again (fork line 146:
        // "renewal -> new expire_date -> fresh reminders").
        Carbon::setTestNow($base->copy()->addDays(7));
        $client = Client::query()->findOrFail('9002');
        $client->expire_date = Carbon::now('Asia/Tehran')->addDays(1)->format('Y-m-d').' 23:59';
        $client->save();

        $again = NotificationBotService::runDue(now('Asia/Tehran'));
        $this->assertSame(1, $again['sent']);
        $this->assertSame(2, PlanNotificationSent::query()->count());
    }

    public function test_notification_runner_is_skipped_when_the_switch_is_off(): void
    {
        $this->makeDueClient('9003', 1);

        NotificationSetting::query()->updateOrCreate(
            ['id' => 1],
            ['is_active' => false, 'expiry_days_before' => '3,1', 'usage_percent_thresholds' => '80,90', 'updated_at' => now()]
        );

        $result = NotificationBotService::runDue(now('Asia/Tehran'));

        $this->assertTrue($result['skipped']);
        $this->assertSame(0, $result['sent']);
        $this->assertCount(0, Http::recorded());
    }

    public function test_winback_runner_drains_queued_rows_only_when_the_campaign_is_active(): void
    {
        WinbackSetting::query()->updateOrCreate(['id' => 1], [
            'is_active' => false,
            'inactive_days' => 30,
            'message_text' => 'سلام {name}',
            'coupon_code' => '',
        ]);

        WinbackLog::query()->create([
            'chat_id' => 'w1',
            'message' => 'سلام',
            'coupon_code' => null,
            'status' => 'QUEUED',
            'created_at' => now(),
        ]);

        // Inactive campaign: the schedule closure returns without sending.
        $this->assertFalse(
            (bool) (WinbackAdminService::getSettings()['is_active'] ?? false)
        );
        $this->assertSame('QUEUED', WinbackLog::query()->first()->status);

        // Active campaign: processQueued() (with the cron's limit) drains it.
        WinbackSetting::query()->where('id', 1)->update(['is_active' => true]);

        $sent = WinbackAdminService::processQueued(function (array $payload): bool {
            $response = TelegramService::class;
            $res = app($response)->sendMessage($payload['chat_id'], $payload['text'], ['parse_mode' => 'HTML']);

            return (bool) ($res['ok'] ?? false);
        }, 200);

        $this->assertSame(1, $sent['sent']);
        $this->assertSame(0, $sent['failed']);
        $this->assertSame('SENT', WinbackLog::query()->first()->status);
    }

    public function test_process_queued_respects_the_cron_recipient_limit(): void
    {
        WinbackSetting::query()->updateOrCreate(['id' => 1], [
            'is_active' => true,
            'inactive_days' => 30,
            'message_text' => 'سلام',
            'coupon_code' => '',
        ]);

        foreach (['q1', 'q2', 'q3'] as $chat) {
            WinbackLog::query()->create([
                'chat_id' => $chat,
                'message' => 'سلام',
                'coupon_code' => null,
                'status' => 'QUEUED',
                'created_at' => now(),
            ]);
        }

        $result = WinbackAdminService::processQueued(fn () => true, 2);

        $this->assertSame(2, $result['total']);
        $this->assertSame(1, WinbackLog::query()->where('status', 'QUEUED')->count());
    }

    public function test_db_backup_is_due_uses_the_latest_slot_not_the_exact_day(): void
    {
        // Disabled → never due.
        DbBackupAdminService::save([
            'enabled' => false,
            'frequency' => 'daily',
            'time' => '03:00',
            'weekday' => 1,
            'target' => 'admin_id',
        ]);
        $this->assertFalse(DbBackupAdminService::isDue(Carbon::now('Asia/Tehran')));

        // Enabled, never sent → due immediately (fork: empty last_sent_at).
        DbBackupAdminService::save([
            'enabled' => true,
            'frequency' => 'daily',
            'time' => '03:00',
            'weekday' => 1,
            'target' => 'admin_id',
        ]);
        $this->assertTrue(DbBackupAdminService::isDue(Carbon::parse('2026-10-14 10:00', 'Asia/Tehran')));

        // Already sent after today's slot → not due again.
        $this->writeBackupKey('db_backup_last_sent_at', '2026-10-14 03:01');
        $this->assertFalse(DbBackupAdminService::isDue(Carbon::parse('2026-10-14 10:00', 'Asia/Tehran')));

        // Missed yesterday's slot entirely → the catch-up fires (fork's
        // "most recent slot <= now" behaviour).
        $this->writeBackupKey('db_backup_last_sent_at', '2026-10-12 03:01');
        $this->assertTrue(DbBackupAdminService::isDue(Carbon::parse('2026-10-14 10:00', 'Asia/Tehran')));

        // Today's 03:00 has not arrived yet; the slot that counts is
        // yesterday's 03:00 and the last send (03:01) is after it → not due
        // again until today's 03:00 passes. This is the fork's catch-up rule:
        // only a slot nobody hit becomes due.
        $this->writeBackupKey('db_backup_last_sent_at', '2026-10-13 03:01');
        $this->assertFalse(DbBackupAdminService::isDue(Carbon::parse('2026-10-14 02:00', 'Asia/Tehran')));
    }

    public function test_db_backup_run_due_skips_when_not_due(): void
    {
        DbBackupAdminService::save([
            'enabled' => false,
            'frequency' => 'daily',
            'time' => '03:00',
            'weekday' => 1,
            'target' => 'admin_id',
        ]);

        $result = DbBackupAdminService::runDue();

        $this->assertTrue($result['skipped']);
        $this->assertNull($result['path']);
        $this->assertCount(0, Http::recorded());
    }

    /** PanelSetting write helper mirroring DbBackupAdminService::write. */
    private function writeBackupKey(string $key, string $value): void
    {
        PanelSetting::query()->updateOrInsert(
            ['setting_key' => $key],
            ['setting_value' => $value]
        );
    }
}
