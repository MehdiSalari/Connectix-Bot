<?php

declare(strict_types=1);

namespace App\Services\Setup;

use App\Enums\SetupState;
use App\Models\Admin;
use App\Services\Connectix\ConnectixService;
use App\Services\Panel\PanelSettingsService;
use App\Services\Telegram\TelegramService;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The single answer to "is this application installed and usable?".
 *
 * The specification asks for one abstraction, used from every entry point,
 * instead of the same question answered ad hoc in a dozen places. This is it,
 * and the runtime guard is `App\Http\Middleware\EnsureInstalled`.
 *
 * The result is always derived from live checks - the environment, the database,
 * the configured secrets, the admin account. The state file written by the wizard
 * is reported but never believed on its own, so a deployment that was configured
 * by hand (the normal case for an existing reseller) is correctly seen as
 * installed without ever having opened the wizard, and a deleted state file
 * cannot lock a working panel out of itself.
 *
 * The checks come in two levels:
 *
 *  - `checks()` is local only: files, PHP, the database and configuration. It is
 *    cheap enough to run on every request and is what the guard uses.
 *  - `checks(true)` additionally calls the Telegram and Connectix APIs. It is used
 *    by the wizard's final validation, by the installation report and by
 *    `connectix:setup status`, never by the per-request guard.
 */
class InstallationService
{
    public function __construct(
        private readonly SetupStateStore $store,
        private readonly TelegramService $telegram,
        private readonly ConnectixService $connectix,
        private readonly PanelSettingsService $settings,
    ) {}

    /**
     * @return array<int, array{key: string, label: string, ok: bool, critical: bool, message: string}>
     */
    public function checks(bool $probe = false): array
    {
        $checks = array_merge(
            $this->environmentChecks(),
            $this->databaseChecks(),
            $this->configurationChecks(),
        );

        if ($probe) {
            $checks = array_merge($checks, $this->apiChecks());
        }

        return $checks;
    }

    /**
     * The critical local checks only, keyed by check.
     *
     * @return array<string, array{key: string, label: string, ok: bool, critical: bool, message: string}>
     */
    public function critical(bool $probe = false): array
    {
        $indexed = [];

        foreach ($this->checks($probe) as $check) {
            $indexed[$check['key']] = $check;
        }

        return $indexed;
    }

    /**
     * Whether the application may serve its normal routes.
     */
    public function isInstalled(): bool
    {
        foreach ($this->critical() as $check) {
            if ($check['critical'] && ! $check['ok']) {
                return false;
            }
        }

        return true;
    }

    public function state(bool $probe = false): SetupState
    {
        if ($this->isInstalled()) {
            return SetupState::Configured;
        }

        return $this->store->state() === SetupState::Failed
            ? SetupState::Failed
            : SetupState::NotInstalled;
    }

    /**
     * @return array<int, string> The keys of the critical checks that fail.
     */
    public function failures(bool $probe = false): array
    {
        $failed = [];

        foreach ($this->checks($probe) as $check) {
            if ($check['critical'] && ! $check['ok']) {
                $failed[] = $check['key'];
            }
        }

        return $failed;
    }

    public function markCompleted(): void
    {
        $this->store->markCompleted();
    }

    /**
     * What the wizard shows on its report page.
     *
     * @return array<string, mixed>
     */
    public function summary(bool $probe = false): array
    {
        $appName = config('connectix_bot.app_name') ?: $this->settings->appName();
        $admins = (int) $this->safe(fn (): int => Admin::query()->count(), 0);
        $users = (int) $this->safe(fn (): int => DB::table('users')->count(), 0);
        $clients = (int) $this->safe(fn (): int => DB::table('clients')->count(), 0);

        return [
            'state' => $this->state($probe),
            'app_name' => $appName !== '' ? $appName : 'Connectix Bot',
            'database' => (string) config('database.default'),
            'admins' => $admins,
            'users' => $users,
            'clients' => $clients,
            'started_at' => $this->store->startedAt(),
            'completed_at' => $this->store->completedAt(),
        ];
    }

    // -----------------------------------------------------------------
    // Environment
    // -----------------------------------------------------------------

