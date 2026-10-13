<?php

declare(strict_types=1);

namespace App\Services\Features;

use App\Models\PanelSetting;
use App\Models\User;
use App\Models\WelcomeCreditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Automatic welcome credit for new members who haven't purchased
 * (اعتبار خوشآمدگویی).
 *
 * Port of the fork's WelcomeCreditService. Admin sets: on/off, amount
 * (toman), a time window and an optional notification message. Guarantees:
 *   - one credit per user, ever (welcome_credit_log.chat_id is UNIQUE);
 *   - only users with NO successful purchase;
 *   - toggle off or window closed => nothing is granted.
 *
 * Settings live in `panel_settings` under `welcome_credit_*` keys. This
 * service owns the policy (eligible()/isRunning()); the wallet credit
 * itself is written by the bot layer, which is outside this batch.
 */
class WelcomeCreditAdminService
{
    public static function defaults(): array
    {
        return [
            'enabled' => '0',
            'amount' => '0',
            'starts_at' => '',
            'ends_at' => '',
            'notify' => '1',
            'message' => "🎁 هدیه خوشآمدگویی!\nمبلغ {amount} تومان اعتبار به کیف پول شما اضافه شد.",
        ];
    }

    /** @return array{enabled: bool, amount: int, starts_at: string, ends_at: string, notify: bool, message: string} */
    public static function get(): array
    {
        $d = self::defaults();

        return [
            'enabled' => self::read('welcome_credit_enabled') === '1',
            'amount' => max(0, (int) (self::read('welcome_credit_amount') ?? $d['amount'])),
            'starts_at' => (string) (self::read('welcome_credit_starts_at') ?? ''),
            'ends_at' => (string) (self::read('welcome_credit_ends_at') ?? ''),
            'notify' => ($saved = self::read('welcome_credit_notify')) !== null ? $saved === '1' : true,
            'message' => (string) (self::read('welcome_credit_message') ?: $d['message']),
        ];
    }

    public static function save(array $in): bool
    {
        $amount = (int) preg_replace('/\D+/', '', (string) ($in['amount'] ?? 0));

        self::write('welcome_credit_enabled', ! empty($in['enabled']) ? '1' : '0');
        self::write('welcome_credit_amount', (string) max(0, $amount));
        self::write('welcome_credit_starts_at', self::cleanDateTime($in['starts_at'] ?? ''));
        self::write('welcome_credit_ends_at', self::cleanDateTime($in['ends_at'] ?? ''));
        self::write('welcome_credit_notify', ! empty($in['notify']) ? '1' : '0');

        $msg = trim((string) ($in['message'] ?? ''));
        self::write('welcome_credit_message', $msg !== '' ? $msg : self::defaults()['message']);

        return true;
    }

    /** Is the campaign granting right now? */
    public static function isRunning(?array $c = null): bool
    {
        $c = $c ?? self::get();

        if (! $c['enabled'] || $c['amount'] <= 0) {
            return false;
        }

        $now = now('Asia/Tehran');
        if ($c['starts_at'] !== '' && $now->lt(self::parseTime($c['starts_at']))) {
            return false;
        }
        if ($c['ends_at'] !== '' && $now->gt(self::parseTime($c['ends_at']))) {
            return false;
        }

        return true;
    }

    /**
     * Eligible = the user exists, has no successful payment and has never
     * been granted the credit (welcome_credit_log is unique on chat_id).
     */
    public static function eligible(string $chatId): bool
    {
        if ($chatId === '') {
            return false;
        }

        if (! User::query()->where('chat_id', $chatId)->exists()) {
            return false;
        }

        if (self::hasSuccessfulPayment($chatId)) {
            return false;
        }

        return ! WelcomeCreditLog::query()->where('chat_id', $chatId)->exists();
    }

    /**
     * Legacy markers of a settled purchase, extended with the audit's `OK` /
     * `2` variants. `method = 'loyalty'` rows are excluded: a free redemption
     * is a gift, not a purchase (the fork's own welcome-credit guard treated
     * them the same way — see docs/legacy-audit.md §payment parity).
     */
    public static function hasSuccessfulPayment(string $chatId): bool
    {
        return DB::table('payments')
            ->where('chat_id', $chatId)
            ->whereIn('is_paid', ['1', '2', 'OK', 'APPROVED'])
            ->where(fn ($q) => $q
                ->whereNull('method')
                ->orWhere('method', '')
                ->orWhere('method', '!=', 'loyalty'))
            ->exists();
    }

    /** @return array{count: int, total: int} */
    public static function stats(): array
    {
        $row = WelcomeCreditLog::query()
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(amount),0) AS t')
            ->first();

        return [
            'count' => (int) ($row->c ?? 0),
            'total' => (int) ($row->t ?? 0),
        ];
    }

    /** Accepts HTML datetime-local ('Y-m-dTH:i') or 'Y-m-d H:i'; returns 'Y-m-d H:i' or ''. */
    private static function cleanDateTime(mixed $v): string
    {
        $v = trim(str_replace('T', ' ', (string) $v));
        if ($v === '') {
            return '';
        }
        $ts = strtotime($v);

        return $ts ? date('Y-m-d H:i', $ts) : '';
    }

    private static function parseTime(string $value): Carbon
    {
        try {
            return Carbon::parse($value, 'Asia/Tehran');
        } catch (\Throwable $e) {
            return now('Asia/Tehran');
        }
    }

    private static function read(string $key): ?string
    {
        return PanelSetting::query()->where('setting_key', $key)->value('setting_value');
    }

    private static function write(string $key, string $value): void
    {
        PanelSetting::query()->updateOrInsert(
            ['setting_key' => $key],
            ['setting_value' => $value]
        );
    }
}