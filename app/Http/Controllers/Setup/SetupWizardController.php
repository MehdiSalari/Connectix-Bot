<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use App\Enums\AdminRole;
use App\Enums\SetupStep;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Connectix\ConnectixService;
use App\Services\Migration\LegacyImportService;
use App\Services\Panel\PanelSettingsService;
use App\Services\Setup\DatabaseTester;
use App\Services\Setup\InstallationService;
use App\Services\Setup\SetupStateStore;
use App\Services\Setup\SetupWizard;
use App\Services\Sync\ClientSyncService;
use App\Services\Sync\WalletSyncService;
use App\Services\Telegram\TelegramService;
use App\Support\EnvWriter;
use App\Support\LogRedaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * The first-run installation wizard.
 *
 * This is the Laravel equivalent of legacy `setup/index.php`, `setup/setup.php`
 * and `setup/setup_progress.php`. The three legacy files were one form, one
 * script and one progress poll; the same work is here split into steps that can
 * each be checked, retried and reported, because a single streaming script on a
 * shared host gives an operator one line of log and no way back.
 *
 * Every credential typed here is written to `.env` through EnvWriter, which is
 * the only component allowed to write that file, and nothing echoes one back:
 * the views show whether a value is set, never what it is.
 */
class SetupWizardController extends Controller
{
    public function __construct(
        private readonly InstallationService $installation,
        private readonly SetupWizard $wizard,
        private readonly SetupStateStore $store,
        private readonly EnvWriter $env,
        private readonly DatabaseTester $databases,
        private readonly TelegramService $telegram,
        private readonly ConnectixService $connectix,
        private readonly PanelSettingsService $settings,
    ) {}

    // -----------------------------------------------------------------
    // Navigation
    // -----------------------------------------------------------------

    /**
     * Land on whatever still needs doing.
     */
    public function index(): RedirectResponse
    {
        return redirect()->route('setup.show', ['step' => $this->wizard->next()->value]);
    }

    public function show(string $step): View|RedirectResponse
    {
        $current = SetupStep::tryFrom($step);

        if ($current === null) {
            abort(404);
        }

        // A step whose input is not there yet redirects to the step that
        // produces it, so the wizard can never dead-end on a dead form.
        if (! $this->wizard->canOpen($current)) {
            return redirect()->route('setup.show', ['step' => $this->wizard->next()->value]);
        }

        return view('setup.step', array_merge(
            $this->viewData($current),
            ['stepData' => $this->stepData($current)],
        ));
    }

    // -----------------------------------------------------------------
    // Step 2: database
    // -----------------------------------------------------------------

    public function database(Request $request): RedirectResponse
    {
        $driver = (string) config('database.default');
        $input = $this->databaseInput($request, $driver);
        $create = $request->boolean('create_database');

        try {
            $probe = $this->databases->test($driver, $input, $create);
        } catch (Throwable $e) {
            Log::warning('The installer could not probe the database.', ['detail' => $this->redact($e->getMessage())]);

            $probe = ['ok' => false, 'message' => 'اتصال دیتابیس برقرار نشد.', 'code' => $e::class];
        }

        if (! $probe['ok']) {
            $this->store->markFailed(SetupStep::Database->value, $probe['message']);

            return redirect()->route('setup.show', ['step' => SetupStep::Database->value])
                ->withErrors(['database' => $probe['message']]);
        }

        $this->store->markStarted(SetupStep::Database->value);

        $values = $this->databaseValues($driver, $input);
        $values['DB_CONNECTION'] = $driver;

        $this->env->set($values)->apply();

        return redirect()->route('setup.show', ['step' => SetupStep::Migrations->value])
            ->with('status', 'اتصال دیتابیس برقرار شد و اطلاعات ذخیره شد.');
    }

    // -----------------------------------------------------------------
    // Step 3: schema
    // -----------------------------------------------------------------

