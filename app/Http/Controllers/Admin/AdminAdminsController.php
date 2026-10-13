<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AdminRole;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminActivity;
use App\Services\Panel\AdminPermissionService;
use App\Services\Panel\PanelSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Administrator management: the list, creation, activation, per-section
 * permission grants and password resets.
 *
 * Port of the fork's admins/index.php on top of the two roles this schema
 * holds (`admin` / `editor`, no super tier — the primary account IS the
 * installer admin). Kept behaviours:
 *
 *  - creation validates the email, an eight character password and a role,
 *    and writes an activity row (`admin_create`);
 *  - toggling refuses the primary account — «حساب ادمین اصلی را نمی‌توان
 *    غیرفعال کرد» — and refuses deactivating yourself;
 *  - the permission checkboxes store the explicit JSON list every non-role
 *    default grant relies on (`admin_permissions`);
 *  - a password reset re-hashes and, unless the target is the primary
 *    admin themselves, only an admin may run it (`admin_password`);
 *  - the primary account never edits anyone's role into its own image and
 *    is never edited by anyone — its row carries the lock note instead of
 *    the forms.
 */
class AdminAdminsController extends Controller
{
    public function __construct(
        private readonly PanelSettingsService $settings,
    ) {}

    public function index(Request $request): View
    {
        $admins = Admin::query()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();

        return view('admin.admins.index', [
            'appName' => $this->settings->appName(),
            'admins' => $admins,
            'me' => $request->user('admin'),
            'permissions' => AdminPermissionService::availablePermissions(),
            'defaults' => [
                AdminRole::Admin->value => AdminPermissionService::defaultPermissions(AdminRole::Admin),
                AdminRole::Editor->value => AdminPermissionService::defaultPermissions(AdminRole::Editor),
            ],
        ]);
    }

    /**
     * Create an administrator account.
     *
     * Only a role-Admin may create at all (the page 403s an editor at the
     * route), and the created account is never primary — the installer
     * account is the only one that is.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190', 'unique:admins,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in([AdminRole::Admin->value, AdminRole::Editor->value])],
            'chat_id' => ['nullable', 'regex:/^\d+$/'],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(array_keys(AdminPermissionService::availablePermissions()))],
        ]);

        $granted = $this->grantedKeys($data['permissions'] ?? [], $data['role']);

        $admin = Admin::query()->create([
            'email' => $data['email'],
            'password' => $data['password'], // hashed by the model cast
            'token' => bin2hex(random_bytes(32)),
            'chat_id' => $data['chat_id'] ?? '',
            'role' => AdminRole::from($data['role']),
            'is_primary' => false,
            'is_active' => true,
            'permissions' => $granted === null ? null : json_encode($granted, JSON_UNESCAPED_UNICODE),
        ]);

        $this->record($request, 'admin_create', (string) $admin->id, 'email='.$admin->email.' role='.$data['role']);

        return back()->with('success', 'حساب ادمین جدید ایجاد شد.');
    }

    /**
     * Flip `is_active`. The primary account can never be switched off and
     * nobody may deactivate themselves.
     */
    public function toggle(Request $request, Admin $admin): RedirectResponse
    {
        $me = $request->user('admin');

        if ((bool) $admin->is_primary) {
            return back()->with('warning', 'حساب ادمین اصلی را نمی‌توان غیرفعال کرد.');
        }

        if ($me !== null && (int) $me->id === (int) $admin->id) {
            return back()->with('error', 'نمی‌توانید حساب خودتان را غیرفعال کنید.');
        }

        $admin->forceFill(['is_active' => ! (bool) $admin->is_active])->save();

        $this->record($request, $admin->is_active ? 'admin_activate' : 'admin_deactivate', (string) $admin->id);

        return back()->with('success', 'وضعیت حساب بروزرسانی شد.');
    }

    /**
     * Replace an account's explicit permission list.
     *
     * The primary account's grants are not editable — the fork let nobody
     * touch it and there is nothing to narrow anyway.
     */
    public function updatePermissions(Request $request, Admin $admin): RedirectResponse
    {
        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(array_keys(AdminPermissionService::availablePermissions()))],
            'role' => ['nullable', Rule::in([AdminRole::Admin->value, AdminRole::Editor->value])],
        ]);

        if ((bool) $admin->is_primary) {
            return back()->with('warning', 'حساب ادمین اصلی توسط هیچ‌کس قابل تغییر نیست.');
        }

        $role = AdminRole::from($data['role'] ?? $admin->role->value);
        $granted = $this->grantedKeys($data['permissions'] ?? [], $role->value);

        $admin->forceFill([
            'permissions' => $granted === null ? null : json_encode($granted, JSON_UNESCAPED_UNICODE),
        ])->save();

        $this->record($request, 'admin_permissions', (string) $admin->id);

        return back()->with('success', 'دسترسی‌های ادمین بروزرسانی شد.');
    }

    /**
     * Set a new password for another administrator.
     *
     * Primary targets are untouchable; the acting admin may reset their own
     * password only if they are not primary (the fork let the primary reset
     * anyone but themselves).
     */
    public function resetPassword(Request $request, Admin $admin): RedirectResponse
    {
        $me = $request->user('admin');
        $isSelf = $me !== null && (int) $me->id === (int) $admin->id;

        if ((bool) $admin->is_primary && ! $isSelf) {
            return back()->with('warning', 'حساب ادمین اصلی توسط هیچ‌کس قابل تغییر نیست.');
        }

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $admin->forceFill(['password' => $data['password']])->save();

        $this->record($request, 'admin_password', (string) $admin->id, $isSelf ? 'self' : '');

        return back()->with('success', 'رمز عبور ادمین تغییر کرد.');
    }

    /**
     * Normalise the posted checkbox list into the stored JSON value.
     *
     * A full grant of a role's default set stores NULL (the role default
     * already answers), anything else stores the cleaned key list with
     * `dashboard` forced in — the fork's "an explicit list always sees the
     * dashboard" rule.
     *
     * @param  list<string>  $posted
     * @return list<string>|null
     */
    private function grantedKeys(array $posted, string $role): ?array
    {
        $clean = array_values(array_intersect(
            array_map('strval', $posted),
            array_keys(AdminPermissionService::availablePermissions()),
        ));

        // The reserved section never rides in through a posted list — it is
        // what this page itself controls, the fork's SUPER_ONLY rule.
        $clean = array_values(array_diff($clean, ['admins']));

        if ($clean === []) {
            return null;
        }

        $clean = array_values(array_unique(array_merge($clean, ['dashboard'])));

        $default = AdminPermissionService::defaultPermissions(AdminRole::from($role));

        sort($clean);
        $sortedDefault = $default;
        sort($sortedDefault);

        return $clean === $sortedDefault ? null : $clean;
    }

    /**
     * One activity-trail row, best effort — a logging failure never fails
     * the mutation.
     */
    private function record(Request $request, string $action, string $subjectId, string $details = ''): void
    {
        try {
            AdminActivity::query()->create([
                'admin_email' => $request->user('admin')?->email,
                'admin_id' => $request->user('admin')?->id,
                'action' => $action,
                'subject_type' => 'admin',
                'subject_id' => $subjectId,
                'details' => $details !== '' ? $details : null,
                'ip' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // never block the action over a logging failure
        }
    }
}
