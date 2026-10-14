<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AdminRole;
use App\Http\Controllers\Controller;
use App\Models\PanelSetting;
use App\Models\PaymentCard;
use App\Services\Cards\CardTrackService;
use App\Services\Panel\PanelSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Suspicious cards (کارت‌های مشکوک).
 *
 * Port of the fork's cards/index.php: the chats that paid with several
 * distinct cards inside the look-back window, each expandable into its card
 * list, plus the alert threshold the page is cut at.
 *
 * W3.12 adds the bot-side suspicion reporter's view on top: the cards used
 * again and again over the last seven days ({@see suspiciousCards()}), cut at
 * the `card_track_threshold` panel_settings row - the same key
 * App\Services\Resellers\CardTrackBotService reads, default 3. Saving that
 * threshold rides the page's own GET, so no extra route is needed and an
 * editor can read the section without being able to change it.
 *
 * Reading is open to both roles; changing either threshold is admin-only.
 */
class AdminCardController extends Controller
{
    /**
     * The suspicion reporter's settings key and default - the same pair
     * App\Services\Resellers\CardTrackBotService::getThreshold() reads.
     */
    public const TRACK_THRESHOLD_KEY = 'card_track_threshold';

    public const TRACK_THRESHOLD_DEFAULT = 3;

    /** How far back the suspicion reporter looks. */
    public const TRACK_LOOKBACK_DAYS = 7;

    public function __construct(
        private readonly CardTrackService $cards,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        // The suspicion form saves through the page's own URL, so a saved
        // threshold never leaves a `?threshold=` in the address bar (which
        // would re-save it on every reload) - redirect back instead.
        if ($request->query('threshold') !== null) {
            return $this->saveThreshold($request);
        }

        $threshold = $this->cards->threshold();

        return view('admin.cards.index', [
            'appName' => app(PanelSettingsService::class)->appName(),
            'flagged' => $this->cards->flaggedChats($threshold),
            'stats' => $this->cards->stats($threshold),
            'threshold' => $threshold,
            'suspicious' => $this->suspiciousCards(),
            'trackThreshold' => $this->trackThreshold(),
        ]);
    }

    /**
     * Save the alert threshold: how many distinct cards mark a chat.
     */
    public function threshold(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'threshold' => ['required', 'integer', 'min:'.CardTrackService::MIN_THRESHOLD, 'max:20'],
        ]);

        $this->cards->saveThreshold((int) $data['threshold']);

        return back()->with('success', 'آستانهٔ هشدار ذخیره شد.');
    }

    /**
     * The suspicion threshold: the `card_track_threshold` panel_settings row,
     * default {@see TRACK_THRESHOLD_DEFAULT}.
     */
    public function trackThreshold(): int
    {
        $stored = PanelSetting::query()
            ->where('setting_key', self::TRACK_THRESHOLD_KEY)
            ->value('setting_value');

        if ($stored === null || ! is_numeric($stored)) {
            return self::TRACK_THRESHOLD_DEFAULT;
        }

        return max(1, (int) $stored);
    }

    /**
     * The cards used more than the threshold number of times over the last
     * seven days, the most repeated first - the suspicion reporter's rows
     * (App\Services\Resellers\CardTrackBotService::suspiciousCards()).
     *
     * The card number is masked to its last four digits: the page names a
     * suspect card, it does not hand out the full number.
     *
     * @return list<array{card: string, count: int, last_seen: ?Carbon, chat_id: string}>
     */
    public function suspiciousCards(?int $threshold = null): array
    {
        $threshold ??= $this->trackThreshold();

        return PaymentCard::query()
            ->selectRaw('card, COUNT(*) AS uses, MAX(created_at) AS last_seen')
            ->where('created_at', '>=', now()->subDays(self::TRACK_LOOKBACK_DAYS))
            ->groupBy('card')
            ->havingRaw('COUNT(*) > ?', [$threshold])
            ->orderByDesc('uses')
            ->limit(100)
            ->get()
            ->map(static fn ($row): array => [
                'card' => self::maskCard((string) $row->card),
                'count' => (int) $row->uses,
                // MAX(created_at) is an aggregate alias, so the model's
                // created_at cast does not reach it - parse it here or the
                // view's ->format() call lands on a string.
                'last_seen' => $row->last_seen === null ? null : Carbon::parse($row->last_seen),
                'chat_id' => PaymentCard::query()
                    ->where('card', (string) $row->card)
                    ->distinct()
                    ->pluck('chat_id')
                    ->implode(', '),
            ])
            ->all();
    }

    /**
     * Save the suspicion threshold: how many repeats mark a card.
     */
    public function saveThreshold(Request $request): RedirectResponse
    {
        // Same boundary as the multi-card threshold route: the editor reads
        // the page, only the admin changes what it flags.
        if ($request->user('admin')?->role !== AdminRole::Admin) {
            abort(403);
        }

        $data = $request->validate([
            'threshold' => ['required', 'integer', 'min:1'],
        ]);

        PanelSetting::query()->updateOrCreate(
            ['setting_key' => self::TRACK_THRESHOLD_KEY],
            ['setting_value' => (string) $data['threshold'], 'updated_at' => now()],
        );

        return redirect()
            ->route('admin.cards.index')
            ->with('success', 'آستانه مشکوکسازی ذخیره شد.');
    }

    /** The last four digits of a card, everything before them starred. */
    private static function maskCard(string $card): string
    {
        $digits = preg_replace('/\D+/', '', $card);

        if ($digits === null || $digits === '') {
            return $card;
        }

        if (strlen($digits) <= 4) {
            return $digits;
        }

        return str_repeat('*', strlen($digits) - 4).substr($digits, -4);
    }
}