    /**
     * @return array<int, array{key: string, label: string, ok: bool, critical: bool, message: string}>
     */
    private function environmentChecks(): array
    {
        $minimum = (string) config('setup.min_php', '8.3');
        $missingExtensions = array_values(array_filter(
            (array) config('setup.extensions', []),
            static fn (string $extension): bool => ! extension_loaded($extension),
        ));

        $unwritable = array_values(array_filter(
            (array) config('setup.writable', []),
            fn (string $path): bool => ! $this->isWritable($path),
        ));

        $appKey = (string) config('app.key');

        return [
            $this->check(
                'application.php',
                'نسخه PHP',
                version_compare(PHP_VERSION, $minimum, '>='),
                true,
                PHP_VERSION.' (حداقل '.$minimum.')',
            ),
            $this->check(
                'application.extensions',
                'افزونه‌های PHP',
                $missingExtensions === [],
                true,
                $missingExtensions === []
                    ? 'همه نصب هستند'
                    : 'نصب نیستند: '.implode(', ', $missingExtensions),
            ),
            $this->check(
                'application.storage',
                'دسترسی نوشتن پوشه‌ها',
                $unwritable === [],
                true,
                $unwritable === []
                    ? 'همه مسیرها قابل نوشتن هستند'
                    : 'قابل نوشتن نیستند: '.implode('، ', $unwritable),
            ),
            $this->check(
                'application.key',
                'کلید برنامه (APP_KEY)',
                $appKey !== '',
                true,
                $appKey === '' ? 'APP_KEY تنظیم نشده است' : 'تنظیم شده است',
            ),
        ];
    }

    // -----------------------------------------------------------------
    // Database
    // -----------------------------------------------------------------

