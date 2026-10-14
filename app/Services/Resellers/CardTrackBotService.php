<?php

declare(strict_types=1);

namespace App\Services\Resellers;

use App\Models\AdminActivity;
use App\Models\PaymentCard;
use App\Services\Telegram\TelegramService;
use App\Support\JalaliCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Bot-side card-track "suspicious cards" reporter.
 *
 * CardTrackAdmin (panel side) tracks cards; this bot-facing service mirrors
 * the fork's CardTrackService suspicious-cards queries:
 *   - suspiciousCards() -> PaymentCard rows where the same card appears for
 *     more than one chat_id within the last 7 days (count>threshold logic
 *     approximated by grouping and filtering; fork used the threshold
 *     from PanelSetting 'card_track_threshold' default 3)
 *   - reportText() -> Persian list matching fork's format
 *   - notifyAdmins() -> loops admin ids via TelegramService
 */
class CardTrackBotService
{
    private const DEFAULT_THRESHOLD = 3;
    private const LOOKBACK_DAYS = 7;

    /** @return array<int, array<string, mixed>> */
    public static function suspiciousCards(int $threshold = null): array
    {
        if (! Schema::hasTable('payment_cards')) {
            return [];
        }

        $threshold = $threshold ?? self::getThreshold();
        $since = Carbon::now('Asia/Tehran')->subDays(self::LOOKBACK_DAYS);

        $rows = PaymentCard::query()
            ->selectRaw('card, COUNT(DISTINCT chat_id) AS users_count, COUNT(*) AS uses, GROUP_CONCAT(DISTINCT chat_id) AS chat_ids, MAX(created_at) AS last_seen')
            ->where('created_at', '>=', $since->format('Y-m-d H:i:s'))
            ->groupBy('card')
            ->havingRaw('COUNT(DISTINCT chat_id) > ?', [$threshold])
            ->orderByDesc('users_count')
            ->orderByDesc('uses')
            ->get()
            ->all();

        $result = [];
        foreach ($rows as $r) {
            $result[] = [
                'card'        => (string) $r->card,
                'users_count' => (int) $r->users_count,
                'uses'        => (int) $r->uses,
                'chat_ids'    => (string) $r->chat_ids,
                'last_seen'   => $r->last_seen,
            ];
        }

        return $result;
    }

    public static function reportText(array $cards = null): string
    {
        $cards = $cards ?? self::suspiciousCards();

        if ($cards === []) {
            return 'هیچ کارت مشکوکی در ۷ روز گذشته یافت نشد.';
        }

        $lines = [];
        $lines[] = '🧾 گزارش کارت‌های مشکوک (طی ۷ روز اخیر)';
        $lines[] = '';

        foreach ($cards as $idx => $c) {
            $num = $idx + 1;
            $masked = self::maskCard($c['card']);
            $lines[] = $num . '. کارت: <code>' . htmlspecialchars($masked, ENT_QUOTES, 'UTF-8') . '</code>';
            $lines[] = '   تعداد کاربرهای متفاوت: ' . $c['users_count'];
            $lines[] = '   تعداد دفعات استفاده: ' . $c['uses'];
            $ids = array_map('trim', explode(',', (string) $c['chat_ids']));
            $ids = array_slice($ids, 0, 10);
            $lines[] = '   آیدی‌های مرتبط: ' . implode('، ', array_map(fn ($i) => '<code>' . htmlspecialchars((string) $i, ENT_QUOTES, 'UTF-8') . '</code>', $ids));
            $lines[] = '';
        }

        $lines[] = '⚠️ در صورت مشاهده تخلف، نسبت به مسدودسازی اقدام کنید.';

        return implode("\n", $lines);
    }

    /** @return array{sent: int, failed: int} */
    public static function notifyAdmins(string $text = null): array
    {
        $text = $text ?? self::reportText();
        $ids = self::adminIds();

        if ($ids === []) {
            return ['sent' => 0, 'failed' => 0];
        }

        $sent = 0;
        $failed = 0;
        foreach ($ids as $adminId) {
            try {
                app(TelegramService::class)->sendMessage((string) $adminId, $text, ['parse_mode' => 'HTML']);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('CardTrackBotService::notifyAdmins failed for admin ' . $adminId, [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        self::logActivity($sent, $failed, $text);

        return ['sent' => $sent, 'failed' => $failed];
    }

    private static function getThreshold(): int
    {
        try {
            $v = DB::table('panel_settings')->where('setting_key', 'card_track_threshold')->value('setting_value');
            if ($v === null || $v === '') {
                return self::DEFAULT_THRESHOLD;
            }
            $t = (int) $v;
            return max(2, $t);
        } catch (\Throwable $e) {
            return self::DEFAULT_THRESHOLD;
        }
    }

    /** @return array<int, string> */
    private static function adminIds(): array
    {
        return array_values(array_filter(
            array_map(strval(...), (array) config('connectix_bot.admin_ids', [])),
            static fn (string $id): bool => $id !== ''
        ));
    }

    private static function maskCard(string $card): string
    {
        $digits = preg_replace('/\D+/', '', $card);
        if (strlen($digits) <= 4) {
            return $digits;
        }
        return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }

    private static function logActivity(int $sent, int $failed, string $text): void
    {
        try {
            AdminActivity::query()->create([
                'action'      => 'card_track_bot_notify',
                'subject_type' => 'payment_cards',
                'meta'         => json_encode([
                    'sent'    => $sent,
                    'failed'  => $failed,
                    'preview' => mb_substr($text, 0, 200),
                ], JSON_UNESCAPED_UNICODE),
                'created_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            // ignore
        }
    }
}