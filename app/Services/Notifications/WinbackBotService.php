<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\WinbackLog;
use App\Models\WinbackSent;
use App\Models\WinbackSetting;
use App\Services\Telegram\TelegramService;
use App\Services\Winback\WinbackAdminService;
use App\Support\JalaliCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bot-side win-back trigger.
 *
 * The admin layer (WinbackAdminService) owns the campaign queue and the
 * eligibility policy. This service is what the bot/cron asks when it needs to
 * know who is due right now and how to hand one a message.
 *
 * Fork semantics preserved here: a due client is one whose paid window ends
 * within `winback_days`, grouped by the day it expires; trigger() records the
 * dedup claim (WinbackSent) and the log row (WinbackSent) before sending, so
 * a re-run can never double-message the same cycle.
 */
class WinbackBotService
{
    /** @return array{is_active: bool, inactive_days: int, message_text: string, coupon_code: string} */
    public static function settings(): array
    {
        $settings = WinbackAdminService::getSettings();
        // cron_secret is hidden by the model; surface it for the bot layer
        // (the fork's cron script authenticates against it).
        if (! array_key_exists('cron_secret', $settings)) {
            $settings['cron_secret'] = (string) WinbackSetting::query()->where('id', 1)->value('cron_secret');
        }

        return $settings;
    }

    /**
     * Clients whose paid window ends within `winback_days` of $now, grouped by
     * the Tehran day they expire on.
     *
     * `clients.expire_date` is whatever the seller panel reports: Jalali
     * ('1403-12-21 17:59') for legacy imports, Gregorian for newer rows.
     * JalaliCalendar::parseTimestamp converts the Jalali form and passes
     * Gregorian through, so both land on the same comparison.
     *
     * @return array<int, list<array<string, mixed>>> keyed by days-to-expiry
     */
    public static function dueClients(Carbon $now): array
    {
        if (! Schema::hasTable('clients') || ! Schema::hasColumn('clients', 'expire_date')) {
            return [];
        }

        $settings = self::settings();
        $days = (int) ($settings['inactive_days'] ?? 30);
        if ($days <= 0) {
            return [];
        }

        $today = $now->copy()->startOfDay();
        $windowEnd = $today->copy()->addDays($days);

        $rows = DB::table('clients')
            ->select(['id', 'chat_id', 'username', 'expire_date'])
            ->whereNotNull('expire_date')
            ->where('expire_date', '!=', '')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $expires = JalaliCalendar::parseTimestamp($row->expire_date);

            if ($expires === null) {
                continue;
            }

            // Tehran-day comparison: parsing the expiry as app-timezone (UTC)
            // shifted every comparison by 3.5 hours and made the largest day
            // group unreachable.
            $expiresDay = Carbon::parse($expires, 'Asia/Tehran')->startOfDay();

            if ($expiresDay->lt($today) || $expiresDay->gt($windowEnd)) {
                continue;
            }

            $delta = (int) $today->diffInDays($expiresDay, false);

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
     * Send the win-back message to one client, recording the dedup claim and
     * the log row first so a re-run cannot double-send the same cycle.
     *
     * $code is the discount coupon attached (may be empty).
     *
     * @return array{ok: bool, log_id: int|null, error: string|null}
     */
    public static function trigger(string $chatId, array $client, string $code = ''): array
    {
        $settings = self::settings();
        $name = $client['username'] ?: ($client['chat_id'] ?: 'دوست عزیز');
        $text = str_replace(['{name}', '{نام}'], $name, (string) ($settings['message_text'] ?? ''));
        if ($code !== '') {
            $text .= "\n\n🎁 کد تخفیف بازگشت شما: <code>".htmlspecialchars($code, ENT_QUOTES, 'UTF-8').'</code>';
        }

        try {
            $log = WinbackLog::create([
                'chat_id' => $chatId,
                'message' => $settings['message_text'] ?? '',
                'coupon_code' => $code ?: null,
                'status' => 'SENDING',
                'created_at' => now(),
            ]);

            $cycle = self::cycleKey($client);

            WinbackSent::query()->updateOrInsert(
                ['chat_id' => $chatId, 'cycle_key' => $cycle],
                ['created_at' => now()]
            );

            $sent = self::send($chatId, $text);

            $log->status = $sent ? 'SENT' : 'FAILED';
            $log->save();

            return ['ok' => $sent, 'log_id' => $log->id, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'log_id' => null, 'error' => $e->getMessage()];
        }
    }

    /** Send the message through the bot transport. Returns true on success. */
    private static function send(string $chatId, string $text): bool
    {
        try {
            $res = app(TelegramService::class)->sendMessage($chatId, $text, ['parse_mode' => 'HTML']);

            return (bool) ($res['ok'] ?? false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The dedup key of a cycle: the expiry day of this client's paid window. */
    private static function cycleKey(array $client): string
    {
        $expires = JalaliCalendar::parseTimestamp($client['expire_date'] ?? '');

        if ($expires === null) {
            return (string) ($client['chat_id'] ?? '');
        }

        return substr($expires, 0, 10);
    }
}
