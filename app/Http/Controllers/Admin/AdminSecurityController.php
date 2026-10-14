<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Admin\TwoFactorAuth;
use App\Services\Panel\PanelSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Account security — self-service 2FA setup for the CURRENTLY logged-in
 * admin (any role). Enabling requires proving the authenticator app is
 * correctly set up (must submit one valid code) before it's turned on.
 *
 * Direct port of the fork's security.php. The four actions the fork's
 * hidden `action` field dispatches are all here — start_setup (mint a
 * pending secret into the session), confirm_setup (verify one code, then
 * enable and issue the one-time backup codes), disable (current password
 * proves possession) and regenerate_backup (invalidate the old codes).
 * The fork's per-request CSRF hash_equals check is the framework's
 * VerifyCsrfToken middleware in this rewrite; the Persian flash texts are
 * verbatim fork strings, printed by the admin layout's flash block.
 */
class AdminSecurityController extends Controller
{
    public function __construct(
        private readonly PanelSettingsService $settings,
    ) {}

    /**
     * GET renders the current state; POST applies whichever action the
     * hidden field named, then redirects back (PRG) so a refresh never
     * repeats it.
     */
    public function __invoke(Request $request): View|RedirectResponse
    {
        $me = $request->user('admin');

        if ($me === null) {
            // Unreachable behind the admin.auth middleware; kept so the
            // type stays honest rather than trusting the guard blindly.
            return redirect()->route('admin.login');
        }

        if ($request->isMethod('GET')) {
            return $this->show($request, $me);
        }

        return match ((string) $request->input('action')) {
            'start_setup' => $this->startSetup($request),
            'confirm_setup' => $this->confirmSetup($request, $me),
            'disable' => $this->disable($request, $me),
            'regenerate_backup' => $this->regenerateBackup($me),
            default => redirect()->route('admin.security.show'),
        };
    }

    /**
     * The page reads the enabled flag and the remaining backup-code count
     * fresh from the row; the pending setup secret lives only in the
     * session, so nothing sensitive is stored before the first valid code
     * proves the authenticator app really holds the secret.
     */
    private function show(Request $request, Admin $me): View
    {
        $fresh = Admin::query()->findOrFail($me->getKey());

        $pendingSecret = $request->session()->get('2fa_setup_secret');

        return view('admin.security.show', [
            'appName' => $this->settings->appName(),
            'admin' => $fresh,
            'totpEnabled' => ! empty($fresh->totp_enabled),
            'remainingBackupCodes' => TwoFactorAuth::remainingBackupCodesCount($fresh->backup_codes),
            'pendingSecret' => is_string($pendingSecret) ? $pendingSecret : null,
            'otpauthUrl' => is_string($pendingSecret)
                ? TwoFactorAuth::getOtpauthUrl($pendingSecret, $fresh->email, $this->settings->appName())
                : null,
            'newBackupCodes' => $request->session()->get('new_backup_codes'),
        ]);
    }

    /**
     * Mint the pending secret. It stays in the session — not in the row —
     * until confirm_setup sees one valid code, so a half-finished setup
     * can never lock anyone out.
     */
    private function startSetup(Request $request): RedirectResponse
    {
        $request->session()->put('2fa_setup_secret', TwoFactorAuth::generateSecret());

        return redirect()->route('admin.security.show');
    }

    /**
     * One valid code turns 2FA on and mints the one-time backup codes.
     * The plain codes are flashed for exactly one render (the redirect
     * after this one); only their hashes are persisted.
     */
    private function confirmSetup(Request $request, Admin $me): RedirectResponse
    {
        $pendingSecret = $request->session()->get('2fa_setup_secret');
        $code = (string) $request->input('code');

        if (! is_string($pendingSecret) || ! TwoFactorAuth::verifyCode($pendingSecret, $code)) {
            return redirect()->route('admin.security.show')
                ->with('error', 'کد وارد شده صحیح نیست. دوباره تلاش کنید.');
        }

        $backup = TwoFactorAuth::generateBackupCodes();

        $me->forceFill([
            'totp_secret' => $pendingSecret,
            'totp_enabled' => true,
            'backup_codes' => $backup['hashed_json'],
        ])->save();

        $request->session()->forget('2fa_setup_secret');

        return redirect()->route('admin.security.show')
            ->with('success', 'احراز هویت دو مرحلهای با موفقیت فعال شد.')
            ->with('new_backup_codes', $backup['plain']);
    }

    /**
     * Disabling demands the current password — the same possession proof
     * every other account change on this panel asks for — and wipes the
     * secret and the backup codes along with the flag.
     */
    private function disable(Request $request, Admin $me): RedirectResponse
    {
        $password = (string) $request->input('password');

        if (! Hash::check($password, (string) $me->password)) {
            return redirect()->route('admin.security.show')
                ->with('error', 'رمز عبور نادرست است.');
        }

        $me->forceFill([
            'totp_enabled' => false,
            'totp_secret' => null,
            'backup_codes' => null,
        ])->save();

        return redirect()->route('admin.security.show')
            ->with('success', 'احراز هویت دو مرحلهای غیرفعال شد.');
    }

    /**
     * Fresh codes, old ones dead on the spot — the hashes are replaced
     * wholesale, so no previously issued code survives.
     */
    private function regenerateBackup(Admin $me): RedirectResponse
    {
        $backup = TwoFactorAuth::generateBackupCodes();

        $me->forceFill(['backup_codes' => $backup['hashed_json']])->save();

        return redirect()->route('admin.security.show')
            ->with('success', 'کدهای پشتیبان جدید صادر شد. کدهای قبلی دیگر معتبر نیستند.')
            ->with('new_backup_codes', $backup['plain']);
    }
}
