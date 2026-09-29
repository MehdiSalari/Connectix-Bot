<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\SetupState;
use App\Models\Admin;
use App\Services\Setup\ApplicationKey;
use App\Services\Setup\InstallationService;
use App\Services\Setup\SetupStateStore;
use App\Support\EnvWriter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The installation wizard: the Laravel replacement for legacy `setup/index.php`,
 * `setup/setup.php` and `setup/setup_progress.php`.
 *
 * The scenario names come from the specification's own list - fresh install,
 * missing or invalid configuration, an existing database, a failed step, a retry,
 * and access after installation - because those are the cases that decide whether
 * a deployment can recover without a developer.
 *
 * The first-run guard is off for the rest of the suite (see phpunit.xml) and
 * switched on per test here, so these tests never have to guess whether the
 * application they are exercising is "installed".
 *
 * DatabaseMigrations rather than RefreshDatabase: the whole point of these tests
 * is that the installer creates the schema, and a wrapping transaction makes
 * SQLite refuse the DDL the migrations need.
 */
class SetupTest extends TestCase
{
    use DatabaseMigrations;

    private string $stateFile;

    private string $envFile;

    private string $keyFile;

    /**
     * The sqlite file this test class runs against. A real file rather than the
     * suite's `:memory:` default, because the database step is allowed to change
     * the connection and purge it: on an in memory database that hands the next
     * statement an empty one and the schema disappears mid-test. DatabaseMigrations
     * runs `migrate:fresh` for every test, so one file is enough for isolation.
     */
    private static string $databaseFile;

    /**
     * The environment before this test, so a step that calls EnvWriter::apply()
     * cannot leak a database path or a token into the next test's application.
     *
     * @var array<string, string|false>
     */
    private array $environment = [];

    /**
     * The connection settings before this test, so a step that pointed the
     * application at a new database does not leave the harness pointing at it too.
     *
     * @var array<string, mixed>
     */
    private array $databaseConfig = [];

    public static function setUpBeforeClass(): void
    {
        self::$databaseFile = self::scratchPath('setup-suite.sqlite');
    }

    public static function tearDownAfterClass(): void
    {
        // Windows keeps the file locked while the last connection is closing, so
        // this is best effort: the directory is a scratch one and is gitignored.
        if (is_file(self::$databaseFile)) {
            @unlink(self::$databaseFile);
        }
    }

    protected function setUp(): void
    {
        // Captured before the application exists, so the suite's own default is
        // what gets restored rather than this class's file.
        $this->environment = ['DB_DATABASE' => getenv('DB_DATABASE')];

        if (! is_file(self::$databaseFile)) {
            touch(self::$databaseFile);
        }

        // Set before the application boots, so config/database.php reads it.
        putenv('DB_DATABASE='.self::$databaseFile);
        $_ENV['DB_DATABASE'] = self::$databaseFile;
        $_SERVER['DB_DATABASE'] = self::$databaseFile;

        parent::setUp();

        Cache::flush();

        $this->stateFile = storage_path('framework/testing/'.uniqid('setup', true).'.json');
        $this->envFile = storage_path('framework/testing/'.uniqid('setup', true).'.env');
        $this->keyFile = storage_path('framework/testing/'.uniqid('setup', true).'.key');

        foreach (array_keys((array) config('setup.config_map')) as $key) {
            if ($key !== 'DB_DATABASE') {
                $this->environment[$key] = getenv((string) $key);
            }
        }

        $this->databaseConfig = config('database');

        config([
            'setup.enforce' => true,
            'setup.state_file' => $this->stateFile,
            'setup.env_file' => $this->envFile,
            'connectix_bot.app_name' => null,
            'connectix_bot.telegram.token' => null,
            'connectix_bot.telegram.webhook_secret' => null,
            'connectix_bot.telegram.webhook_url' => null,
            'connectix_bot.connectix.token' => null,
            'connectix_bot.admin_ids' => [],
            'setup.key_file' => $this->keyFile,
        ]);
    }

