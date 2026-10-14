<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\LoginAttempt;
use App\Services\Admin\TwoFactorAuth;
use App\Services\Connectix\ConnectixService;
use App\Services\Panel\PanelSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Email and password sign in for the web admin panel, plus the two-factor
 * second step the feature fork adds.
 *
 * Port of login.php / verify_2fa.php / cancel_2fa.php: credentials are
 * checked against the admins table with bcrypt; when the admin has
 * totp_enabled the session is NOT opened yet — the pending admin id is
 * parked in the session and the visitor is sent to the code form, which is
 * the only place that can complete the login. The remembered Connectix
 * seller token cookie is re-validated against the panel on arrival, exactly
 * like the legacy auto-login, and it honours 2FA too: a remembered browser
 * proves "this browser", not the second factor.
 */
class AdminLoginController extends Controller
{
    private const COOKIE = 'token';

    private const REMEMBER_DAYS = 30;

    private const INVALID = 'Invalid email or password';

    /**
     * The fork throttles the 2FA code step per pending admin id, harder
     * than the login form itself (8 in 15 minutes): the password is already
     * known by then, so the code is the only thing left to guess.
     */
    private const TFA_MAX_ATTEMPTS = 8;

    private const TFA_WINDOW_MINUTES = 15;

    /**
     * A real bcrypt digest used when no such admin exists, so a miss costs the
     * same time as a hit and the response cannot be used to learn which
     * addresses are registered.
     */
    private const DECOY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public function show(Request $request): RedirectResponse|View
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        $token = $request->cookie(self::COOKIE);

        if (is_string($token) && $token !== '') {
            return $this->handleRememberedToken($request, $token);
        }

