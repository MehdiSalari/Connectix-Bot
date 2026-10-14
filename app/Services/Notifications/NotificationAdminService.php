<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Client;
use App\Models\NotificationSetting;
use App\Models\PlanNotificationSent;
use App\Support\JalaliCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-facing service for notification settings (expiry/usage reminders).
 *
 * Mirrors the fork's NotificationService:
 *   - settings row via NotificationSetting model (is_active, expiry_days_before CSV '3,1', usage_percent_thresholds CSV '80,90')
 *   - save validating CSV ints
 *   - dueClients(now) returning clients whose expire_date within N days grouped by day (uses Client model expire_date column — guard with Schema::hasColumn)
 *   - preview/dry-run method
 */
class NotificationAdminService
{
    public static function getSettings(): array
    {
        $settings = NotificationSetting::first();
        if (! $settings) {
            $settings = NotificationSetting::create([
                'is_active' => true,
                'expiry_days_before' => '3,1',
                'usage_percent_thresholds' => '80,90',
            ]);
        }

        return $settings->toArray();
    }

    public static function saveSettings(array $data): bool
    {
        $settings = NotificationSetting::first();
        if (! $settings) {
            $settings = new NotificationSetting;
        }

        $settings->is_active = ! empty($data['is_active']);
        $settings->expiry_days_before = self::sanitizeNumberList($data['expiry_days_before'] ?? '3,1');
        $settings->usage_percent_thresholds = self::sanitizeNumberList($data['usage_percent_thresholds'] ?? '80,90');
        $settings->updated_at = now();

        return $settings->save();
    }

    private static function sanitizeNumberList(string $csv): string
    {
        $parts = array_filter(array_map('trim', explode(',', $csv)), fn ($v) => is_numeric($v) && (int) $v > 0);
        $parts = array_unique(array_map('intval', $parts));
        sort($parts);

        return $parts === [] ? '0' : implode(',', $parts);
    }

    /**
     * Preview clients whose expire_date is within N days of $now.
     *
     * `clients.expire_date` is whatever the seller panel reports: Jalali
     * ('1403-12-21 17:59') for the legacy import, Gregorian for anything
     * newer. JalaliCalendar::parseTimestamp converts the Jalali form and
     * passes Gregorian through, so both land on the same comparison.
     *
     * @return array<int, list<array<string, mixed>>> keyed by days-to-expiry
     */
    public static function dueClients(Carbon $now): array
    {
        if (! Schema::hasTable('clients') || ! Schema::hasColumn('clients', 'expire_date')) {
            return [];
        }

        $settings = self::getSettings();
        $days = array_filter(array_map('intval', explode(',', (string) $settings['expiry_days_before'])), fn ($d) => $d > 0);

        if ($days === []) {
            return [];
        }

        $maxDays = max($days);
        $today = $now->copy()->startOfDay();
        $windowEnd = $today->copy()->addDays($maxDays);

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

            // The panel measures "days remaining" on Tehran days, and $now is
            // a Tehran Carbon. Parsing the expiry as app-timezone (UTC)
            // shifted every comparison by 3.5 hours, which made the largest
            // day group unreachable: a client expiring on Tehran-day D+3
            // landed past the window end and was dropped instead of grouped.
            $expiresDay = Carbon::parse($expires, 'Asia/Tehran')->startOfDay();

            if ($expiresDay->lt($today) || $expiresDay->gt($windowEnd)) {
                continue;
            }

            $delta = (int) $today->diffInDays($expiresDay, false);

            if (! in_array($delta, $days, true)) {
                continue;
            }

            $result[$delta][] = $row->toArray();
        }

        ksort($result);

        return $result;
    }

    /** Last sent summary from plan_notifications_sent grouped by notif_type/day */
    public static function lastSentSummary(int $limit = 10): array
    {
        if (! Schema::hasTable('plan_notifications_sent')) {
            return [];
        }

        return PlanNotificationSent::query()
            ->orderBy('sent_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}
