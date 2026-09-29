<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Connectix\ConnectixService;
use App\Services\Panel\PanelSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Email and password sign in for the web admin panel.
 *
 * Port of login.php: credentials are checked against the admins table with
 * bcrypt, a session is opened, and the Connectix seller token is remembered
 * in a thirty day cookie. The remembered cookie is re-validated against the
 * panel on arrival, exactly like the legacy auto-login; when the panel no
 * longer accepts the token the cookie is dropped.
 */
class AdminLoginController extends Controller
{
    private const COOKIE = 'token';

    private const REMEMBER_DAYS = 30;

    private const INVALID = 'Invalid email or password';

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

        if ($admin === null || ! Hash::check($password, $admin->password)) {
            return redirect()->route('admin.login')
                ->withErrors(['credentials' => self::INVALID])
                ->withInput();
        }

        Auth::guard('admin')->login($admin);

        return redirect()->intended(route('admin.dashboard'))
            ->withCookie($this->rememberToken($admin));
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

        Auth::guard('admin')->login($admin);

        return redirect()->route('admin.dashboard');
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

    private function rememberToken(Admin $admin): Cookie
    {
        return \Illuminate\Support\Facades\Cookie::make(
            self::COOKIE,
            $admin->token,
            self::REMEMBER_DAYS * 24 * 60,
            '/',
            null,
            false,
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
