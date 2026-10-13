<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Cards\CardTrackService;
use App\Services\Panel\PanelSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Suspicious cards (کارت‌های مشکوک).
 *
 * Port of the fork's cards/index.php: the chats that paid with several
 * distinct cards inside the look-back window, each expandable into its card
 * list, plus the alert threshold the page is cut at.
 *
 * Reading is open to both roles; changing the threshold is admin-only.
 */
class AdminCardController extends Controller
{
    public function __construct(
        private readonly CardTrackService $cards,
    ) {}

    public function index(): View
    {
        $threshold = $this->cards->threshold();

        return view('admin.cards.index', [
            'appName' => app(PanelSettingsService::class)->appName(),
            'flagged' => $this->cards->flaggedChats($threshold),
            'stats' => $this->cards->stats($threshold),
            'threshold' => $threshold,
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
}
