<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Models\AdminActivity;
use App\Services\Shop\FlashSaleAdminService;
use App\Services\Telegram\TelegramService;
use Illuminate\Support\Facades\Log;

/**
 * Bot-side flash-sale banner + admin notifier.
 *
 * The admin layer owns the sale rows (FlashSaleAdminService). This service is
 * what the bot handlers ask when they need to know:
 *   - whether a plan currently has a live sale (activeForPlan)
 *   - what the banner line should say (bannerText)
 *   - how to tell the admins about it (notifyAdmins)
 *
 * It deliberately does NOT write to flash_sales — that is admin-only.
 */
class FlashSaleBotService
{
    /**
     * The «🔥 حراج ویژه» banner line for one plan, with the struck-through
     * original price, the sale price and the remaining time.
     *
     * $originalPrice is the plan's full price in Toman (what the bot already
     * resolved downstream); passing it lets the banner show real numbers.
     * When it is omitted the banner still fires, just without the price line.
     */
    public static function bannerText(string $planId, $originalPrice = null): string
    {
        $sale = self::activeForPlan($planId);

        if (! $sale) {
            return '';
        }

        if ($originalPrice !== null && $originalPrice != 0) {
            [$final] = FlashSaleAdminService::priceWithSale((int) $originalPrice, $sale);

            return FlashSaleAdminService::remainingText($sale['ends_at']) !== ''
                ? "\n🔥 <b>حراج ویژه</b>: قیمت اصلی <s>" . number_format((float) $originalPrice) . "</s> ➜ <b>" . number_format((float) $final) . "</b> تومان"
                    . "\n⏳ زمان باقی‌مانده تا پایان حراج: <b>" . FlashSaleAdminService::remainingText($sale['ends_at']) . "</b>"
                : "\n🔥 <b>حراج ویژه</b>: قیمت اصلی <s>" . number_format((float) $originalPrice) . "</s> ➜ <b>" . number_format((float) $final) . "</b> تومان";
        }

        $rem = FlashSaleAdminService::remainingText($sale['ends_at']);

        return "\n🔥 حراج ویژه فعال است! تا پایان حراج: " . $rem;
    }

    /** The live sale for a plan, or null when none is active right now. */
    public static function activeForPlan(string $planId): ?array
    {
        $map = FlashSaleAdminService::activeMap($planId === '' ? null : [$planId]);

        return $map[$planId] ?? null;
    }

    /** Whether a live sale covers this plan right now. */
    public static function isOnSale(string $planId): bool
    {
        return self::activeForPlan($planId) !== null;
    }

    /**
     * Broadcast a flash-sale notice to every configured admin.
     *
     * Outbound Telegram calls go through TelegramService so tests can fake
     * the API; failures are logged and swallowed, never thrown.
     */
    public static function notifyAdmins(string $chatId, string $planId, string $text): array
    {
        $ids = self::adminIds();

        if ($ids === []) {
            return ['sent' => 0, 'failed' => 0, 'note' => 'no admin ids configured'];
        }

        $sent = 0;
        $failed = 0;

        foreach ($ids as $adminId) {
            try {
                app(TelegramService::class)->sendMessage((string) $adminId, $text, ['parse_mode' => 'HTML']);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('FlashSaleBotService::notifyAdmins failed for admin ' . $adminId, [
                    'chat_id' => $chatId,
                    'plan_id' => $planId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        self::logActivity($chatId, $planId, $text, $sent, $failed);

        return ['sent' => $sent, 'failed' => $failed];
    }

    /** @return array<int, string> */
    private static function adminIds(): array
    {
        return array_values(array_filter(
            array_map(strval(...), (array) config('connectix_bot.admin_ids', [])),
            static fn (string $id): bool => $id !== ''
        ));
    }

    private static function logActivity(string $chatId, string $planId, string $text, int $sent, int $failed): void
    {
        try {
            AdminActivity::query()->create([
                'action'      => 'flash_sale_bot_notify',
                'subject_type' => 'flash_sale',
                'subject_id'   => $planId,
                'meta'         => json_encode([
                    'chat_id' => $chatId,
                    'sent'    => $sent,
                    'failed'  => $failed,
                    'preview' => mb_substr($text, 0, 200),
                ], JSON_UNESCAPED_UNICODE),
                'created_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            // Activity logging is best-effort; never break the send over it.
        }
    }
}