    /**
     * Run the migrations. Never `migrate:fresh` and never `migrate:reset`: the
     * database this points at is very often a populated one, and legacy only
     * ever ran `CREATE TABLE IF NOT EXISTS`.
     */
    public function migrations(): RedirectResponse
    {
        $this->store->markStarted(SetupStep::Migrations->value);

        try {
            $exit = Artisan::call('migrate', ['--force' => true], $this->migrationOutput());
        } catch (Throwable $e) {
            Log::error('The installer could not run the migrations.', ['detail' => $this->redact($e->getMessage())]);

            $this->store->markFailed(SetupStep::Migrations->value, $this->safeMessage($e));

            return redirect()->route('setup.show', ['step' => SetupStep::Migrations->value])
                ->withErrors(['migrations' => 'اجرای migration ها ناموفق بود.']);
        }

        $log = trim(Artisan::output());

        if ($exit !== 0) {
            $this->store->markFailed(SetupStep::Migrations->value, 'migrate exited with '.$exit);

            return redirect()->route('setup.show', ['step' => SetupStep::Migrations->value])
                ->withErrors(['migrations' => 'اجرای migration ها ناموفق بود.'])
                ->with('log', $log);
        }

        $missing = $this->installation->missingTables();

        if ($missing !== []) {
            $this->store->markFailed(SetupStep::Migrations->value, 'missing tables');

            return redirect()->route('setup.show', ['step' => SetupStep::Migrations->value])
                ->withErrors(['migrations' => 'این جدول‌ها ساخته نشدند: '.implode('، ', $missing)])
                ->with('log', $log);
        }

        return redirect()->route('setup.show', ['step' => SetupStep::Connectix->value])
            ->with('status', 'جدول‌های لازم ساخته شدند.')
            ->with('log', $log);
    }

    /**
     * The installer is not a console: the migration output is collected instead
     * of printed, so the browser gets a log box like the legacy one and no
     * unstyled text before the page.
     */
    private function migrationOutput(): BufferedOutput
    {
        return new BufferedOutput;
    }

    // -----------------------------------------------------------------
    // Step 4: Connectix
    // -----------------------------------------------------------------

