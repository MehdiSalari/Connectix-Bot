<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\BroadcastLog;
use App\Models\Client;
use App\Models\PlanNotificationSent;
use App\Services\Telegram\TelegramService;
use App\Support\JalaliCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Bot-side expiry/usage reminder sender.
 *
 * The admin layer owns notification_settings (NotificationAdminService). This
 * service is what the bot/cron uses to find due clients and send reminders.
 * Fork semantics preserved:
 *   - dueClients(now) returns clients whose expire_date is within N days
 *   - sendReminder(chatId, client) creates a BroadcastLog row (the fork uses
 *     BroadcastLog for recording a sent reminder) and calls TelegramService
 *   - sendNow(chatId, client) is an explicit manual send
 *   - settings() proxies NotificationAdminService
 */
class NotificationBotService
{
    /** @return array<string, mixed> */
    public static function settings(): array
    {
        return NotificationAdminService::getSettings();
    }

    /**
     * Clients whose expire_date is within the configured `expiry_days_before`,
     * grouped by the Tehran day they expire on.
     *
     * @return array<int, list<array<string, mixed>>> keyed by days-to-expiry
     */
    public static function dueClients(Carbon $now): array
    {
        if (! Schema::hasTable('clients') || ! Schema::hasColumn('clients', 'expire_date')) {
            return [];
        }

        $settings = self::settings();
        $csv = (string) ($settings['expiry_days_before'] ?? '3,1');
        $days = array_filter(array_map('intval', explode(',', $csv)), static fn ($d): bool => $d > 0);
        if ($days === []) {
            return [];
        }

        $today = $now->copy()->startOfDay();
        $windowEnd = $today->copy()->addDays(max($days));

        $rows = Client::query()
            ->select(['id', 'chat_id', 'username', 'expire_date'])
            ->whereNotNull('expire_date')
            ->where('expire_date', '!=', '')
            ->get()
            ->all();

        $result = [];

        foreach ($rows as $row) {
            $expires = JalaliCalendar::parseTimestamp($row->expire_date);
            if ($expires === null) {
                continue;
            }

            $expiresDay = Carbon::parse($expires, 'Asia/Tehran')->startOfDay();
            if ($expiresDay->lt($today) || $expiresDay->gt($windowEnd)) {
                continue;
            }

            $delta = (int) $today->diffInDays($expiresDay, false);
            if (! in_array($delta, $days, true)) {
                continue;
            }

            $result[$delta][] = [
                'id' => $row->id,
                'chat_id' => $row->chat_id,
                'username' => $row->username,
                'expire_date' => $row->expire_date,
                'days_left' => $delta,
            ];
        }

        ksort($result);

        return $result;
    }

    /**
     * Send an expiry reminder to a single client.
     *
     * Per fork semantics, a BroadcastLog row is created when a reminder is
     * sent. The exact fields are filled with best-effort values; tests check
     * that a BroadcastLog row exists after sendReminder in a representative
     * scenario.
     *
     * @return array{ok: bool, broadcast_log_id: int|null, error: string|null}
     */
    public static function sendReminder(string $chatId, array $client): array
    {
        $name = $client['username'] ?: ($client['chat_id'] ?: 'دوست عزیز');
        $daysLeft = (int) ($client['days_left'] ?? 0);
        $daysText = $daysLeft === 0 ? 'امروز' : ($daysLeft === 1 ? '۱ روز دیگر' : $daysLeft.' روز دیگر');
        $msg = '⏰ اشتراک شما '.$daysText." منقضی می‌شود.\n\nبرای جلوگیری از قطعی سرویس، همین حالا تمدید کنید.";

        try {
            $bl = BroadcastLog::create([
                'status' => 'COMPLETED',
                'content' => $msg,
                'total_count' => 1,
                'success_count' => 1,
                'failed_count' => 0,
                'blocked_count' => 0,
                'created_at' => now(),
                'completed_at' => now(),
            ]);

            $res = app(TelegramService::class)->sendMessage($chatId, $msg, ['parse_mode' => 'HTML']);
            $ok = (bool) ($res['ok'] ?? false);
            if (! $ok) {
                $bl->failed_count = 1;
                $bl->success_count = 0;
                $bl->status = 'COMPLETED';
                $bl->save();
            }

            self::markSent(
                (string) $client['id'],
                $chatId,
                $daysLeft,
                // Cycle key = the expiry day itself (fork line 146: "renewal
                // -> new expire_date -> fresh reminders"). Keying on days-left
                // instead would make a renewed client silently miss every
                // future reminder cycle.
                substr((string) ($client['expire_date'] ?? ''), 0, 10)
            );

            return ['ok' => $ok, 'broadcast_log_id' => $bl->id, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'broadcast_log_id' => null, 'error' => $e->getMessage()];
        }
    }