    protected function tearDown(): void
    {
        // The database step points the application at a different database, and
        // the harness is put back before parent::tearDown(): the DatabaseMigrations
        // trait rolls the schema back there, and it must not roll back a database
        // the wizard just created instead of the test one.
        if (config('database') !== $this->databaseConfig) {
            config(['database' => $this->databaseConfig]);
            DB::purge();
        }

        parent::tearDown();

        foreach ($this->environment as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        foreach ([$this->stateFile, $this->envFile, $this->keyFile] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * A path under storage/framework/testing that can be built before the
     * application exists, since the database has to be pointed at a real file
     * before config/database.php is read.
     */
    private static function scratchPath(string $name): string
    {
        $directory = dirname(__DIR__, 2).'/storage/framework/testing';

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory.'/'.$name;
    }

    // -----------------------------------------------------------------
    // First run detection
    // -----------------------------------------------------------------

    #[Test]
    public function a_fresh_application_is_sent_to_the_installer(): void
    {
        // No tables at all: this is a deployment where nothing has been run yet.
        foreach (config('setup.tables') as $table) {
            Schema::dropIfExists($table);
        }

        $this->assertFalse(app(InstallationService::class)->isInstalled());

        $this->get('/')->assertRedirect('/setup');

        // The environment is healthy here, so the wizard lands on the first step
        // that still has work to do rather than on the environment report.
        $this->get('/setup')->assertRedirect('/setup/migrations');
    }

    #[Test]
    public function the_installer_and_the_health_check_answer_before_anything_is_configured(): void
    {
        foreach (config('setup.tables') as $table) {
            Schema::dropIfExists($table);
        }

        $this->get('/setup/requirements')->assertOk()->assertSee('افزونه‌های PHP');
        $this->get('/up')->assertOk();
    }

    #[Test]
    public function a_machine_endpoint_answers_json_instead_of_a_redirect(): void
    {
        foreach (config('setup.tables') as $table) {
            Schema::dropIfExists($table);
        }

        $response = $this->postJson('/bank/sms', ['message' => 'hello']);

        $response->assertStatus(503)
            ->assertJsonPath('setup_url', route('setup.index'));
    }

    #[Test]
    public function a_configured_application_serves_its_normal_routes(): void
    {
        $this->configureApplication();

        $this->get('/')->assertOk();
        $this->assertTrue(app(InstallationService::class)->isInstalled());
    }

    // -----------------------------------------------------------------
    // The state abstraction
    // -----------------------------------------------------------------

    #[Test]
    public function the_state_comes_from_live_checks_and_not_from_the_state_file(): void
    {
        $service = app(InstallationService::class);

        $this->assertSame(SetupState::NotInstalled, $service->state());

        app(SetupStateStore::class)->markCompleted();

        // The marker alone must not make an unconfigured application look
        // installed: a deleted or forged state file cannot unlock the runtime.
        $this->assertSame(SetupState::NotInstalled, $service->state());
        $this->assertFalse($service->isInstalled());

        $this->configureApplication();

        $this->assertSame(SetupState::Configured, $service->state());
    }

    #[Test]
    public function a_missing_admin_means_the_application_is_not_installed(): void
    {
        $this->configureApplication();

        Admin::query()->delete();

        $service = app(InstallationService::class);

        $this->assertFalse($service->isInstalled());
        $this->assertContains('admin.account', $service->failures());
    }

    #[Test]
    public function the_environment_file_alone_does_not_install_the_application(): void
    {
        // A hand-configured deployment: every secret is present in .env but no
        // admin exists yet. This is the state between writing credentials and
        // creating the first admin, and it must still be "not installed".
        (new EnvWriter)->set([
            'TELEGRAM_BOT_TOKEN' => '123456:token',
            'TELEGRAM_WEBHOOK_URL' => 'https://example.com/telegram/webhook',
            'TELEGRAM_WEBHOOK_SECRET' => str_repeat('a', 64),
            'CONNECTIX_PANEL_TOKEN' => 'panel-token',
        ])->apply();

        $this->assertContains('admin.account', app(InstallationService::class)->failures());
    }

    // -----------------------------------------------------------------
    // Steps
    // -----------------------------------------------------------------

    #[Test]
    public function the_database_step_rejects_a_connection_it_cannot_make(): void
    {
        // SQLite with a path inside a directory that does not exist.
        config(['database.default' => 'sqlite']);

        $this->post('/setup/database', [
            'name' => storage_path('framework/testing/missing-dir-'.uniqid().'/db.sqlite'),
        ])->assertSessionHasErrors('database');

        $this->assertFileDoesNotExist($this->envFile);
    }

    #[Test]
    public function the_database_step_stores_the_connection(): void
    {
        $database = storage_path('framework/testing/setup-'.uniqid().'.sqlite');
        touch($database);

        config(['database.default' => 'sqlite']);

        $this->post('/setup/database', ['name' => $database])
            ->assertRedirect('/setup/migrations');

        $this->assertSame($database, config('database.connections.sqlite.database'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertStringContainsString('DB_CONNECTION="sqlite"', (string) file_get_contents($this->envFile));

        // The wizard connects to what it just saved, so the schema step of the
        // next request has to see this database and not the one from .env.
        $this->assertSame($database, DB::connection()->getDatabaseName());
    }

    #[Test]
    public function a_missing_encryption_key_is_created_before_the_wizard_is_reachable(): void
    {
        // A release that was unpacked without an APP_KEY still has to render the
        // installer: EncryptCookies and the session refuse to boot without one.
        config(['app.key' => '']);

        $key = (new ApplicationKey)->ensure() ?? $this->fail('No key was generated.');

        $this->assertStringStartsWith('base64:', $key);
        $this->assertSame($key, config('app.key'));
        $this->assertSame($key, (new EnvWriter)->get('APP_KEY'));

        // Stable across requests, or the wizard's session dies after each click.
        $this->assertNull((new ApplicationKey)->ensure());
        $this->assertSame($key, config('app.key'));
    }

    #[Test]
    public function the_migration_step_creates_a_fresh_schema(): void
    {
        // Nothing has ever been migrated on this database: the state a first
        // install starts from, and the one the legacy installer handled with
        // CREATE TABLE IF NOT EXISTS. Every table, not just the ones the checks
        // know about - a deployment can carry extra tables from a previous life.
        Schema::dropAllTables();

        $this->post('/setup/migrations')->assertRedirect('/setup/connectix');

        foreach (config('setup.tables') as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' was not created.');
        }
    }

    #[Test]
    public function the_migration_step_never_removes_existing_data(): void
    {
        // An upgrade in place: the schema is already there and populated. The
        // migrations have to be a no-op, never a rebuild.
        DB::table('users')->insert(['chat_id' => 'keep-me', 'created_at' => now()]);

        $this->post('/setup/migrations')->assertRedirect('/setup/connectix');

        $this->assertSame(1, DB::table('users')->where('chat_id', 'keep-me')->count());
    }

    #[Test]
    public function the_connectix_step_logs_in_and_stores_the_token(): void
    {
        Http::fake([
            'https://api.connectix.vip/v1/seller/auth/login' => Http::response(['token' => 'panel-token'], 200),
            'https://api.connectix.vip/v1/seller/seller-data' => Http::response([
                'data' => ['seller' => ['id' => 7]],
            ], 200),
        ]);

        $this->post('/setup/connectix', [
            'email' => 'seller@connectix.vip',
            'password' => 'a-password',
        ])->assertRedirect('/setup/telegram');

        $this->assertSame('panel-token', config('connectix_bot.connectix.token'));
        $this->assertStringContainsString('CONNECTIX_PANEL_TOKEN="panel-token"', (string) file_get_contents($this->envFile));
    }

    #[Test]
    public function invalid_connectix_credentials_are_not_stored(): void
    {
        Http::fake([
            'https://api.connectix.vip/v1/seller/auth/login' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        $this->post('/setup/connectix', [
            'email' => 'seller@connectix.vip',
            'password' => 'wrong',
        ])->assertSessionHasErrors('connectix');

        $this->assertNull(config('connectix_bot.connectix.token'));
    }

    #[Test]
    public function an_invalid_telegram_token_does_not_complete_the_setup(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => false,
                'description' => 'Unauthorized',
            ], 401),
        ]);

        $this->post('/setup/telegram', ['token' => '123456:not-a-real-token'])
            ->assertSessionHasErrors('telegram');

        $this->assertNull(config('connectix_bot.telegram.token'));
    }

    #[Test]
    public function a_valid_telegram_token_stores_the_token_a_secret_and_the_webhook_url(): void
    {
        $this->fakeTelegram();

        $this->post('/setup/telegram', [
            'token' => '123456:valid-telegram-token',
            'webhook_url' => 'https://example.com/telegram/webhook',
        ])->assertRedirect('/setup/webhook');

        $this->assertSame('123456:valid-telegram-token', config('connectix_bot.telegram.token'));
        $this->assertSame('https://example.com/telegram/webhook', config('connectix_bot.telegram.webhook_url'));
        $this->assertSame(64, strlen((string) config('connectix_bot.telegram.webhook_secret')));
    }

    #[Test]
    public function an_existing_webhook_secret_is_kept(): void
    {
        config(['connectix_bot.telegram.webhook_secret' => str_repeat('b', 64)]);
        $this->fakeTelegram();

        $this->post('/setup/telegram', ['token' => '123456:valid-telegram-token']);

        $this->assertSame(str_repeat('b', 64), config('connectix_bot.telegram.webhook_secret'));
    }

    #[Test]
    public function the_webhook_step_registers_the_url_and_reports_the_status(): void
    {
        $this->configureApplication();
        $this->actingAsOwner();
        $this->fakeTelegram();

        $this->post('/setup/webhook', ['action' => 'register'])
            ->assertRedirect('/setup/admin')
            ->assertSessionHas('status');

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'setWebhook')
                && $request['url'] === 'https://example.com/telegram/webhook';
        });
    }

    #[Test]
    public function a_mismatched_webhook_url_is_reported_instead_of_being_silently_accepted(): void
    {
        $this->configureApplication();
        $this->actingAsOwner();
        Http::fake([
            'https://api.telegram.org/*/getWebhookInfo' => Http::response([
                'ok' => true,
                'result' => ['url' => 'https://elsewhere.example/bot.php', 'status' => 'registered'],
            ], 200),
        ]);

        $this->post('/setup/webhook', ['action' => 'check'])
            ->assertSessionHasErrors('webhook');
    }

    #[Test]
    public function the_admin_step_creates_the_first_admin_with_a_hashed_password(): void
    {
        $this->configureApplication(withoutAdmin: true);

        $this->post('/setup/admin', [
            'email' => 'owner@example.com',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
            'chat_id' => '123456789',
            'role' => 'admin',
        ])->assertRedirect('/setup/bot-config');

        $admin = Admin::query()->where('email', 'owner@example.com')->firstOrFail();

        $this->assertNotSame('a-strong-password', $admin->password);
        $this->assertTrue(password_verify('a-strong-password', $admin->password));
        $this->assertSame('123456789', $admin->chat_id);
        $this->assertSame(AdminRole::Admin, $admin->role);
        $this->assertSame(['123456789'], config('connectix_bot.admin_ids'));
    }

    #[Test]
    public function re_running_the_admin_step_updates_only_the_typed_account(): void
    {
        $this->configureApplication();
        $this->actingAsOwner();
        $other = Admin::query()->create([
            'email' => 'editor@example.com',
            'password' => 'editor-password',
            'token' => 'token',
            'chat_id' => '1',
            'role' => AdminRole::Editor->value,
        ]);

        $this->post('/setup/admin', [
            'email' => 'owner@example.com',
            'password' => 'rotated-password',
            'password_confirmation' => 'rotated-password',
        ])->assertRedirect('/setup/bot-config');

        $this->assertTrue(
            password_verify('rotated-password', Admin::query()->where('email', 'owner@example.com')->value('password'))
        );
        $this->assertTrue(password_verify('editor-password', $other->fresh()->password));
    }

    #[Test]
    public function the_bot_configuration_step_reads_the_panel_and_pins_local_values(): void
    {
        $this->configureApplication();
        $this->actingAsOwner();

        Http::fake([
            'https://api.connectix.vip/v1/seller/telegram-bot' => Http::response([
                'bot' => ['app_name' => 'Acme VPN', 'support_telegram' => '@acme'],
                'telegram_messages' => [],
            ], 200),
        ]);

        $this->post('/setup/bot-config', ['action' => 'fetch'])
            ->assertRedirect('/setup/bot-config')
            ->assertSessionHas('status');

        $this->post('/setup/bot-config', [
            'action' => 'save',
            'app_name' => 'My Own Brand',
            'active' => '1',
        ])->assertRedirect('/setup/import');

        $this->assertSame('My Own Brand', config('connectix_bot.app_name'));
        $this->assertSame('My Own Brand', DB::table('panel_settings')->where('setting_key', 'app_name')->value('setting_value'));
    }

    #[Test]
    public function the_import_step_runs_in_dry_run_mode_until_it_is_confirmed(): void
    {
        $this->configureApplication();
        $this->actingAsOwner();

        $this->post('/setup/import', ['action' => 'legacy'])
            ->assertRedirect('/setup/import')
            ->assertSessionHas('status');

        $this->assertSame(0, DB::table('users')->count());
    }

    // -----------------------------------------------------------------
    // Completion
    // -----------------------------------------------------------------

    #[Test]
    public function completion_is_refused_while_a_critical_check_fails(): void
    {
        $this->configureApplication();
        $this->actingAsOwner();
        $this->fakeTelegram();

        // A panel that rejects the token is exactly the "invalid Connectix
        // credentials" case from the specification's list.
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'bot']], 200),
            'https://api.connectix.vip/v1/seller/seller-data' => Http::response(['message' => 'nope'], 403),
        ]);

        $this->post('/setup/complete')
            ->assertOk()
            ->assertSee('نصب کامل اعلام نشد');

        $this->assertNotSame(SetupState::Configured, app(SetupStateStore::class)->state());
    }

    #[Test]
    public function a_fully_validated_installation_is_marked_complete(): void
    {
        $this->configureApplication();
        $this->actingAsOwner();

        Http::fake([
            'https://api.telegram.org/*/getMe' => Http::response([
                'ok' => true,
                'result' => ['username' => 'acme_bot'],
            ], 200),
            'https://api.telegram.org/*/getWebhookInfo' => Http::response([
                'ok' => true,
                'result' => [
                    'url' => 'https://example.com/telegram/webhook',
                    'status' => 'registered',
                ],
            ], 200),
            'https://api.connectix.vip/v1/seller/seller-data' => Http::response([
                'data' => ['seller' => ['id' => 1]],
            ], 200),
        ]);

        $this->post('/setup/complete')->assertRedirect('/setup/done');

        $this->assertSame(SetupState::Configured, app(SetupStateStore::class)->state());
        $this->assertIsString(app(SetupStateStore::class)->completedAt());

        // The operator who installed it is not signed in to the panel yet, so the
        // closing report has to be reachable for them - and only for them.
        $this->get('/setup/done')->assertOk();
    }

    #[Test]
    public function the_closing_report_is_not_open_to_anyone_else(): void
    {
        $this->configureApplication();

        $this->get('/setup/done')->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Access after installation
    // -----------------------------------------------------------------

    #[Test]
    public function the_installer_is_closed_to_anonymous_visitors_once_installed(): void
    {
        $this->configureApplication();

        $this->get('/setup')->assertNotFound();
        $this->get('/setup/requirements')->assertNotFound();
        $this->post('/setup/reset', ['confirm' => '1'])->assertNotFound();
    }

    #[Test]
    public function the_installer_is_closed_to_a_signed_in_editor(): void
    {
        $this->configureApplication();

        $editor = Admin::query()->where('email', 'owner@example.com')->firstOrFail();
        $editor->update(['role' => AdminRole::Editor]);

        $this->actingAs($editor, 'admin')->get('/setup')->assertNotFound();
    }

    #[Test]
    public function an_administrator_can_re_open_the_installer(): void
    {
        $this->configureApplication();

        $admin = Admin::query()->where('email', 'owner@example.com')->firstOrFail();

        $this->actingAs($admin, 'admin')->get('/setup')->assertRedirect('/setup/complete');
        $this->actingAs($admin, 'admin')->get('/setup/complete')->assertOk();
    }

    #[Test]
    public function re_running_setup_removes_the_state_file_and_nothing_else(): void
    {
        $this->configureApplication();

        $admin = Admin::query()->where('email', 'owner@example.com')->firstOrFail();
        DB::table('users')->insert(['chat_id' => 'keep-me']);

        $this->actingAs($admin, 'admin')->post('/setup/reset', ['confirm' => '1'])
            ->assertRedirect('/setup');

        $this->assertFileDoesNotExist($this->stateFile);
        $this->assertSame(1, DB::table('users')->where('chat_id', 'keep-me')->count());
        $this->assertSame(1, Admin::query()->where('email', 'owner@example.com')->count());
    }

    #[Test]
    public function a_reset_without_confirmation_does_nothing(): void
    {
        $this->configureApplication();

        $admin = Admin::query()->where('email', 'owner@example.com')->firstOrFail();
        app(SetupStateStore::class)->markCompleted();

        $this->actingAs($admin, 'admin')->post('/setup/reset')
            ->assertSessionHasErrors('reset');

        $this->assertFileExists($this->stateFile);
    }

    // -----------------------------------------------------------------
    // Existing installations
    // -----------------------------------------------------------------

    #[Test]
    public function an_existing_database_is_recognised_instead_of_being_reinstalled(): void
    {
        // Data from a previous install, no wizard state file, no bot secrets: the
        // legacy case of pointing the new code at a live database.
        $this->configureApplication(withoutBotToken: true);

        $this->assertFileDoesNotExist($this->stateFile);

        $service = app(InstallationService::class);

        // No telegram token, so still not installed, but the schema and admin
        // checks pass: the wizard knows there is nothing to create.
        $failures = $service->failures();

        $this->assertNotContains('database.schema', $failures);
        $this->assertNotContains('admin.account', $failures);
        $this->assertContains('telegram.token', $failures);
    }

    #[Test]
    public function the_environment_writer_never_loses_a_credential_with_punctuation(): void
    {
        $writer = new EnvWriter;

        $password = 'p@ss word#with"quotes\\and$dollar';

        $writer->set(['DB_PASSWORD' => $password])->apply();

        // The round trip is what matters: a password containing a space, a hash,
        // a quote, a backslash or a dollar must come back byte for byte, or the
        // application would come up on a different password than the operator
        // typed and every login would fail.
        $this->assertSame($password, $writer->get('DB_PASSWORD'));
        $this->assertStringContainsString('DB_PASSWORD="', (string) file_get_contents($this->envFile));
    }

    #[Test]
    public function the_environment_writer_refuses_a_key_that_is_not_an_env_name(): void
    {
        $this->expectException(\RuntimeException::class);

        (new EnvWriter)->set(['BAD KEY' => 'value']);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * The state of a deployment that has credentials, schema and an admin: the
     * point every wizard test starts from.
     */
    private function configureApplication(bool $withoutAdmin = false, bool $withoutBotToken = false): void
    {
        config([
            'connectix_bot.telegram.token' => $withoutBotToken ? null : '123456:configured-telegram-token',
            'connectix_bot.telegram.webhook_secret' => str_repeat('c', 64),
            'connectix_bot.telegram.webhook_url' => 'https://example.com/telegram/webhook',
            'connectix_bot.connectix.token' => 'panel-token',
        ]);

        if ($withoutAdmin) {
            Admin::query()->delete();

            return;
        }

        Admin::query()->updateOrCreate(
            ['email' => 'owner@example.com'],
            [
                'password' => 'a-strong-password',
                'token' => 'panel-token',
                'chat_id' => '123456789',
                'role' => AdminRole::Admin->value,
            ],
        );
    }

    private function fakeTelegram(): void
    {
        Http::fake([
            'https://api.telegram.org/*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 1, 'username' => 'acme_bot'],
            ], 200),
            'https://api.telegram.org/*/getWebhookInfo' => Http::response([
                'ok' => true,
                'result' => ['url' => 'https://example.com/telegram/webhook', 'status' => 'registered'],
            ], 200),
            'https://api.telegram.org/*/setWebhook' => Http::response(['ok' => true, 'result' => true], 200),
        ]);
    }

    /**
     * Sign in as the administrator the wizard created.
     *
     * Once the application counts as installed the installer is admin only, so
     * every test that re-runs a step on a configured installation has to be that
     * administrator: that is the specification's own rule, not a test fixture.
     */
    private function actingAsOwner(): Admin
    {
        $admin = Admin::query()->where('email', 'owner@example.com')->firstOrFail();

        $this->actingAs($admin, 'admin');

        return $admin;
    }
}
