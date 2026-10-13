<?php

declare(strict_types=1);

namespace App\Services\Features;

use App\Models\PanelSetting;
use App\Models\User;
use App\Models\WelcomeCreditLog;
use App\Models\WalletTransaction;
use App\Models\Wallet;
use App\Models\Payment;
use App\Services\Bot\EmojiService;
use App\Services\Features\StartEmojiAdminService;
use App\Services\Telegram\TelegramService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Welcome gift service: grant welcome credit on first /start only (if eligible).
 *
 * Parity with fork WelcomeCreditService:
 * - Enabled + window (starts_at/ends_at)
 * - Users row exists (should exist for new users)
 * - Zero successful payments (is_paid IN ('1','2') etc, but treat as settled)
 * - No welcome_credit_log row for this chat_id
 * - Credits wallet + creates wallet_transactions with type 'WELCOME_CREDIT' (string)
 * - Optional notify message
 */
class WelcomeGiftService
{
    public static function defaults(): array
    {
        return [
            'enabled' => '0',
            'amount' => '0',
            'starts_at' => '',
            'ends_at' => '',
            'notify' => '1',
            'message' => "🎁 هدیه خوش‌آمدگویی!\nمبلغ {amount} تومان اعتبار به کیف پول شما اضافه شد.",
        ];
    }

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

    public static function isRunning(?array $c = null): bool
    {
        $c = $c ?? self::get();
        if (! $c['enabled'] || $c['amount'] <= 0) {
            return false;
        }

        try {
            $now = now('Asia/Tehran');
            if ($c['starts_at'] !== '') {
                $s = Carbon::parse($c['starts_at'], 'Asia/Tehran');
                if ($now->lt($s)) {
                    return false;
                }
            }
            if ($c['ends_at'] !== '') {
                $e = Carbon::parse($c['ends_at'], 'Asia/Tehran');
                if ($now->gt($e)) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

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

    public static function grantIfEligible(string|int $chatId): bool
    {
        try {
            $c = self::get();
            if (! self::isRunning($c)) {
                return false;
            }

            if (! $chatId) {
                return false;
            }
            $cid = (string) $chatId;

            // User must exist
            if (! User::query()->where('chat_id', $cid)->exists()) {
                return false;
            }

            // No successful payments
            if (self::hasSuccessfulPayment($cid)) {
                return false;
            }

            // No welcome credit log row
            if (WelcomeCreditLog::query()->where('chat_id', $cid)->exists()) {
                return false;
            }

            // Ensure wallet exists
            $wallet = Wallet::query()->where('chat_id', $cid)->first();
            if (! $wallet) {
                $wallet = Wallet::query()->create([
                    'chat_id' => $cid,
                    'balance' => '0',
                ]);
            }

            $amount = $c['amount'];
            if ($amount <= 0) {
                return false;
            }

            // Create log and credit inside transaction-like sequence; keep simple
            try {
                WelcomeCreditLog::query()->create([
                    'chat_id' => $cid,
                    'amount' => $amount,
                ]);
            } catch (\Throwable $e) {
                return false;
            }

            // Increase wallet
            $newBalance = (int) $wallet->balance + $amount;
            $wallet->forceFill(['balance' => (string) $newBalance])->save();

            // Create wallet transaction with type WELCOME_CREDIT
            $tx = WalletTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'amount' => (string) $amount,
                'operation' => 'INCREASE',
                'chat_id' => $cid,
                'status' => 'SUCCESS',
                'type' => 'WELCOME_CREDIT',
            ]);

            // Notify if enabled
            if ($c['notify']) {
                $message = str_replace(
                    '{amount}',
                    number_format($amount),
                    $c['message']
                );
                $text = EmojiService::renderText($message);
                try {
                    app(TelegramService::class)->sendMessage($cid, $text, [
                        'parse_mode' => 'HTML',
                    ]);
                } catch (\Throwable $e) {
                    // Ignore notify failures
                }
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('WelcomeGiftService::grantIfEligible failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private static function read(string $key): ?string
    {
        return PanelSetting::query()
            ->where('setting_key', $key)
            ->value('setting_value');
    }
}