    /**
     * @return array<int, array{key: string, label: string, ok: bool, critical: bool, message: string}>
     */
    private function databaseChecks(): array
    {
        $connected = false;
        $connectionMessage = '';

        try {
            DB::connection()->getPdo();
            $connected = true;
            $connectionMessage = 'اتصال برقرار است ('.config('database.default').')';
        } catch (Throwable $e) {
            $connectionMessage = $e->getMessage();
            Log::warning('The installation check could not reach the database.', [
                'error' => $e->getMessage(),
            ]);
        }

        $missing = [];
        $pending = [];

        if ($connected) {
            $missing = $this->missingTables();
            $pending = $this->pendingMigrations();
        }

        return [
            $this->check('database.connection', 'اتصال دیتابیس', $connected, true, $connectionMessage),
            $this->check(
                'database.schema',
                'جدول‌های مورد نیاز',
                $connected && $missing === [],
                true,
                $missing === [] ? 'همه جدول‌ها موجود هستند' : 'موجود نیستند: '.implode('، ', $missing),
            ),
            $this->check(
                'database.migrations',
                'migration ها',
                $connected && $missing === [] && $pending === [],
                true,
                match (true) {
                    ! $connected => 'ابتدا باید اتصال دیتابیس برقرار شود',
                    $missing !== [] => 'ابتدا باید جدول‌ها ساخته شوند',
                    $pending === [] => 'همه اجرا شده‌اند',
                    default => 'اجرا نشده: '.implode('، ', $pending),
                },
            ),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function missingTables(): array
    {
        $missing = [];

        foreach ((array) config('setup.tables', []) as $table) {
            if (! Schema::hasTable($table)) {
                $missing[] = (string) $table;
            }
        }

        return $missing;
    }

    /**
     * @return array<int, string>
     */
    public function pendingMigrations(): array
    {
        try {
            $files = Migrator::getMigrationFiles(database_path('migrations'));
            $ran = app('migration.repository')->getRan();

            return array_values(array_diff(array_keys($files), $ran));
        } catch (Throwable $e) {
            // No migrations table yet is the normal first-run state, not a crash.
            return [];
        }
    }

    // -----------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------

    /**
     * @return array<int, array{key: string, label: string, ok: bool, critical: bool, message: string}>
     */
    private function configurationChecks(): array
    {
        $token = (string) config('connectix_bot.telegram.token');
        $webhookSecret = (string) config('connectix_bot.telegram.webhook_secret');
        $webhookUrl = (string) config('connectix_bot.telegram.webhook_url');
        $panelToken = (string) config('connectix_bot.connectix.token');
        $admins = (int) $this->safe(fn (): int => Admin::query()->count(), -1);
        $appName = (string) config('connectix_bot.app_name');

        if ($appName === '') {
            // The panel is the authority for the name, exactly as it was for the
            // bot_config.json legacy wrote at install time.
            $appName = (string) $this->safe(fn (): string => $this->settings->appName(), '');
        }

        return [
            $this->check(
                'telegram.token',
                'توکن ربات تلگرام',
                $token !== '',
                true,
                $token === '' ? 'TELEGRAM_BOT_TOKEN تنظیم نشده است' : 'تنظیم شده است',
            ),
            $this->check(
                'telegram.webhook_url',
                'آدرس webhook',
                $webhookUrl !== '',
                true,
                $webhookUrl === '' ? 'TELEGRAM_WEBHOOK_URL تنظیم نشده است' : $webhookUrl,
            ),
            $this->check(
                'telegram.webhook_secret',
                'رمز webhook',
                $webhookSecret !== '',
                true,
                $webhookSecret === '' ? 'TELEGRAM_WEBHOOK_SECRET تنظیم نشده است' : 'تنظیم شده است',
            ),
            $this->check(
                'connectix.token',
                'توکن پنل Connectix',
                $panelToken !== '',
                true,
                $panelToken === '' ? 'CONNECTIX_PANEL_TOKEN تنظیم نشده است' : 'تنظیم شده است',
            ),
            $this->check(
                'admin.account',
                'حساب ادمین',
                $admins > 0,
                true,
                $admins < 0
                    ? 'جدول admins موجود نیست'
                    : ($admins === 0 ? 'هیچ ادمینی ساخته نشده است' : $admins.' ادمین'),
            ),
            $this->check(
                'bot-config.ready',
                'تنظیمات ربات',
                $appName !== '',
                false,
                $appName === ''
                    ? 'پنل هنوز نامی برای ربات برنگردانده است'
                    : $appName,
            ),
        ];
    }

    // -----------------------------------------------------------------
    // Live APIs
    // -----------------------------------------------------------------

    /**
     * @return array<int, array{key: string, label: string, ok: bool, critical: bool, message: string}>
     */
    private function apiChecks(): array
    {
        $bot = blank((string) config('connectix_bot.telegram.token'))
            ? ['ok' => false, 'message' => 'ابتدا توکن ربات تلگرام ثبت شود']
            : $this->probe(function (): array {
                $name = (string) (($this->telegram->getMe()['username'] ?? null) ?? '');

                return [
                    'ok' => $name !== '',
                    'message' => $name === '' ? 'getMe نام کاربری برنگرداند' : '@'.ltrim($name, '@'),
                ];
            }, 'TELEGRAM_BOT_TOKEN');

        $webhook = $this->webhookProbe();

        $panel = $this->probe(function (): array {
            $accepted = $this->connectix->verifySellerToken((string) config('connectix_bot.connectix.token'));

            return [
                'ok' => $accepted,
                'message' => $accepted ? 'توکن فروشنده پذیرفته شد' : 'پنل این توکن را نپذیرفت',
            ];
        }, 'CONNECTIX_PANEL_TOKEN');

        return [
            $this->check('telegram.api', 'ارتباط با Telegram', $bot['ok'], true, $bot['message']),
            $this->check('telegram.webhook.api', 'webhook ثبت‌شده در تلگرام', $webhook['ok'], true, $webhook['message']),
            $this->check('connectix.api', 'ارتباط با پنل Connectix', $panel['ok'], true, $panel['message']),
        ];
    }

    /**
     * Run one live check, turning any failure into a report line.
     *
     * @param  callable(): array{ok: bool, message: string}  $probe
     * @return array{ok: bool, message: string}
     */
    private function probe(callable $probe, string $key): array
    {
        try {
            return $probe();
        } catch (Throwable $e) {
            Log::warning('The installation check could not reach an API.', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function webhookProbe(): array
    {
        if (blank((string) config('connectix_bot.telegram.token'))) {
            return ['ok' => false, 'message' => 'ابتدا توکن ربات تلگرام ثبت شود'];
        }

        try {
            $info = $this->telegram->getWebhookInfo();
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $url = (string) config('connectix_bot.telegram.webhook_url');

        if (blank($info['url'] ?? null)) {
            return ['ok' => false, 'message' => 'هیچ webhook ای ثبت نشده است'];
        }

        if ($url !== '' && rtrim((string) $info['url'], '/') !== rtrim($url, '/')) {
            return ['ok' => false, 'message' => 'آدرس ثبت‌شده با تنظیمات فرق دارد: '.$info['url']];
        }

        if (($info['status'] ?? '') === 'pending_error') {
            return ['ok' => false, 'message' => 'آخرین خطای تلگرام: '.($info['last_error_message'] ?? 'نامشخص')];
        }

        return ['ok' => true, 'message' => (string) $info['url']];
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array{key: string, label: string, ok: bool, critical: bool, message: string}
     */
    private function check(string $key, string $label, bool $ok, bool $critical, string $message = ''): array
    {
        return compact('key', 'label', 'ok', 'critical', 'message');
    }

    private function isWritable(string $path): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }

        if (is_file($path)) {
            return is_writable($path);
        }

        return is_dir(dirname($path)) && is_writable(dirname($path));
    }

    /**
     * A lookup that must never take a page down with it.
     */
    private function safe(callable $callback, mixed $fallback = null): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return $fallback;
        }
    }
}
