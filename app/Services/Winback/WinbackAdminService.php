<?php

declare(strict_types=1);

namespace App\Services\Winback;

use App\Models\WinbackLog;
use App\Models\WinbackSent;
use App\Models\WinbackSetting;
use Illuminate\Support\Facades\DB;

/**
 * Admin-facing service for the win-back campaign (بازگشت مشتری).
 *
 * Mirrors the fork's WinbackService where the port brief asks for it, with
 * one deliberate redefinition carried by the brief: eligibility is "users
 * with ZERO successful payments whose last activity is older than
 * `inactive_days`" (the fork's cron targeted past payers instead — noted as
 * a deviation in the delivery report).
 *
 * Buyer parity: a payment counts as a successful purchase when `is_paid`
 * carries one of the legacy accepted markers ('1', '2', 'OK', 'APPROVED'),
 * and rows with `method = 'loyalty'` are excluded from the buyer set — a
 * free loyalty redemption is a gift, not a purchase (parity with the
 * welcome-credit guard).
 *
 * Campaign state: startCampaign() queues one winback_logs row per eligible
 * chat with status QUEUED (the cron/bot does the actual sending later) and
 * claims the (chat_id, cycle_key) slot in winback_sent; stopCampaign()
 * cancels the QUEUED rows and releases their claims so a restart re-queues.
 */
class WinbackAdminService
{
    /** Legacy markers of a settled payment. */
    public const PAID_MARKERS = ['1', '2', 'OK', 'APPROVED'];

    public static function getSettings(): array
    {
        $settings = WinbackSetting::query()->first();
        if (! $settings) {
            $settings = WinbackSetting::create([
                'is_active' => false,
                'inactive_days' => 30,
                'message_text' => "سلام {name} 👋\nمدتیه سراغی ازت نگرفتیم! دلمون براتون تنگ شده 🌹\nهمین الان با یک تخفیف ویژه برگرد و دوباره سرویس بگیر.",
                'coupon_code' => '',
                'cron_secret' => bin2hex(random_bytes(16)),
            ]);
        }

        return $settings->toArray();
    }

    public static function saveSettings(array $data): bool
    {
        $settings = WinbackSetting::query()->first();
        if (! $settings) {
            $settings = new WinbackSetting();
            $settings->cron_secret = bin2hex(random_bytes(16));
        }

        $settings->is_active = ! empty($data['is_active']);
        $settings->inactive_days = max(1, (int) ($data['inactive_days'] ?? 30));
        $settings->message_text = (string) ($data['message_text'] ?? '');
        $settings->coupon_code = trim((string) ($data['coupon_code'] ?? ''));
        $settings->updated_at = now();

        return $settings->save();
    }

    /**
     * The SQL predicate identifying a successful non-loyalty payment,
     * shared by eligibility so the buyer rule cannot drift between queries.
     */
    private static function buyerCondition(string $alias = 'payments'): string
    {
        $markers = implode(',', array_map(
            static fn (string $m): string => "'" . $m . "'",
            self::PAID_MARKERS
        ));

        return "({$alias}.is_paid IN ({$markers}) AND COALESCE({$alias}.method, '') <> 'loyalty')";
    }

