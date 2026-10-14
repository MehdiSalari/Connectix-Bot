<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Models\LoyaltyCode;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Services\Loyalty\LoyaltyAdminService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LoyaltyBotService
{
    private static function now(): string
    {
        return Carbon::now('Asia/Tehran')->format('Y-m-d H:i:s');
    }

    public static function earnPoints(int|string $paymentId): void
    {
        $paymentIdNum = (int) $paymentId;
        $pdo = DB::connection()->getPdo();
        $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ?');
        $stmt->execute([$paymentIdNum]);
        $p = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$p) {
            return;
        }
        $isPaid = (string) ($p['is_paid'] ?? '');
        if (!in_array($isPaid, ['1', '2'], true)) {
            return;
        }
        $method = (string) ($p['method'] ?? '');
        if ($method === 'loyalty') {
            return;
        }
        if (!LoyaltyAdminService::enabled()) {
            return;
        }
        $chatId = (string) ($p['chat_id'] ?? '');
        $priceStr = (string) ($p['price'] ?? '0');
        $priceStr = str_replace(',', '', $priceStr);
        $price = (int) round((float) $priceStr);
        if ($price <= 0) {
            return;
        }
        $rate = max(1, (int) LoyaltyAdminService::setting('rate_toman'));
        $earned = intdiv($price, $rate);
        if ($earned <= 0) {
            return;
        }
        try {
            LoyaltyPoint::create([
                'chat_id' => $chatId,
                'amount' => $earned,
                'remaining' => $earned,
                'type' => 'payment',
                'ref' => 'pay:' . $paymentIdNum,
                'note' => 'سفارش ' . ($p['order_number'] ?? $paymentIdNum),
                'expires_at' => null,
                'created_at' => self::now(),
            ]);
        } catch (\Throwable $e) {
            return;
        }
        try {
            tg('sendMessage', ['chat_id' => $chatId, 'text' => '🎉 ' . $earned . ' امتیاز به حساب شما اضافه شد.']);
        } catch (\Throwable $e) {
        }
    }

    public static function getBalance(string|int $chatId): int
    {
        return LoyaltyAdminService::balance((string) $chatId);
    }

    public static function redeemCode(string|int $chatId, int $codeId): array
    {
        $chat = (string) $chatId;
        $balance = self::getBalance($chat);
        $code = LoyaltyCode::query()->find($codeId);
        if (!$code) {
            return ['ok' => false, 'error' => 'کد یافت نشد.'];
        }
        if ($code->assigned_to !== null && (string) $code->assigned_to !== $chat) {
            return ['ok' => false, 'error' => 'این کد متعلق به شما نیست.'];
        }
        $red = LoyaltyRedemption::query()->where('code', (string) $code->code)->first();
        if ($red && $red->used_at !== null) {
            return ['ok' => false, 'error' => 'این کد قبلاً استفاده شده.'];
        }
        $reward = LoyaltyReward::query()->find($code->reward_id);
        $cost = $reward ? (int) $reward->cost : 0;
        if ($cost > 0 && $balance < $cost) {
            return ['ok' => false, 'error' => 'متیاز کافی نیست.'];
        }
        try {
            DB::transaction(function () use ($chat, $cost, $code, $reward) {
                if ($cost > 0) {
                    $spend = new \ReflectionMethod(LoyaltyAdminService::class, 'spend');
                    $spend->setAccessible(true);
                    $spend->invoke(null, $chat, $cost, 'redeem', 'redeem:' . $code->id, $reward ? $reward->title : 'redeem');
                }
                LoyaltyRedemption::create([
                    'chat_id' => $chat,
                    'reward_id' => $code->reward_id,
                    'reward_title' => $reward ? $reward->title : null,
                    'points' => $cost,
                    'code' => (string) $code->code,
                    'kind' => $reward ? $reward->kind : 'code',
                    'value' => $reward ? $reward->value : 0,
                    'plan_id' => $reward ? $reward->plan_id : null,
                    'expires_at' => $reward && $reward->ends_at ? $reward->ends_at : self::now(),
                    'used_at' => self::now(),
                    'created_at' => self::now(),
                ]);
                if ($code->assigned_to === null) {
                    $code->assigned_to = $chat;
                    $code->assigned_at = self::now();
                }
                $code->save();
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'خطا در ثبت.'];
        }
        try {
            tg('sendMessage', ['chat_id' => $chat, 'text' => '✅ کد با موفقیت اعمال شد.']);
        } catch (\Throwable $e) {
        }
        return ['ok' => true];
    }
}
