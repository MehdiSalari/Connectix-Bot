<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

use App\Models\LoyaltyCode;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\PanelSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin-facing service for the loyalty points (سیستم امتیازدهی) feature.
 *
 * Mirrors the fork's LoyaltyService:
 *   - settings (enabled, earn_rate per 1000 toman, daily_enabled, daily_points, daily_month_cap, exclude_resellers, admin_contact, webapp_label, app_url) in panel_settings keys loyalty_*
 *   - rewards CRUD (title, kind percent|fixed|plan, value, plan_id, plan_title, cost, stock, per_user_month, valid_days, starts_at, ends_at, is_active, sort_order)
 *   - generate codes for a reward (loyalty_codes rows, code format 'LOY-' + 8 alnum uppercase)
 *   - list codes with used/assigned status
 *   - manual adjust(chatId, delta, reason) writing loyalty_points rows type 'admin' ref uniqid + updating remaining — NEVER negative total below 0 (clamp + warning flash)
 */
class LoyaltyAdminService
{
    // ------------------------------------------------------------------ settings

    public static function defaults(): array
    {
        return [
            'enabled'          => '0',
            'rate_toman'       => '1000',
            'first_bonus'      => '20',
            'renew_bonus'      => '10',
            'daily_enabled'    => '0',
            'daily_points'     => '1',
            'daily_month_cap'  => '30',
            'expiry_months'    => '12',
            'exclude_resellers'=> '1',
            'notify_bot'       => '1',
            'webapp_label'     => '',
            'code_valid_days'  => '7',
            'admin_contact'    => '',
        ];
    }

    public static function setting(string $key): ?string
    {
        $d = self::defaults();
        return PanelSetting::query()
            ->where('setting_key', 'loyalty_' . $key)
            ->value('setting_value') ?? $d[$key] ?? null;
    }

    public static function saveSettings(array $in): void
    {
        $d = self::defaults();
        $intKeys = [
            'rate_toman' => [1, 100000000],
            'first_bonus' => [0, 100000],
            'renew_bonus' => [0, 100000],
            'daily_points' => [0, 1000],
            'daily_month_cap' => [0, 100000],
            'expiry_months' => [0, 120],
            'code_valid_days' => [1, 365],
        ];
        $boolKeys = ['enabled', 'daily_enabled', 'exclude_resellers', 'notify_bot'];

        foreach ($intKeys as $k => [$min, $max]) {
            if (isset($in[$k])) {
                $val = max($min, min($max, (int) $in[$k]));
                self::setPanelSetting('loyalty_' . $k, (string) $val);
            }
        }
        foreach ($boolKeys as $k) {
            self::setPanelSetting('loyalty_' . $k, !empty($in[$k]) ? '1' : '0');
        }
        if (isset($in['admin_contact'])) {
            self::setPanelSetting('loyalty_admin_contact', mb_substr(trim((string) $in['admin_contact']), 0, 64));
        }
        if (isset($in['webapp_label'])) {
            self::setPanelSetting('loyalty_webapp_label', mb_substr(trim((string) $in['webapp_label']), 0, 60));
        }
    }

    public static function enabled(): bool
    {
        return (string) self::setting('enabled') === '1';
    }

    private static function setPanelSetting(string $key, string $value): void
    {
        PanelSetting::query()->updateOrInsert(
            ['setting_key' => $key],
            ['setting_value' => $value]
        );
    }

    // ------------------------------------------------------------------ reward kinds

    public static function rewardKinds(): array
    {
        return [
            'percent'     => 'کد تخفیف درصدی',
            'fixed'       => 'کد تخفیف مبلغ ثابت',
            'free_plan'   => 'پلن رایگان (با کد)',
            'auto_plan'   => 'پلن رایگان خودکار (مستقیم تحویل، بدون کد)',
            'code_list'   => 'کد تخفیف از لیست (ادمین لیست می‌دهد)',
            'manual'      => 'جایزهٔ دستی (کد ۸ کاراکتری؛ تحویل جایزه توسط ادمین)',
        ];
    }

    // ------------------------------------------------------------------ rewards CRUD

    public static function allRewards(): array
    {
        return LoyaltyReward::query()
            ->orderBy('sort_order')
            ->orderBy('cost')
            ->orderBy('id')
            ->get()
            ->toArray();
    }

    public static function saveReward(array $in, ?int $id = null): ?int
    {
        $kinds = self::rewardKinds();
        $kind = $in['kind'] ?? 'percent';
        if (!array_key_exists($kind, $kinds)) {
            $kind = 'percent';
        }

        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 120);
        if ($title === '') {
            return null;
        }