    /**
     * Users with zero successful payments whose last activity is older than
     * $inactiveDays and who have not been queued for this churn cycle yet.
     *
     * "Last activity" falls back to the registration timestamp — the only
     * activity column the legacy schema carries.
     *
     * @return array<int, object>
     */
    public static function getEligibleUsers(int $inactiveDays, ?int $limit = null): array
    {
        $cutoff = now('Asia/Tehran')->subDays($inactiveDays)->format('Y-m-d H:i:s');
        $buyer = self::buyerCondition('p');

        $sql = "
            SELECT u.chat_id, u.name, u.created_at AS last_activity_at
            FROM users u
            WHERE COALESCE(u.is_blocked, 0) = 0
              AND u.chat_id IS NOT NULL
              AND u.chat_id <> ''
              AND COALESCE(u.created_at, '1970-01-01 00:00:00') < :cutoff
              AND NOT EXISTS (
                  SELECT 1 FROM payments p WHERE p.chat_id = u.chat_id AND {$buyer}
              )
              AND NOT EXISTS (
                  SELECT 1 FROM winback_sent w
                  WHERE w.chat_id = u.chat_id AND w.cycle_key = :cycle
              )
            ORDER BY COALESCE(u.created_at, '1970-01-01 00:00:00') ASC
        ";

        $bindings = [
            'cutoff' => $cutoff,
            'cycle' => self::cycleKey($cutoff),
        ];

        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        try {
            return DB::select($sql, $bindings);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function countEligibleUsers(int $inactiveDays): int
    {
        return count(self::getEligibleUsers($inactiveDays));
    }

    /**
     * Queue the campaign: one winback_logs row per eligible chat with status
     * QUEUED. The sender (cron/bot) picks them up later.
     *
     * @return array{success: bool, status: string, total: int, queued: int, message: string}
     */
    public static function startCampaign(?int $adminId = null, ?int $limitPerRun = null): array
    {
        $settings = self::getSettings();
        $inactiveDays = (int) $settings['inactive_days'];

        $recipients = self::getEligibleUsers($inactiveDays, $limitPerRun);
        $totalUsers = count($recipients);

        if ($totalUsers === 0) {
            return [
                'success' => true,
                'status' => 'COMPLETED',
                'total' => 0,
                'queued' => 0,
                'message' => 'در حال حاضر مشتری واجد شرایطی برای این کمپین یافت نشد',
            ];
        }

        $cutoff = now('Asia/Tehran')->subDays($inactiveDays)->format('Y-m-d H:i:s');
        $cycle = self::cycleKey($cutoff);

        DB::transaction(function () use ($recipients, $settings, $cycle): void {
            foreach ($recipients as $row) {
                WinbackSent::query()->firstOrCreate(
                    ['chat_id' => $row->chat_id, 'cycle_key' => $cycle],
                    ['created_at' => now()]
                );

                WinbackLog::create([
                    'chat_id' => $row->chat_id,
                    'message' => $settings['message_text'],
                    'coupon_code' => $settings['coupon_code'] ?: null,
                    'status' => 'QUEUED',
                    'created_at' => now(),
                ]);
            }
        });

        return [
            'success' => true,
            'status' => 'QUEUED',
            'total' => $totalUsers,
            'queued' => $totalUsers,
            'message' => number_format($totalUsers) . ' پیام در صف ارسال قرار گرفت.',
        ];
    }

    /** Cancel every queued message and release the dedup claims they hold. */
    public static function stopCampaign(): int
    {
        return DB::transaction(function (): int {
            $inactiveDays = (int) (self::getSettings()['inactive_days'] ?? 30);
            $cycle = self::cycleKey(
                now('Asia/Tehran')->subDays($inactiveDays)->format('Y-m-d H:i:s')
            );

            $queued = WinbackLog::query()->where('status', 'QUEUED')->get();

            foreach ($queued as $log) {
                WinbackSent::query()
                    ->where('chat_id', $log->chat_id)
                    ->where('cycle_key', $cycle)
                    ->delete();
            }

            return WinbackLog::query()
                ->where('status', 'QUEUED')
                ->update(['status' => 'CANCELLED']);
        });
    }

    public static function getRecentLogs(int $limit = 10): array
    {
        return WinbackLog::query()
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public static function getQueuedCount(): int
    {
        return WinbackLog::query()->where('status', 'QUEUED')->count();
    }

    /**
     * Hand every queued message to $sender as a plain array payload. No
     * Telegram call happens here: the caller injects the transport (the
     * cron/bot wires the real sender, tests wire a counter).
     *
     * @return array{sent: int, failed: int, total: int}
     */
    public static function processQueued(?callable $sender = null): array
    {
        $queued = WinbackLog::query()
            ->where('status', 'QUEUED')
            ->orderBy('id')
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($queued as $log) {
            $payload = [
                'chat_id' => $log->chat_id,
                'text' => str_replace(
                    ['{name}', '{نام}'],
                    'دوست عزیز',
                    (string) $log->message
                ),
            ];

            if ($payload['text'] !== '' && $log->coupon_code) {
                $payload['text'] .= "\n\n🎁 کد تخفیف بازگشت شما: " . $log->coupon_code;
            }

            $ok = false;
            if ($sender !== null) {
                try {
                    $ok = (bool) $sender($payload);
                } catch (\Throwable $e) {
                    $ok = false;
                }
            }

            $log->status = $ok ? 'SENT' : 'FAILED';
            $log->save();

            $ok ? $sent++ : $failed++;
        }

        return ['sent' => $sent, 'failed' => $failed, 'total' => $queued->count()];
    }

    /**
     * The dedup key of a churn window: the date part of the activity cutoff,
     * so a user re-qualifying inside the same window is not queued twice and
     * a new window opens a fresh cycle (the fork keyed on last-payment date).
     */
    private static function cycleKey(string $cutoff): string
    {
        return substr($cutoff, 0, 10);
    }
}