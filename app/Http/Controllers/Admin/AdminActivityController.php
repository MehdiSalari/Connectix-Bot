<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivity;
use App\Services\Panel\PanelSettingsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The activity trail: who did what, from where.
 *
 * Port of the fork's admins/activity.php under its one load-bearing rule —
 * «هر ادمین فقط فعالیتهای خودش را میبیند»: every query is pinned to the
 * acting admin's own email, so an editor sees their own rows and nobody
 * sees anyone else's, with no id or scope parameter to smuggle past it.
 * The fork let the *primary* admin widen the scope to everyone; the port
 * brief drops that, so the page has exactly one scope.
 *
 * `action` carries the raw key (`admin_create`, `account_email`, …) and
 * the view translates it to the fork's Persian caption, falling back to
 * the raw key for actions with no caption yet.
 */
class AdminActivityController extends Controller
{
    /** Rows per page — the fork showed a flat 300; pagination is the rewrite's addition. */
    private const PER_PAGE = 20;

    public function __construct(
        private readonly PanelSettingsService $settings,
    ) {}

    public function index(Request $request): View
    {
        $me = $request->user('admin');

        // Search on the action key only, per the port brief; an empty
        // search means no clause at all rather than a LIKE '%%'.
        $search = trim((string) $request->query('search', ''));

        $activities = AdminActivity::query()
            ->where('admin_email', (string) $me?->email)
            ->when($search !== '', static fn ($q) => $q->where('action', 'like', '%'.$search.'%'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.activity.index', [
            'appName' => $this->settings->appName(),
            'activities' => $activities,
            'search' => $search,
            'labels' => self::ACTION_LABELS,
        ]);
    }

    /**
     * The fork's Persian captions for the actions the panel records
     * (admins/index.php + account.php); anything unlisted prints raw.
     *
     * @var array<string, string>
     */
    private const ACTION_LABELS = [
        'admin_create' => 'ساخت ادمین',
        'admin_password' => 'تغییر رمز ادمین',
        'admin_permissions' => 'ویرایش دسترسیها',
        'admin_activate' => 'فعالسازی ادمین',
        'admin_deactivate' => 'غیرفعالسازی ادمین',
        'account_email' => 'تغییر ایمیل حساب',
        'account_password' => 'تغییر رمز حساب',
    ];
}
