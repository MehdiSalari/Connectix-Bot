<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `legacy:import` and `legacy:verify`: the replacement for the data half of
 * `setup/setup.php`, usable on a database that is already live.
 *
 * The legacy installer created the schema itself and imported into it. The
 * rewrite keeps those table and column names, so an install that moves to the
 * new code on the same database needs nothing at all; these commands cover the
 * other case, a fresh Laravel database filled from an old one.
 */
class LegacyImportTest extends TestCase
{
    use RefreshDatabase;

    private string $legacyDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->legacyDatabase = storage_path('framework/testing/legacy-'.uniqid().'.sqlite');

        if (! is_dir(dirname($this->legacyDatabase))) {
            mkdir(dirname($this->legacyDatabase), 0777, true);
        }

        touch($this->legacyDatabase);

        config(['database.connections.legacy' => [
            'driver' => 'sqlite',
            'database' => $this->legacyDatabase,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        DB::purge('legacy');
    }

    protected function tearDown(): void
    {
        DB::purge('legacy');

        if (is_file($this->legacyDatabase)) {
            unlink($this->legacyDatabase);
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // legacy:import
    // -----------------------------------------------------------------

    #[Test]
    public function a_dry_run_reports_without_writing(): void
    {
        $this->seedLegacy();

        $this->artisan('legacy:import')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Client::query()->count());
    }

    #[Test]
    public function it_copies_every_known_table(): void
    {
        $this->seedLegacy();

        $this->artisan('legacy:import --confirm')->assertSuccessful();

        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, Client::query()->count());
        $this->assertSame(1, Wallet::query()->count());
        $this->assertSame(1, Payment::query()->count());

        $this->assertDatabaseHas('users', ['chat_id' => '553', 'name' => 'Buyer']);
        $this->assertDatabaseHas('clients', ['id' => 'abc', 'user_id' => User::query()->value('id')]);
        $this->assertDatabaseHas('wallets', ['chat_id' => '553', 'balance' => '25000']);
        $this->assertDatabaseHas('payments', ['order_number' => 'ORD-1', 'is_paid' => '1']);
    }

    #[Test]
    public function running_it_twice_changes_nothing_the_second_time(): void
    {
        $this->seedLegacy();

        $this->artisan('legacy:import --confirm')->assertSuccessful();
        $this->artisan('legacy:import --confirm')
            ->expectsOutputToContain('unchanged')
            ->assertSuccessful();

        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, Client::query()->count());
    }

    #[Test]
    public function it_can_import_a_single_table(): void
    {
        $this->seedLegacy();

        $this->artisan('legacy:import --only=users --confirm')->assertSuccessful();

        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, Client::query()->count());
    }

    #[Test]
    public function a_row_without_a_natural_key_is_reported_and_skipped(): void
    {
        $this->seedLegacy();

        // A user whose chat id is the literal string the panel writes for "none".
        DB::connection('legacy')->table('users')->insert([
            'id' => 2,
            'chat_id' => '',
            'name' => 'No chat',
        ]);

        $this->artisan('legacy:import --only=users --confirm')
            ->expectsOutputToContain('failed')
            ->assertSuccessful();

        $this->assertSame(1, User::query()->count());
    }

    #[Test]
    public function a_missing_legacy_table_is_reported_instead_of_crashing(): void
    {
        $this->seedLegacy();

        Schema::connection('legacy')->drop('payments');

        $this->artisan('legacy:import --only=payments --confirm')
            ->expectsOutputToContain('does not exist')
            ->assertSuccessful();
    }

    #[Test]
    public function it_refuses_to_run_without_a_configured_legacy_database(): void
    {
        config(['database.connections.legacy' => ['driver' => 'mysql', 'database' => null, 'username' => null]]);
        DB::purge('legacy');

        $this->artisan('legacy:import --confirm')
            ->expectsOutputToContain('not configured')
            ->assertFailed();
    }

    // -----------------------------------------------------------------
    // legacy:verify
    // -----------------------------------------------------------------

    #[Test]
    public function verify_fails_before_the_import_and_passes_after_it(): void
    {
        $this->seedLegacy();

        $this->artisan('legacy:verify')->assertFailed();

        $this->artisan('legacy:import --confirm')->assertSuccessful();

        $this->artisan('legacy:verify')
            ->expectsOutputToContain('Every legacy row is present locally.')
            ->assertSuccessful();
    }

    #[Test]
    public function verify_reports_a_row_that_is_still_missing(): void
    {
        $this->seedLegacy();

        $this->artisan('legacy:import --only=users --confirm')->assertSuccessful();

        $this->artisan('legacy:verify --only=clients')->assertFailed();
    }

    /**
     * A miniature copy of a legacy install, written with the same column names
     * the old installer used.
     */
    private function seedLegacy(): void
    {
        $schema = Schema::connection('legacy');

        $schema->create('users', function ($table): void {
            $table->increments('id');
            $table->string('chat_id', 255)->nullable();
            $table->string('telegram_id', 255)->nullable();
            $table->string('name', 255)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('avatar')->nullable();
            $table->text('action')->nullable();
            $table->boolean('test')->default(false);
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('clients', function ($table): void {
            $table->string('id', 100);
            $table->integer('count_of_devices')->nullable();
            $table->string('username', 255)->nullable();
            $table->string('password', 255)->nullable();
            $table->string('chat_id', 255)->nullable();
            $table->integer('user_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('wallets', function ($table): void {
            $table->increments('id');
            $table->string('chat_id', 255)->nullable();
            $table->string('balance', 255)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('payments', function ($table): void {
            $table->increments('id');
            $table->string('order_number', 20)->nullable();
            $table->string('chat_id', 255)->nullable();
            $table->string('client_id', 255)->nullable();
            $table->string('plan_id', 255)->nullable();
            $table->string('price', 255)->nullable();
            $table->string('coupon', 255)->nullable();
            $table->string('is_paid', 50)->nullable();
            $table->string('method', 255)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::connection('legacy')->table('users')->insert([
            'id' => 1,
            'chat_id' => '553',
            'telegram_id' => '@buyer',
            'name' => 'Buyer',
            'test' => 1,
        ]);

        DB::connection('legacy')->table('clients')->insert([
            'id' => 'abc',
            'count_of_devices' => 2,
            'username' => 'buyer',
            'password' => 'pass1',
            'chat_id' => '553',
            'user_id' => 1,
        ]);

        DB::connection('legacy')->table('wallets')->insert([
            'id' => 1,
            'chat_id' => '553',
            'balance' => '25000',
        ]);

        DB::connection('legacy')->table('payments')->insert([
            'id' => 1,
            'order_number' => 'ORD-1',
            'chat_id' => '553',
            'client_id' => 'abc',
            'plan_id' => 'plan-1',
            'price' => '150000',
            'is_paid' => '1',
            'method' => 'wallet',
        ]);
    }
}