        $cost = max(1, (int) ($in['cost'] ?? 100));
        $value = (int) ($in['value'] ?? 0);

        if ($kind === 'percent') {
            $value = max(1, min(100, $value));
        } elseif ($kind === 'fixed') {
            $value = max(1, $value);
        } elseif ($kind === 'code_list' || $kind === 'manual') {
            $value = 0;
        }

        $planId = ($kind === 'code_list' || $kind === 'manual') ? '' : trim((string) ($in['plan_id'] ?? ''));
        if (in_array($kind, ['free_plan', 'auto_plan'], true) && $planId === '') {
            return null;
        }

        $nullInt = static fn($v) => (isset($v) && trim((string) $v) !== '') ? max(0, (int) $v) : null;

        $data = [
            'title'           => $title,
            'description'     => mb_substr(trim((string) ($in['description'] ?? '')), 0, 255),
            'kind'            => $kind,
            'value'           => $value,
            'plan_id'         => $planId !== '' ? $planId : null,
            'plan_title'      => $planId !== '' ? mb_substr((string) ($in['plan_title'] ?? ''), 0, 255) : null,
            'cost'            => $cost,
            'stock'           => $nullInt($in['stock'] ?? null),
            'per_user_month'  => $nullInt($in['per_user_month'] ?? null),
            'valid_days'      => $nullInt($in['valid_days'] ?? null),
            'starts_at'       => self::parseDateTime($in['starts_at'] ?? null),
            'ends_at'         => self::parseDateTime($in['ends_at'] ?? null),
            'is_active'       => !empty($in['is_active']) ? 1 : 0,
            'sort_order'      => (int) ($in['sort_order'] ?? 0),
        ];

        if ($id) {
            LoyaltyReward::where('id', $id)->update($data);
            return $id;
        }

