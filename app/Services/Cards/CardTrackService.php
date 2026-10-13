<?php

declare(strict_types=1);

namespace App\Services\Cards;

use App\Models\PanelSetting;
use App\Models\PaymentCard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Paying-card tracking and the suspicious-card page behind it.
 *
 * Port of the fork's services/CardTrackService.php behind cards/index.php:
 * every card number a chat pays with is appended to `payment_cards`, and a
 * chat that has produced several distinct cards inside the look-back window
 * is the multi-account signal the page surfaces.
 *
 * The alert threshold is a `panel_settings` row (`card_multi_threshold`,
 * default 3, floored at 2) — the database equivalent of the fork's
 * PanelSettings::get call.
 */
class CardTrackService
{
    /** The setting row that carries the alert threshold. */
    public const THRESHOLD_KEY = 'card_multi_threshold';

    /** The fork's shipped default, before an admin narrows or widens it. */
    public const DEFAULT_THRESHOLD = 3;

    /** The smallest threshold that means anything: one card is everyone. */
    public const MIN_THRESHOLD = 2;

    /** How far back "this user keeps showing up with new cards" looks. */
    private const LOOKBACK_DAYS = 30;

    /**
     * The configured alert threshold, never below {@see MIN_THRESHOLD}.
     */
    public function threshold(): int
    {
        $stored = PanelSetting::query()
            ->where('setting_key', self::THRESHOLD_KEY)
            ->value('setting_value');

        if ($stored === null || ! is_numeric($stored)) {
            return self::DEFAULT_THRESHOLD;
        }

        return max(self::MIN_THRESHOLD, (int) $stored);
    }

    /**
     * Persist the alert threshold.
     */
    public function saveThreshold(int $threshold): void
    {
        PanelSetting::query()->updateOrCreate(
            ['setting_key' => self::THRESHOLD_KEY],
            ['setting_value' => (string) max(self::MIN_THRESHOLD, $threshold), 'updated_at' => now()],
        );
    }

    /**
     * Append one card payment and report whether that chat has now crossed
     * the threshold of distinct cards.
     */
    public function record(string $chatId, string $card, string $source = 'buy'): bool
    {
        PaymentCard::query()->create([
            'chat_id' => $chatId,
            'card' => $card,
            'source' => $source,
            'created_at' => now(),
        ]);

        $distinct = (int) PaymentCard::query()
            ->where('chat_id', $chatId)
            ->distinct()
            ->count('card');

        return $distinct >= $this->threshold();
    }

    /**
     * The chats that hit the threshold in the look-back window, each with its
     * distinct card list for the page's expandable row.
     *
     * @return list<array{chat_id: string, cards_count: int, uses: int, last_seen: ?Carbon, cards: list<string>}>
     */
    public function flaggedChats(?int $threshold = null): array
    {
        $threshold ??= $this->threshold();

        $counts = PaymentCard::query()
            ->select('chat_id', DB::raw('COUNT(DISTINCT card) as cards_count'), DB::raw('COUNT(*) as uses'))
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->groupBy('chat_id')
            ->havingRaw('COUNT(DISTINCT card) >= ?', [$threshold])
            ->orderByDesc('cards_count')
            ->get();

        if ($counts->isEmpty()) {
            return [];
        }

        $chats = $counts->pluck('chat_id')->all();

        $cards = PaymentCard::query()
            ->whereIn('chat_id', $chats)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('chat_id');

        return $counts
            ->map(function ($row) use ($cards): array {
                $list = $cards->get($row->chat_id, collect());

                return [
                    'chat_id' => (string) $row->chat_id,
                    'cards_count' => (int) $row->cards_count,
                    'uses' => (int) $row->uses,
                    'last_seen' => $list->first()?->created_at,
                    'cards' => $list->pluck('card')->unique()->values()->all(),
                ];
            })
            ->all();
    }

    /**
     * The page's header counters: unique cards seen, flagged chats, and how
     * often each card has appeared.
     *
     * @return array{
     *     total_cards: int,
     *     flagged: int,
     *     per_card: list<array{card: string, uses: int, users: int}>
     * }
     */
    public function stats(?int $threshold = null): array
    {
        $threshold ??= $this->threshold();

        $perCard = PaymentCard::query()
            ->select('card', DB::raw('COUNT(*) as uses'), DB::raw('COUNT(DISTINCT chat_id) as users'))
            ->groupBy('card')
            ->orderByDesc('uses')
            ->limit(100)
            ->get()
            ->map(static fn ($row): array => [
                'card' => (string) $row->card,
                'uses' => (int) $row->uses,
                'users' => (int) $row->users,
            ])
            ->all();

        return [
            'total_cards' => (int) PaymentCard::query()->distinct()->count('card'),
            'flagged' => count($this->flaggedChats($threshold)),
            'per_card' => $perCard,
        ];
    }
}