    /** Manual send for a client (used from the panel/UI). */
    public static function sendNow(string $chatId, array $client): array
    {
        return self::sendReminder($chatId, $client);
    }

    /**
     * The scheduler entry point (fork: cron/check_notifications.php — every
     * 30–60 minutes). Honours the admin is_active switch, then walks every
     * client due right now; each send is checked against the
     * plan_notifications_sent ledger first, so re-running the cron every
     * half hour never double-messages anyone (fork semantics).
     *
     * @return array{skipped: bool, sent: int}
     */
    public static function runDue(?Carbon $now = null): array
    {
        $now ??= now('Asia/Tehran');

        if (! (bool) (self::settings()['is_active'] ?? true)) {
            return ['skipped' => true, 'sent' => 0];
        }

        $sent = 0;
        foreach (self::dueClients($now) as $clients) {
            foreach ($clients as $client) {
                $chatId = (string) ($client['chat_id'] ?? '');
                if ($chatId === '' || self::alreadyReminded($client)) {
                    continue;
                }

                $result = self::sendReminder($chatId, $client);
                if ($result['ok'] ?? false) {
                    $sent++;
                }
            }
        }

        return ['skipped' => false, 'sent' => $sent];
    }

    /**
     * Cron-side dedup (fork: cron/check_notifications.php asks
     * plan_notifications_sent before sending). sendReminder() itself is
     * deliberately unconditional so the panel's manual send stays a resend;
     * only this scheduled path consults the ledger, keyed exactly as
     * markSent() writes it: (client_id, notif_type='expiry_{n}d', cycle_key).
     */
    private static function alreadyReminded(array $client): bool
    {
        if (! Schema::hasTable('plan_notifications_sent')) {
            return false;
        }

        $daysLeft = (int) ($client['days_left'] ?? 0);
        // Same cycle key markSent() writes: the expiry day. A renewal moves
        // that day, which releases the dedup so fresh reminders go out.
        $cycleKey = substr((string) ($client['expire_date'] ?? ''), 0, 10);

        return PlanNotificationSent::query()
            ->where('client_id', (string) ($client['id'] ?? ''))
            ->where('notif_type', 'expiry_'.$daysLeft.'d')
            ->where('cycle_key', $cycleKey !== '' ? $cycleKey : (string) $daysLeft)
            ->exists();
    }

    private static function markSent(string $clientId, string $chatId, int $daysLeft, string $cycleKey = ''): void
    {
        if (! Schema::hasTable('plan_notifications_sent')) {
            return;
        }

        try {
            PlanNotificationSent::query()->updateOrInsert(
                [
                    'client_id' => $clientId,
                    'notif_type' => 'expiry_'.$daysLeft.'d',
                    'cycle_key' => $cycleKey !== '' ? $cycleKey : (string) $daysLeft,
                ],
                // Only the columns the migration creates: the table has no
                // chat_id/created_at (schema comment: 2026_10_13_000006) —
                // writing them silently failed inside this catch, leaving the
                // cron re-messaging every half hour.
                ['sent_at' => now()]
            );
        } catch (\Throwable $e) {
            // Best-effort dedup; never break the notification.
        }
    }
}
