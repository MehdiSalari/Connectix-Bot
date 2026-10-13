<?php

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\AdminRole;
use App\Models\Admin;

/**
 * Multi-level access control for panel administrators.
 *
 * Port of the feature fork's services/PermissionService.php, reduced to the
 * two roles this rewrite's `admins.role` column actually holds:
 *
 *  - the fork's super_admin (the installer's primary account) and admin
 *    (day-to-day operations) collapse into AdminRole::Admin;
 *  - the fork's support tier (read-only: dashboard, tickets, user lookup)
 *    maps onto AdminRole::Editor, the second role the legacy column already
 *    carried.
 *
 * The fork's model is otherwise kept as-is:
 *
 *  - the permission catalogue is fixed in code (`availablePermissions()`);
 *  - every role has a default set (`defaultPermissions()`);
 *  - any admin can additionally carry an EXPLICIT per-admin list in the
 *    `admins.permissions` JSON column, which replaces the role default for
 *    that account — the "each admin only opens the sections they are
 *    granted" behaviour the fork's admins/index.php manages with checkboxes;
 *  - `dashboard` is always part of an explicit list so a grant can never
 *    lock an administrator out of the panel entirely, exactly as
 *    PermissionService::permissionsFor() did with its forced `dashboard`.
 *
 * The route middleware (`admin.role`) remains the hard gate for panel pages;
 * this service is the catalogue and the per-account decision layer the fork
 * used both for the menu and for the pages behind it.
 */
class AdminPermissionService
{
    /**
     * The one section a role default never includes and only an explicit
     * grant carries. Managing administrators is what the admins page itself
     * controls — the fork's `manage_admins` super-only key.
     *
     * @var list<string>
     */
    private const RESERVED = ['admins'];

    /**
     * The read-only set the Editor role starts with — the fork's support
     * tier (dashboard, user lookup, tickets) widened with the two read-only
     * ledgers (orders, wallet, bank sms) an editor may open in this panel.
     *
     * @var list<string>
     */
    private const EDITOR_DEFAULTS = [
        'dashboard', 'users', 'orders', 'wallet', 'sms', 'tickets',
    ];

    /**
     * The grantable sections, key => Persian label.
     *
     * The keys name the panel sections — the same vocabulary the sidebar
     * layout uses. `db_backup` stays grantable (the fork's admin matrix
     * carried it) but is left out of the Editor's read-only set.
     *
     * @return array<string, string>
     */
    public static function availablePermissions(): array
    {
        return [
            'dashboard' => 'داشبورد',
            'users' => 'کاربران',
            'orders' => 'سفارش‌ها',
            'wallet' => 'تراکنش‌های کیف پول',
            'sms' => 'پیامک‌های بانکی',
            'resellers' => 'نمایندگی‌ها',
            'tickets' => 'تیکت‌ها',
            'admins' => 'ادمین‌ها',
            'gateways' => 'درگاه‌های پرداخت',
            'panels' => 'پنل‌های VPN و پلن‌ها',
            'crypto' => 'پرداخت کریپتو',
            'coupons' => 'کد تخفیف',
            'custom_plan' => 'پلن سفارشی',
            'flash_sales' => 'حراج زمان‌دار',
            'loyalty' => 'سیستم امتیازدهی',
            'cards' => 'کارت‌های مشکوک',
            'buttons' => 'دکمه‌های صفحه اصلی',
            'emojis' => 'ایموجی‌های پرمیوم',
            'appearance' => 'ظاهر و تم',
            'broadcast' => 'پیام همگانی',
            'winback' => 'بازگشت مشتری',
            'notifications' => 'یادآور و هشدار',
            'features' => 'ویژگی‌های ویژه',
            'db_backup' => 'بکاپ دیتابیس',
        ];
    }

    /**
     * The permission keys a role starts with when no explicit list is stored.
     *
     * An Admin keeps everything except the reserved section; the Editor keeps
     * its read-only set. Both mirror the fork's MATRIX rows.
     *
     * @return list<string>
     */
    public static function defaultPermissions(AdminRole $role): array
    {
        if ($role === AdminRole::Editor) {
            return self::EDITOR_DEFAULTS;
        }

        return array_values(array_diff(
            array_keys(self::availablePermissions()),
            self::RESERVED,
        ));
    }

    /**
     * The explicit per-admin list stored in `admins.permissions`, or null
     * when the account carries none and the role default applies.
     *
     * Unknown keys are dropped (the catalogue is fixed in code) and
     * `dashboard` is always re-added, exactly as the fork did.
     *
     * @return list<string>|null
     */
    protected static function explicitPermissions(Admin $admin): ?array
    {
        $raw = $admin->permissions;

        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        $keys = array_values(array_intersect(
            array_map('strval', $decoded),
            array_keys(self::availablePermissions()),
        ));

        // The reserved section is what this service manages — it never rides
        // in through a stored list, exactly as the fork's SUPER_ONLY keys
        // were stripped from every explicit list.
        $keys = array_values(array_diff($keys, self::RESERVED));

        return array_values(array_unique(array_merge($keys, ['dashboard'])));
    }

    /**
     * The effective permission keys of an admin: the explicit list when one
     * is stored, the role default otherwise.
     *
     * @return list<string>
     */
    public static function permissionsFor(Admin $admin): array
    {
        $explicit = self::explicitPermissions($admin);

        if ($explicit !== null) {
            return $explicit;
        }

        $role = $admin->role ?? AdminRole::Admin;

        return self::defaultPermissions($role);
    }

    /**
     * Whether this admin holds a permission.
     *
     * A deactivated account holds nothing (the fork refused everything for
     * an inactive row too).
     */
    public static function can(?Admin $admin, string $permission): bool
    {
        if ($admin === null) {
            return false;
        }

        // A row freshly created in memory (not yet reloaded) has no attribute
        // value; the column defaults to active, so a missing value means
        // active rather than "no access at all".
        $active = $admin->is_active === null ? true : (bool) $admin->is_active;

        if (! $active) {
            return false;
        }

        if (! in_array($permission, self::permissionsFor($admin), true)) {
            return false;
        }

        // The reserved section is never effective through a role default —
        // only an explicit grant carries it.
        if (in_array($permission, self::RESERVED, true)) {
            return self::explicitPermissions($admin) !== null
                && in_array($permission, self::explicitPermissions($admin), true);
        }

        return true;
    }
}