        return view('admin.login', ['appName' => $this->appName()]);
    }

    public function login(Request $request): RedirectResponse
    {
        [$email, $password] = $this->credentials($request);

        $admin = Admin::query()->where('email', $email)->first();

        // One hash check either way: the decoy keeps the unknown-address case
        // from answering faster than the wrong-password case.
        $hash = $admin?->password ?? self::DECOY_HASH;

        if (! Hash::check($password, $hash) || $admin === null) {
            // The route is throttled; the log makes an attempt that never got
            // far enough to be throttled visible after the fact.
            Log::warning('Admin login rejected.', [
                'email' => $email,
                'ip' => $request->ip(),
            ]);

            return redirect()->route('admin.login')
                ->withErrors(['credentials' => self::INVALID])
                ->withInput();
        }

        if (! empty($admin->totp_enabled)) {
            // Password confirmed but a second factor is still required —
            // hold off on the real session until verify2fa confirms it
            // (fork login.php: pending_admin_id, then verify_2fa.php).
            return $this->beginTwoFactor($request, $admin->getKey());
        }

        Auth::guard('admin')->login($admin);

        return redirect()->intended(route('admin.dashboard'))
            ->withCookie($this->rememberToken($request, $admin));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')
            ->withCookie($this->forgetToken());
    }

    /**
     * The two-factor second step: GET renders the code form, POST checks
     * the submitted code (or a one-time backup code) and completes the
     * login the password stage deliberately left unfinished.
     *
     * Port of verify_2fa.php. Without a pending admin id there is nothing
     * to verify — the visitor is sent back to the login form; a visitor
     * who already has a session is sent onward to the dashboard. A pending
     * admin whose row vanished or whose 2FA was turned off mid-flow loses
     * the pending marker the way the fork's session_destroy() did.
     */
    public function verify2fa(Request $request): RedirectResponse|View
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        $pendingId = $request->session()->get('pending_admin_id');

        if (! is_int($pendingId) && ! (is_string($pendingId) && ctype_digit($pendingId))) {
            return redirect()->route('admin.login');
        }

        if ($request->isMethod('GET')) {
            return view('admin.login', [
                'appName' => $this->appName(),
                'twoFactor' => true,
            ]);
        }

        $pendingId = (int) $pendingId;
        $identifier = '2fa:'.$pendingId;

        if ($this->twoFactorThrottled($identifier)) {
            return redirect()->route('admin.login.2fa')
                ->withErrors(['code' => 'تلاشهای ناموفق زیادی ثبت شده. لطفاً ۱۵ دقیقه دیگر دوباره امتحان کنید.']);
        }

        $admin = Admin::query()
            ->whereKey($pendingId)
            ->where('is_active', 1)
            ->first();

        if ($admin === null || empty($admin->totp_enabled)) {
            $this->forgetTwoFactor($request);

            return redirect()->route('admin.login');
        }

        $code = trim((string) $request->input('code'));
        $usingBackup = (bool) $request->input('use_backup');

        $verified = $usingBackup
            ? TwoFactorAuth::verifyAndConsumeBackupCode($admin, $code)
            : TwoFactorAuth::verifyCode((string) $admin->totp_secret, $code);

        if (! $verified) {
            LoginAttempt::query()->create([
                'identifier' => $identifier,
                'attempted_at' => now(),
            ]);

            return redirect()->route('admin.login.2fa')
                ->withErrors(['code' => $usingBackup
                    ? 'کد پشتیبان نامعتبر است.'
                    : 'کد وارد شده صحیح نیست.']);
        }

        LoginAttempt::query()->where('identifier', $identifier)->delete();

        $remember = (bool) $request->session()->get('pending_admin_remember');
        $this->forgetTwoFactor($request);

        Auth::guard('admin')->login($admin);

        $response = redirect()->intended(route('admin.dashboard'));

        return $remember
            ? $response->withCookie($this->rememberToken($request, $admin))
            : $response;
    }

    /**
     * Port of cancel_2fa.php: drop the pending markers and land back on
     * the login form — the password stage never opened a session, so
     * there is nothing else to tear down.
     */
    public function cancelTwoFactor(Request $request): RedirectResponse
    {
        $this->forgetTwoFactor($request);

        return redirect()->route('admin.login');
    }

    /**
     * The remembered cookie path, port of the auto-login block of login.php.
     *
     * A token is only honoured while it still names both an admins row and a
     * seller the panel accepts. Legacy cleared the cookie and left the page;
     * the same outcome lands here on the login form instead of the installer.
     */
    private function handleRememberedToken(Request $request, string $token): RedirectResponse
    {
        $admin = Admin::query()->where('token', $token)->first();

        if ($admin === null) {
            return redirect()->route('admin.login')->withCookie($this->forgetToken());
        }

        if (! $this->connectix()->verifySellerToken($token)) {
            return redirect()->route('admin.login')->withCookie($this->forgetToken());
        }

        if (! empty($admin->totp_enabled)) {
            // A remembered-device cookie alone must not bypass 2FA — it
            // only proves "this browser", not the second factor (fork
            // login.php sends the cookie path through verify_2fa too).
            return $this->beginTwoFactor($request, $admin->getKey());
        }

        Auth::guard('admin')->login($admin);

        return redirect()->route('admin.dashboard');
    }

    /**
     * Park the pending admin id in the session instead of opening the
     * session, and hand the visitor to the code form. `pending_admin_remember`
     * mirrors the fork: the login form always remembers the browser, so the
     * second step re-issues the cookie only after the code checks out.
     */
    private function beginTwoFactor(Request $request, int $adminId): RedirectResponse
    {
        $request->session()->put('pending_admin_id', $adminId);
        $request->session()->put('pending_admin_remember', true);

        return redirect()->route('admin.login.2fa');
    }

    /**
     * The fork's session_destroy() on cancel / dead pending state: only
     * the pending markers die, since no session was ever opened.
     */
    private function forgetTwoFactor(Request $request): void
    {
        $request->session()->forget(['pending_admin_id', 'pending_admin_remember']);
    }

    /**
     * Fork's isLoginRateLimited('2fa:<id>', 8, 15): eight failed codes in
     * fifteen minutes locks the step for the rest of the window.
     */
    private function twoFactorThrottled(string $identifier): bool
    {
        return LoginAttempt::query()
            ->where('identifier', $identifier)
            ->where('attempted_at', '>', now()->subMinutes(self::TFA_WINDOW_MINUTES))
            ->count() >= self::TFA_MAX_ATTEMPTS;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function credentials(Request $request): array
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string'],
        ]);

        return [(string) $data['email'], (string) $data['password']];
    }

    private function rememberToken(Request $request, Admin $admin): Cookie
    {
        return \Illuminate\Support\Facades\Cookie::make(
            self::COOKIE,
            $admin->token,
            self::REMEMBER_DAYS * 24 * 60,
            '/',
            null,
            // The seller token itself: marked Secure whenever the panel is
            // served over TLS, so it cannot be read off the wire. Hardcoding
            // false (what legacy did) would send it in the clear forever.
            $request->isSecure(),
            true,
            false,
            'Lax',
        );
    }

    private function forgetToken(): Cookie
    {
        return \Illuminate\Support\Facades\Cookie::forget(self::COOKIE);
    }

    private function appName(): string
    {
        return app(PanelSettingsService::class)->appName();
    }

    private function connectix(): ConnectixService
    {
        return app(ConnectixService::class);
    }
}
