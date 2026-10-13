<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminActivity;
use App\Services\Panel\PanelSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The admin's own account page: change e-mail and password.
 *
 * Port of the fork's حساب group (account.php). The fork gates every change
 * behind a fresh 2FA code and then ends the session; this rewrite keeps the
 * part the port brief asks for — the current password proves possession of
 * the account — and drops the 2FA/session teardown, since the panel's 2FA
 * lives in the security wave that has not landed yet.
 *
 * Scope is deliberately unbreakable: the acting admin is read from the
 * session and there is no id parameter anywhere, so a posted id is ignored
 * and nobody can reach another administrator's row through this page.
 */
class AdminAccountController extends Controller
{
    public function __construct(
        private readonly PanelSettingsService $settings,
    ) {}

    /**
     * GET renders the two forms; POST applies whichever one was submitted
     * (a hidden `action` field names it, the way the fork's page does).
     */
    public function __invoke(Request $request): View|RedirectResponse
    {
        $me = $request->user('admin');

        if ($request->isMethod('GET')) {
            return view('admin.account.show', [
                'appName' => $this->settings->appName(),
                'admin' => $me,
            ]);
        }

        return $this->change($request, $me);
    }

    /**
     * Validate and apply one change. Both branches demand the current
     * password first — a wrong one fails with a field error the layout
     * prints, exactly like any other validation miss.
     */
    private function change(Request $request, ?Admin $me): RedirectResponse
    {
        if ($me === null) {
            // Unreachable behind the admin.auth middleware; kept so the
            // type stays honest rather than trusting the guard blindly.
            return redirect()->route('admin.login');
        }

        $action = (string) $request->input('action');

        if ($action === 'email') {
            $data = $request->validate([
                'action' => ['required', Rule::in(['email'])],
                'current_password' => ['required', 'string'],
                'new_email' => ['required', 'email', 'max:190', Rule::unique('admins', 'email')->ignore($me->getKey())],
            ]);

            $this->assertCurrentPassword($me, $data['current_password']);

            $me->forceFill(['email' => $data['new_email']])->save();

            $this->record($request, 'account_email', 'email='.$data['new_email']);

            return redirect()->route('admin.account.show')->with('success', 'اطلاعات حساب بهروزرسانی شد.');
        }

        if ($action === 'password') {
            $data = $request->validate([
                'action' => ['required', Rule::in(['password'])],
                'current_password' => ['required', 'string'],
                'new_password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);

            $this->assertCurrentPassword($me, $data['current_password']);

            // The Admin model casts password to `hashed`, so a plain
            // assignment re-hashes — the same path the admin reset uses.
            $me->forceFill(['password' => $data['new_password']])->save();

            $this->record($request, 'account_password', '');

            return redirect()->route('admin.account.show')->with('success', 'اطلاعات حساب بهروزرسانی شد.');
        }

        throw ValidationException::withMessages([
            'action' => 'درخواست نامعتبر است.',
        ]);
    }

    /**
     * Password possession check, phrased as a validation error on the
     * current_password field so the layout's error line carries it.
     */
    private function assertCurrentPassword(Admin $me, string $current): void
    {
        if (! Hash::check($current, (string) $me->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'رمز عبور فعلی نادرست است.',
            ]);
        }
    }

    /**
     * One activity-trail row, best effort — a logging failure never fails
     * the change. The row lands under this admin's email, so it shows up
     * on their own activity page (the fork's scope rule).
     */
    private function record(Request $request, string $action, string $details): void
    {
        try {
            AdminActivity::query()->create([
                'admin_email' => $request->user('admin')?->email,
                'admin_id' => $request->user('admin')?->id,
                'action' => $action,
                'subject_type' => 'admin',
                'subject_id' => (string) $request->user('admin')?->id,
                'details' => $details !== '' ? $details : null,
                'ip' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // never block the change over a logging failure
        }
    }
}