    /**
     * Port of legacy `getPanelToken()`, then a real authentication test against
     * the seller endpoint. A token that cannot be verified is not written.
     */
    public function connectix(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:190'],
            'password' => ['nullable', 'string'],
            'token' => ['nullable', 'string', 'max:400'],
            'base_url' => ['nullable', 'url', 'max:255'],
        ]);

        $token = trim((string) ($data['token'] ?? ''));

        try {
            if ($token === '') {
                $token = $this->connectix->login(
                    (string) ($data['email'] ?? ''),
                    (string) ($data['password'] ?? ''),
                );
            }

            if (! $this->connectix->verifySellerToken($token)) {
                throw new \RuntimeException('The panel did not accept this token.');
            }
        } catch (Throwable $e) {
            $this->store->markFailed(SetupStep::Connectix->value, $this->safeMessage($e));

            // The message describes the failure, never the credential: a failed
            // login from Connectix can echo the submitted address.
            return redirect()->route('setup.show', ['step' => SetupStep::Connectix->value])
                ->withErrors(['connectix' => 'اتصال به پنل برقرار نشد: '.$this->safeMessage($e)]);
        }

        $this->store->markStarted(SetupStep::Connectix->value);

        $values = ['CONNECTIX_PANEL_TOKEN' => $token];

        if (filled($data['base_url'] ?? null)) {
            $values['CONNECTIX_API_BASE_URL'] = (string) $data['base_url'];
        }

        $this->env->set($values)->apply();

        return redirect()->route('setup.show', ['step' => SetupStep::Telegram->value])
            ->with('status', 'اتصال به پنل Connectix برقرار شد.');
    }

    // -----------------------------------------------------------------
    // Step 5: Telegram
    // -----------------------------------------------------------------

    /**
     * The token is verified with `getMe` before it is stored, exactly as the
     * specification requires: an invalid token must not be able to mark the
     * installation as complete.
     */
    public function telegram(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:200'],
            'webhook_url' => ['nullable', 'url', 'max:255'],
        ]);

        $token = trim((string) $data['token']);

        try {
            $bot = $this->telegram->getMe($token);
        } catch (Throwable $e) {
            $this->store->markFailed(SetupStep::Telegram->value, $this->safeMessage($e));

            return redirect()->route('setup.show', ['step' => SetupStep::Telegram->value])
                ->withErrors(['telegram' => 'توکن تلگرام معتبر نیست: '.$this->safeMessage($e)]);
        }

        if (! is_array($bot) || blank($bot['username'] ?? null)) {
            $this->store->markFailed(SetupStep::Telegram->value, 'getMe returned no username');

            return redirect()->route('setup.show', ['step' => SetupStep::Telegram->value])
                ->withErrors(['telegram' => 'توکن تلگرام معتبر نیست.']);
        }

        $this->store->markStarted(SetupStep::Telegram->value);

        $url = trim((string) ($data['webhook_url'] ?? '')) ?: $this->guessWebhookUrl($request);

        $values = [
            'TELEGRAM_BOT_TOKEN' => $token,
            'TELEGRAM_WEBHOOK_URL' => $url,
            'TELEGRAM_WEBHOOK_SECRET' => $this->existingOrNewSecret(),
        ];

        $this->env->set($values)->apply();

        return redirect()->route('setup.show', ['step' => SetupStep::Webhook->value])
            ->with('status', 'ربات @'.ltrim((string) $bot['username'], '@').' شناسایی شد.');
    }

    // -----------------------------------------------------------------
    // Step 6: webhook
    // -----------------------------------------------------------------

    /**
     * Register the webhook, or only look at what is registered.
     *
     * `action=register` writes; `action=check` only reads, because an operator
     * whose host is behind a proxy frequently needs to see what Telegram has
     * before changing anything.
     */
    public function webhook(Request $request): RedirectResponse
    {
        $action = $request->string('action', 'check')->toString() === 'register' ? 'register' : 'check';
        $secret = (string) config('connectix_bot.telegram.webhook_secret');
        $url = (string) config('connectix_bot.telegram.webhook_url');

        if ($secret === '' || $url === '') {
            return redirect()->route('setup.show', ['step' => SetupStep::Telegram->value])
                ->withErrors(['webhook' => 'ابتدا توکن و آدرس webhook را ثبت کنید.']);
        }

        try {
            if ($action === 'register') {
                $this->telegram->setWebhook($url, array_filter(['secret_token' => $secret]));
            }

            $info = $this->telegram->getWebhookInfo() ?? [];
        } catch (Throwable $e) {
            $this->store->markFailed(SetupStep::Webhook->value, $this->safeMessage($e));

            return redirect()->route('setup.show', ['step' => SetupStep::Webhook->value])
                ->withErrors(['webhook' => 'ارتباط با تلگرام برقرار نشد: '.$this->safeMessage($e)]);
        }

        $registered = rtrim((string) ($info['url'] ?? ''), '/');
        $matches = $registered === rtrim($url, '/');

        if (! $matches) {
            // Reported either way: whether the operator asked to register or to
            // check, Telegram is not pointing at this installation, and saying
            // so in the same red box as a failed probe keeps the step honest.
            $this->store->markFailed(SetupStep::Webhook->value, 'webhook url mismatch');

            return redirect()->route('setup.show', ['step' => SetupStep::Webhook->value])
                ->withErrors(['webhook' => 'تلگرام آدرس دیگری را ثبت کرده است: '.($info['url'] ?: 'هیچ آدرسی')]);
        }

        $this->store->markStarted(SetupStep::Webhook->value);

        $status = (string) ($info['status'] ?? 'registered');

        return redirect()->route('setup.show', ['step' => SetupStep::Admin->value])
            ->with('status', 'Webhook: '.($status === 'pending_error' ? 'خطای آخرین ارسال' : $status));
    }

    // -----------------------------------------------------------------
    // Step 7: admin
    // -----------------------------------------------------------------

    /**
     * Port of legacy `setAdmin()`: an upsert on the email, with the panel token
     * stored in `admins.token` because the panel login reuses it, and the
     * password hashed by the model's `hashed` cast.
     *
     * An existing email is updated, which is legacy's `ON DUPLICATE KEY UPDATE`
     * behaviour and the reason re-running setup can fix a lost password. It only
     * ever touches the row whose email was typed: no other admin is modified.
     */
    public function admin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            'chat_id' => ['nullable', 'string', 'max:64'],
            'role' => ['nullable', 'in:admin,editor'],
        ]);

        $panelToken = (string) config('connectix_bot.connectix.token');
        $chatId = trim((string) ($data['chat_id'] ?? ''));

        $this->store->markStarted(SetupStep::Admin->value);

        $admin = Admin::query()->where('email', $data['email'])->first();

        $values = [
            'password' => (string) $data['password'],
            'token' => $panelToken,
            'chat_id' => $chatId,
            'role' => (string) ($data['role'] ?? AdminRole::Admin->value),
        ];

        if ($admin instanceof Admin) {
            $admin->fill($values)->save();
        } else {
            Admin::query()->create($values + ['email' => (string) $data['email']]);
        }

        // Legacy kept the admin chat id in the panel bot config too, and the
        // rewrite reads it from the environment when the panel has nothing.
        if ($chatId !== '') {
            $existing = array_values(array_filter(
                (array) config('connectix_bot.admin_ids', []),
                static fn (string $id): bool => $id !== $chatId,
            ));
            $existing[] = $chatId;

            $this->env->set(['CONNECTIX_BOT_ADMIN_IDS' => implode(',', $existing)])->apply();
        }

        return redirect()->route('setup.show', ['step' => SetupStep::BotConfig->value])
            ->with('status', 'حساب ادمین ذخیره شد.');
    }

    // -----------------------------------------------------------------
    // Step 8: bot configuration
    // -----------------------------------------------------------------

    /**
     * The bot_config step.
     *
     * Legacy fetched the seller panel's own branding and wrote it to
     * `setup/bot_config.json` at install time. The rewrite resolves the same
     * values through PanelSettingsService, so this step's job is to make the
     * operator decide whether the panel values are used as they are or pinned
     * locally - and to show them, which legacy never did.
     */
    public function botConfig(Request $request): RedirectResponse
    {
        $action = $request->string('action', 'show')->toString();

        if ($action === 'fetch') {
            $this->store->markStarted(SetupStep::BotConfig->value);

            try {
                $this->settings->refresh();
            } catch (Throwable $e) {
                $this->store->markFailed(SetupStep::BotConfig->value, $this->safeMessage($e));

                return redirect()->route('setup.show', ['step' => SetupStep::BotConfig->value])
                    ->withErrors(['bot_config' => 'خواندن تنظیمات از پنل ناموفق بود: '.$this->safeMessage($e)]);
            }

            return redirect()->route('setup.show', ['step' => SetupStep::BotConfig->value])
                ->with('status', 'تنظیمات از پنل خوانده شد.');
        }

        $data = $request->validate([
            'app_name' => ['nullable', 'string', 'max:120'],
            'support_telegram' => ['nullable', 'string', 'max:120'],
            'channel_telegram' => ['nullable', 'string', 'max:120'],
            'card_number' => ['nullable', 'string', 'max:64'],
            'card_name' => ['nullable', 'string', 'max:120'],
            'test' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'force_channel_join' => ['nullable', 'boolean'],
        ]);

        $values = array_filter([
            'CONNECTIX_BOT_APP_NAME' => $data['app_name'] ?? null,
            'CONNECTIX_BOT_SUPPORT_TELEGRAM' => $data['support_telegram'] ?? null,
            'CONNECTIX_BOT_CHANNEL_TELEGRAM' => $data['channel_telegram'] ?? null,
            'CONNECTIX_BOT_CARD_NUMBER' => $data['card_number'] ?? null,
            'CONNECTIX_BOT_CARD_NAME' => $data['card_name'] ?? null,
        ], static fn (?string $value): bool => filled($value));

        $flags = [
            'CONNECTIX_BOT_TEST_ENABLED' => $request->boolean('test') ? 'true' : 'false',
            'CONNECTIX_BOT_ACTIVE' => $request->boolean('active', true) ? 'true' : 'false',
            'CONNECTIX_BOT_FORCE_CHANNEL_JOIN' => $request->boolean('force_channel_join') ? 'true' : 'false',
        ];

        $this->store->markStarted(SetupStep::BotConfig->value);

        $this->env->set($values + $flags)->apply();

        // Values typed here are the reseller's own, so they are kept in
        // panel_settings as well: that is where the admin settings page edits
        // them, and it survives an .env rewrite.
        if ($values !== []) {
            $overrides = $this->settings->overrides();

            $this->settings->saveOverrides(array_filter([
                'app_name' => $data['app_name'] ?? null,
                'support_telegram' => $data['support_telegram'] ?? null,
                'channel_telegram' => $data['channel_telegram'] ?? null,
                'card_number' => $data['card_number'] ?? null,
                'card_name' => $data['card_name'] ?? null,
            ], static fn (?string $value): bool => filled($value)) + $overrides);
        }

        return redirect()->route('setup.show', ['step' => SetupStep::Import->value])
            ->with('status', 'تنظیمات ربات ذخیره شد.');
    }

    // -----------------------------------------------------------------
    // Step 9: import
    // -----------------------------------------------------------------

    /**
     * Bring the data over.
     *
     * Two independent sources, matching what legacy did at the end of setup: the
     * rows of the previous installation, and the seller panel. Both are
     * insert-only and both are safe to repeat, so this button can be pressed
     * again after a failure instead of starting over.
     */
    public function import(Request $request): RedirectResponse
    {
        $action = $request->string('action', 'none')->toString();
        $this->store->markStarted(SetupStep::Import->value);

        if ($action === 'legacy') {
            return $this->importLegacy($request);
        }

        if ($action === 'panel') {
            return $this->importFromPanel();
        }

        return redirect()->route('setup.show', ['step' => SetupStep::Import->value]);
    }

    private function importLegacy(Request $request): RedirectResponse
    {
        // An installation with no previous database to read is the normal case,
        // not a failure: there is nothing to bring over and the operator should
        // be told that in a sentence instead of a red box.
        if (blank((string) config('database.connections.legacy.database'))) {
            return redirect()->route('setup.show', ['step' => SetupStep::Import->value])
                ->with('status', 'دیتابیس قبلی پیکربندی نشده است؛ انتقال از نصب قبلی انجام نشد.');
        }

        $data = $request->validate([
            'legacy_host' => ['nullable', 'string', 'max:190'],
            'legacy_port' => ['nullable', 'string', 'max:10'],
            'legacy_database' => ['nullable', 'string', 'max:190'],
            'legacy_username' => ['nullable', 'string', 'max:190'],
            'legacy_password' => ['nullable', 'string', 'max:255'],
        ]);

        $values = array_filter([
            'LEGACY_DB_HOST' => $data['legacy_host'] ?? null,
            'LEGACY_DB_PORT' => $data['legacy_port'] ?? null,
            'LEGACY_DB_DATABASE' => $data['legacy_database'] ?? null,
            'LEGACY_DB_USERNAME' => $data['legacy_username'] ?? null,
            'LEGACY_DB_PASSWORD' => $data['legacy_password'] ?? null,
        ], static fn (?string $value): bool => filled($value));

        if ($values !== []) {
            $this->env->set($values)->apply();
        }

        $dryRun = ! $request->boolean('confirm');
        $lines = [];

        try {
            $report = app(LegacyImportService::class)->import(
                only: $request->input('tables') ?: null,
                dryRun: $dryRun,
                chunk: 200,
                onLine: function (string $line) use (&$lines): void {
                    if (count($lines) < 200) {
                        $lines[] = $line;
                    }
                },
            );
        } catch (Throwable $e) {
            Log::error('The installer could not read the legacy database.', ['detail' => $this->redact($e->getMessage())]);
            $this->store->markFailed(SetupStep::Import->value, $this->safeMessage($e));

            return redirect()->route('setup.show', ['step' => SetupStep::Import->value])
                ->withErrors(['import' => 'خواندن دیتابیس قبلی ناموفق بود. اتصال را بررسی کنید.']);
        }

        return redirect()->route('setup.show', ['step' => SetupStep::Import->value])
            ->with('status', $dryRun
                ? 'حالت آزمایشی اجرا شد؛ چیزی نوشته نشد.'
                : 'انتقال اطلاعات انجام شد.')
            ->with('report', $report)
            ->with('log', implode(PHP_EOL, $lines));
    }

    private function importFromPanel(): RedirectResponse
    {
        $lines = [];
        $writer = function (string $line) use (&$lines): void {
            if (count($lines) < 200) {
                $lines[] = $line;
            }
        };

        try {
            // A page cap, not the whole panel: the wizard is an HTTP request on a
            // shared host. Running it again continues where this stopped, which
            // is what the daily sync job does afterwards anyway.
            $clients = app(ClientSyncService::class)->sync($writer, 5);
            $wallets = app(WalletSyncService::class)->sync($writer);
        } catch (Throwable $e) {
            Log::error('The installer could not sync from the seller panel.', ['detail' => $this->redact($e->getMessage())]);
            $this->store->markFailed(SetupStep::Import->value, $this->safeMessage($e));

            return redirect()->route('setup.show', ['step' => SetupStep::Import->value])
                ->withErrors(['import' => 'خواندن اطلاعات از پنل ناموفق بود.']);
        }

        return redirect()->route('setup.show', ['step' => SetupStep::Import->value])
            ->with('status', 'اطلاعات پنل خوانده شد.')
            ->with('report', ['clients' => $clients, 'wallets' => $wallets])
            ->with('log', implode(PHP_EOL, $lines));
    }

    // -----------------------------------------------------------------
    // Step 10: completion
    // -----------------------------------------------------------------

    /**
     * The final validation, then the completion state.
     *
     * This is the only place that marks the installation complete, and it only
     * does so when every critical check passes - including the two live API
     * calls, which is why a token that Telegram or the panel rejects cannot end
     * with "installed".
     */
    public function complete(): RedirectResponse|View
    {
        $this->store->markStarted(SetupStep::Complete->value);

        $checks = $this->installation->checks(probe: true);
        $failures = $this->installation->failures(probe: true);

        if ($failures !== []) {
            $this->store->markFailed(SetupStep::Complete->value, implode(',', $failures));

            return view('setup.step', array_merge(
                $this->viewData(SetupStep::Complete, probe: true),
                ['stepData' => $this->stepData(SetupStep::Complete, probe: true), 'installationFailed' => true],
            ));
        }

        $this->installation->markCompleted();

        return redirect()->route('setup.done')
            ->with('status', 'نصب با موفقیت انجام شد.')
            // Stamped in this session only, and read only by ProtectSetup to let
            // the closing report through: the installer is closed to everyone
            // else the moment it is installed.
            ->with('setup.completed', true);
    }

    public function done(): View
    {
        return view('setup.done', $this->viewData(SetupStep::Complete, probe: true));
    }

    /**
     * Forget the wizard's progress so it can be walked again.
     *
     * No database, admin or configuration is touched: the state file is the only
     * thing removed, and the wizard then re-derives the real state from live
     * checks, which normally means the next page is the report again rather than
     * a fresh install.
     */
    public function reset(): RedirectResponse
    {
        if (! request()->boolean('confirm')) {
            return redirect()->route('setup.show', ['step' => SetupStep::Complete->value])
                ->withErrors(['reset' => 'برای شروع مجدد باید تایید کنید.']);
        }

        $this->store->reset();

        return redirect()->route('setup.index')
            ->with('status', 'وضعیت نصب پاک شد. هیچ داده‌ای حذف نشده است.');
    }

    // -----------------------------------------------------------------
    // View data
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function viewData(SetupStep $step, bool $probe = false): array
    {
        return [
            'step' => $step,
            'steps' => $this->wizard->steps(),
            'progress' => $this->wizard->progress($probe),
            'checks' => $this->installation->checks($probe),
            'installed' => $this->installation->isInstalled(),
            'summary' => $this->installation->summary($probe),
            'stateFile' => (string) config('setup.state_file'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stepData(SetupStep $step, bool $probe = false): array
    {
        return match ($step) {
            SetupStep::Database => [
                'driver' => (string) config('database.default'),
                'host' => (string) config('database.connections.'.config('database.default').'.host', 'localhost'),
                'port' => (string) config('database.connections.'.config('database.default').'.port', '3306'),
                'name' => (string) config('database.connections.'.config('database.default').'.database', ''),
                'username' => (string) config('database.connections.'.config('database.default').'.username', ''),
                'hasPassword' => filled(config('database.connections.'.config('database.default').'.password')),
                'keys' => $this->databases->keysFor((string) config('database.default')),
            ],
            SetupStep::Migrations => [
                'missing' => $this->installation->missingTables(),
                'pending' => $this->installation->pendingMigrations(),
            ],
            SetupStep::Connectix => [
                'hasToken' => filled(config('connectix_bot.connectix.token')),
                'baseUrl' => (string) config('connectix_bot.connectix.base_url'),
            ],
            SetupStep::Telegram => [
                'hasToken' => filled(config('connectix_bot.telegram.token')),
                'hasSecret' => filled(config('connectix_bot.telegram.webhook_secret')),
                'webhookUrl' => (string) config('connectix_bot.telegram.webhook_url'),
            ],
            SetupStep::Webhook => [
                'url' => (string) config('connectix_bot.telegram.webhook_url'),
                'hasSecret' => filled(config('connectix_bot.telegram.webhook_secret')),
            ],
            SetupStep::Admin => [
                'admins' => $this->safeAdmins(),
            ],
            SetupStep::BotConfig => [
                'current' => $this->settings->all(),
                'overrides' => $this->settings->overrides(),
                'hasToken' => filled(config('connectix_bot.connectix.token')),
            ],
            SetupStep::Import => [
                'hasLegacy' => filled(config('database.connections.legacy.database')),
                'tables' => array_keys(LegacyImportService::TABLES),
                'counts' => $this->tableCounts(),
            ],
            SetupStep::Complete => [
                'summary' => $this->installation->summary($probe),
            ],
            default => [],
        };
    }

    /**
     * Never the password or the panel token.
     *
     * @return array<int, array<string, string>>
     */
    private function safeAdmins(): array
    {
        try {
            return Admin::query()
                ->orderBy('id')
                ->get(['id', 'email', 'role', 'chat_id'])
                ->map(fn (Admin $admin): array => [
                    'id' => (string) $admin->id,
                    'email' => (string) $admin->email,
                    'role' => $admin->role?->value ?? 'admin',
                    'chat_id' => (string) $admin->chat_id,
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        $counts = [];

        foreach ((array) config('setup.tables', []) as $table) {
            try {
                $counts[(string) $table] = Schema::hasTable($table)
                    ? (int) DB::table($table)->count()
                    : 0;
            } catch (Throwable) {
                $counts[(string) $table] = 0;
            }
        }

        return $counts;
    }

    // -----------------------------------------------------------------
    // Input
    // -----------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function databaseInput(Request $request, string $driver): array
    {
        $data = $request->validate([
            'host' => ['nullable', 'string', 'max:190'],
            'port' => ['nullable', 'string', 'max:10'],
            'name' => ['nullable', 'string', 'max:190'],
            'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);

        if (strtolower($driver) === 'sqlite') {
            return ['database' => (string) ($data['name'] ?? '')];
        }

        return [
            'host' => trim((string) ($data['host'] ?? '')) ?: 'localhost',
            'port' => trim((string) ($data['port'] ?? '')) ?: '3306',
            'database' => trim((string) ($data['name'] ?? '')),
            'username' => trim((string) ($data['username'] ?? '')),
            'password' => (string) ($data['password'] ?? ''),
        ];
    }

    /**
     * @param  array<string, string>  $input
     * @return array<string, string>
     */
    private function databaseValues(string $driver, array $input): array
    {
        $keys = $this->databases->keysFor($driver);
        $sources = [
            'DB_HOST' => $input['host'] ?? null,
            'DB_PORT' => $input['port'] ?? null,
            'DB_DATABASE' => $input['database'] ?? null,
            'DB_USERNAME' => $input['username'] ?? null,
            'DB_PASSWORD' => $input['password'] ?? null,
        ];

        $values = [];

        foreach ($keys as $key) {
            if (filled($sources[$key] ?? null)) {
                $values[$key] = (string) $sources[$key];
            }
        }

        return $values;
    }

    /**
     * The URL Telegram should call, worked out from the request that opened the
     * installer, with the same rules legacy `setBotWebhook()` used: a forwarded
     * https wins over a plain http one, because behind a reverse proxy the
     * connection the browser sees is the one that matters.
     */
    private function guessWebhookUrl(Request $request): string
    {
        $proxied = $request->header('X-Forwarded-Proto') === 'https'
            || $request->header('X-Forwarded-Ssl') === 'on';

        $scheme = $request->isSecure() || $proxied ? 'https' : 'http';

        return $scheme.'://'.$request->getHost().route('telegram.webhook', absolute: false);
    }

    /**
     * Keep an existing webhook secret, generate one when there is none.
     */
    private function existingOrNewSecret(): string
    {
        $existing = (string) config('connectix_bot.telegram.webhook_secret');

        return $existing !== '' ? $existing : EnvWriter::randomSecret();
    }

    /**
     * A message safe to put in front of an operator and in the log.
     *
     * The driver of a failed login echoes the submitted address and the driver of
     * a failed database call echoes the username, password or DSN, so anything
     * that looks like a credential is masked first and the rest is reduced to a
     * single short line. The point is that a shared host's log file, which every
     * other application on the box may be able to read, never becomes a place
     * where the installer's secrets turn up.
     */
    private function safeMessage(Throwable $e): string
    {
        Log::warning('The installer reported a failed step.', [
            'exception' => $e::class,
            'detail' => $this->redact($e->getMessage()),
        ]);

        $firstLine = (string) str($this->redact($e->getMessage()))->limit(180, '');

        return $firstLine !== '' ? $firstLine : 'ارتباط برقرار نشد.';
    }

    /**
     * Mask anything written as `password=.`, `token: .` and friends, whatever
     * quoting the driver or the API used, plus the bot token inside a Telegram
     * URL. The pattern lives in `App\Support\LogRedaction` so the installer and
     * every other log site agree on what a secret looks like.
     */
    private function redact(string $message): string
    {
        return LogRedaction::mask($message);
    }
}