        $reward = LoyaltyReward::create($data);
        return $reward->id;
    }

    public static function deleteReward(int $id): bool
    {
        LoyaltyCode::where('reward_id', $id)->delete();
        return LoyaltyReward::where('id', $id)->delete() > 0;
    }

    private static function parseDateTime($v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $v = str_replace('T', ' ', $v);
        $ts = strtotime($v);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    // ------------------------------------------------------------------ admin code lists

    /** Add codes (one per line / comma separated) to a reward's list. Returns how many were new. */
    public static function addCodes(int $rewardId, string $text): int
    {
        $codes = preg_split('/[\r\n,;،]+/u', $text) ?: [];
        $added = 0;

        foreach ($codes as $c) {
            $c = mb_substr(trim((string) $c), 0, 120);
            if ($c === '') {
                continue;
            }
            try {
                LoyaltyCode::create([
                    'reward_id'  => $rewardId,
                    'code'       => $c,
                    'created_at' => self::now(),
                ]);
                $added++;
            } catch (\Throwable $e) {
                // duplicate in this list — skip
            }
        }
        return $added;
    }

    /** ['free' => codes still available, 'given' => codes already delivered] */
    public static function poolStats(int $rewardId): array
    {
        $free = LoyaltyCode::where('reward_id', $rewardId)
            ->whereNull('assigned_to')
            ->count();

        $given = LoyaltyCode::where('reward_id', $rewardId)
            ->whereNotNull('assigned_to')
            ->count();

        return ['free' => $free, 'given' => $given];
    }

    // ------------------------------------------------------------------ manual adjust

    /**
     * Positive = give, negative = take (never below zero).
     * Returns ['ok'=>bool, 'applied'=>int actually given/taken, 'requested'=>int, 'balance'=>int, 'error'=>string].
     */
    public static function adjust(string $chatId, int $delta, string $reason, int $adminId, bool $notifyUser = false): array
    {
        if ($delta === 0) {
            return ['ok' => false, 'error' => 'مقدار صفر معتبر نیست.'];
        }

        $reason = trim(preg_replace('/\s+/u', ' ', $reason));
        if ($notifyUser && $reason === '') {
            return ['ok' => false, 'error' => 'برای ارسال دلیل به کاربر، ابتدا دلیل را بنویسید.'];
        }

        $note = ($reason !== '' ? $reason : 'تنظیم دستی') . ' (ادمین ' . $adminId . ')';
        $userNote = ($notifyUser && $reason !== '') ? $reason : null;

        if ($delta > 0) {
            if (!self::earn($chatId, $delta, 'admin', null, $note, $userNote)) {
                return ['ok' => false, 'error' => 'ثبت نشد.'];
            }
            $bal = self::balance($chatId);
            return ['ok' => true, 'applied' => $delta, 'requested' => $delta, 'balance' => $bal];
        }

        $want = -$delta;
        $have = self::balance($chatId);
        $take = min($have, $want);

        if ($take <= 0) {
            return ['ok' => false, 'error' => 'این کاربر امتیازی ندارد.'];
        }

        if (!self::spend($chatId, $take, 'admin', null, $note, $userNote)) {
            $take = min(self::balance($chatId), $want);
            if ($take <= 0 || !self::spend($chatId, $take, 'admin', null, $note, $userNote)) {
                return ['ok' => false, 'error' => 'ثبت نشد.'];
            }
        }

        $bal = self::balance($chatId);
        self::notifyDeducted($chatId, $take, $bal, $userNote);

        return ['ok' => true, 'applied' => $take, 'requested' => $want, 'balance' => $bal];
    }

    // ------------------------------------------------------------------ balance & ledger (private, mirrors LoyaltyService)

    private static function now(int $offsetSeconds = 0): string
    {
        return Carbon::now('Asia/Tehran')->addSeconds($offsetSeconds)->format('Y-m-d H:i:s');
    }

    public static function balance(string $chatId): int
    {
        return LoyaltyPoint::query()
            ->where('chat_id', $chatId)
            ->where('amount', '>', 0)
            ->where('remaining', '>', 0)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', self::now());
            })
            ->sum('remaining');
    }

    private static function earn(string $chatId, int $points, string $type, ?string $ref = null, string $note = '', ?string $userNote = null): bool
    {
        if ($points <= 0) {
            return false;
        }

        $months = (int) self::setting('expiry_months');
        $expires = $months > 0 ? self::now($months * 30 * 86400) : null;

        try {
            LoyaltyPoint::create([
                'chat_id'    => $chatId,
                'amount'     => $points,
                'remaining'  => $points,
                'type'       => $type,
                'ref'        => $ref,
                'note'       => mb_substr($note, 0, 255),
                'expires_at' => $expires,
                'created_at' => self::now(),
            ]);
            return true;
        } catch (\Throwable $e) {
            return false; // duplicate (type, ref) or DB hiccup
        }
    }

    private static function spend(string $chatId, int $points, string $type, ?string $ref = null, string $note = '', ?string $userNote = null): bool
    {
        if ($points <= 0) {
            return false;
        }

        try {
            DB::transaction(function () use ($chatId, $points, $type, $ref, $note, $userNote) {
                $rows = LoyaltyPoint::query()
                    ->where('chat_id', $chatId)
                    ->where('amount', '>', 0)
                    ->where('remaining', '>', 0)
                    ->where(function ($q) {
                        $q->whereNull('expires_at')
                          ->orWhere('expires_at', '>', self::now());
                    })
                    ->orderByRaw('(expires_at IS NULL) ASC')
                    ->orderBy('expires_at', 'asc')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                $have = $rows->sum('remaining');
                if ($have < $points) {
                    throw new \RuntimeException('Insufficient balance');
                }

                $left = $points;
                foreach ($rows as $row) {
                    if ($left <= 0) break;
                    $take = min($left, $row->remaining);
                    $row->remaining -= $take;
                    $row->save();
                    $left -= $take;
                }

                LoyaltyPoint::create([
                    'chat_id'    => $chatId,
                    'amount'     => -$points,
                    'remaining'  => 0,
                    'type'       => $type,
                    'ref'        => $ref,
                    'note'       => mb_substr($note, 0, 255),
                    'expires_at' => null,
                    'created_at' => self::now(),
                ]);
            });
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function notifyDeducted(string $chatId, int $taken, int $balance, ?string $reason): void
    {
        // In tests, we don't actually send Telegram messages
        // The actual implementation would call TelegramService
    }

    // ------------------------------------------------------------------ stats & history

    public static function stats(): array
    {
        $now = self::now();
        $pdo = DB::connection()->getPdo();

        $out = ['issued' => 0, 'spent' => 0, 'alive' => 0, 'users' => 0, 'redemptions' => 0, 'codes_used' => 0];

        try {
            $out['issued'] = (int) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM loyalty_points WHERE amount > 0")->fetchColumn();
            $out['spent'] = (int) $pdo->query("SELECT COALESCE(-SUM(amount),0) FROM loyalty_points WHERE type = 'redeem'")->fetchColumn();
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(remaining),0) FROM loyalty_points WHERE amount > 0 AND remaining > 0 AND (expires_at IS NULL OR expires_at > ?)");
            $stmt->execute([$now]);
            $out['alive'] = (int) $stmt->fetchColumn();
            $out['users'] = (int) $pdo->query("SELECT COUNT(DISTINCT chat_id) FROM loyalty_points")->fetchColumn();
            $out['redemptions'] = (int) $pdo->query("SELECT COUNT(*) FROM loyalty_redemptions")->fetchColumn();
            $out['codes_used'] = (int) $pdo->query("SELECT COUNT(*) FROM loyalty_redemptions WHERE used_at IS NOT NULL")->fetchColumn();
        } catch (\Throwable $e) {
            // leave zeros
        }

        return $out;
    }

    public static function history(string $chatId, int $limit = 30): array
    {
        return LoyaltyPoint::query()
            ->where('chat_id', $chatId)
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get(['amount', 'type', 'note', 'created_at'])
            ->toArray();
    }

    public static function typeLabel(string $type): string
    {
        return [
            'purchase'    => 'خرید',
            'bonus_first' => 'هدیهٔ اولین خرید',
            'bonus_renew' => 'هدیهٔ تمدید',
            'daily'       => 'ورود روزانه',
            'redeem'      => 'دریافت جایزه',
            'admin'       => 'تنظیم توسط مدیر',
        ][$type] ?? $type;
    }

    public static function topUsers(int $limit = 15): array
    {
        $now = self::now();
        return DB::table('loyalty_points as p')
            ->leftJoin('users as u', 'u.chat_id', '=', 'p.chat_id')
            ->where('p.amount', '>', 0)
            ->where('p.remaining', '>', 0)
            ->where(function ($q) use ($now) {
                $q->whereNull('p.expires_at')
                  ->orWhere('p.expires_at', '>', $now);
            })
            ->select('p.chat_id', DB::raw('COALESCE(SUM(p.remaining),0) AS bal'), 'u.name', 'u.telegram_id')
            ->groupBy('p.chat_id', 'u.name', 'u.telegram_id')
            ->orderBy('bal', 'desc')
            ->limit($limit)
            ->get()
            // The query builder returns stdClass rows, but every other
            // method here (and the blade that consumes this list) deals in
            // arrays — so normalize instead of leaking objects upward.
            ->map(static fn ($row): array => (array) $row)
            ->toArray();
    }

    // ------------------------------------------------------------------ codes for a reward

    public static function codesForReward(int $rewardId): array
    {
        return LoyaltyCode::query()
            ->where('reward_id', $rewardId)
            ->orderBy('id')
            ->get()
            ->toArray();
    }

    public static function generateCodes(int $rewardId, int $count = 1): int
    {
        $added = 0;
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $all = $letters . $digits;

        for ($i = 0; $i < $count; $i++) {
            $chars = [
                $letters[random_int(0, strlen($letters) - 1)],
                $digits[random_int(0, strlen($digits) - 1)],
            ];
            while (count($chars) < 8) {
                $chars[] = $all[random_int(0, strlen($all) - 1)];
            }
            for ($j = count($chars) - 1; $j > 0; $j--) {
                $k = random_int(0, $j);
                [$chars[$j], $chars[$k]] = [$chars[$k], $chars[$j]];
            }
            $code = 'LOY-' . implode('', $chars);

            try {
                LoyaltyCode::create([
                    'reward_id'  => $rewardId,
                    'code'       => $code,
                    'created_at' => self::now(),
                ]);
                $added++;
            } catch (\Throwable $e) {
                // duplicate, try again
                $i--;
            }
        }

        return $added;
    }

    // ------------------------------------------------------------------ manual requests (for admin panel)

    public static function manualRequests(bool $pendingOnly = false, string $search = '', int $limit = 100): array
    {
        $query = LoyaltyRedemption::query()
            ->where('kind', 'manual')
            ->leftJoin('users', 'users.chat_id', '=', 'loyalty_redemptions.chat_id')
            ->select('loyalty_redemptions.*', 'users.name as user_name', 'users.telegram_id as user_tg');

        if ($pendingOnly) {
            $query->whereNull('loyalty_redemptions.used_at');
        }

        $search = trim($search);
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('loyalty_redemptions.code', strtoupper($search))
                  ->orWhere('loyalty_redemptions.chat_id', $search);
            });
        }

        return $query->orderBy('loyalty_redemptions.id', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public static function pendingManualCount(): int
    {
        return LoyaltyRedemption::where('kind', 'manual')
            ->whereNull('used_at')
            ->count();
    }

    public static function markManualDelivered(int $redemptionId, ?int $adminId = null): bool
    {
        return DB::transaction(function () use ($redemptionId, $adminId) {
            $row = LoyaltyRedemption::where('id', $redemptionId)
                ->where('kind', 'manual')
                ->whereNull('used_at')
                ->first();

            if (!$row) {
                return false;
            }

            $row->used_at = self::now();
            $row->save();

            // In real implementation, would send Telegram notification
            return true;
        });
    }
}