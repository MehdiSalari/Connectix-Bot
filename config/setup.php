<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Installation state
    |--------------------------------------------------------------------------
    |
    | Legacy detected "is this installed?" by the presence of `config.php` next
    | to the entry point. This is that file: a small JSON record of the wizard's
    | progress, kept in storage rather than in the document root so it is not
    | web readable. It records which steps ran and when, and never a secret.
    |
    | It is deliberately not the only criterion for "installed": the live checks
    | in App\Services\Setup\InstallationService decide that, so a deleted state
    | file cannot lock a working installation out of its own panel.
    |
    */

    'state_file' => env('CONNECTIX_SETUP_STATE_FILE', storage_path('app/connectix/setup.json')),

    /*
    | The environment file the wizard writes credentials into. Overridable only
    | so a test can point it at a temporary file; production always uses the
    | project root `.env`.
    */
    'env_file' => env('CONNECTIX_SETUP_ENV_FILE', base_path('.env')),

    /*
    | Where the generated encryption key is cached when `.env` cannot be written,
    | which is the normal case on a shared host where only `storage/` belongs to
    | the site owner. Outside the document root, written 0600, and only read when
    | `.env` has no `APP_KEY` of its own.
    */

    'key_file' => env('CONNECTIX_SETUP_KEY_FILE', storage_path('app/connectix/app.key')),

    /*
    |--------------------------------------------------------------------------
    | Wizard lock
    |--------------------------------------------------------------------------
    |
    | Two people opening the installer at the same time on a shared host would
    | otherwise both write `.env` and both run the migrations.
    |
    */

    'lock' => 'connectix:setup',

    /*
    |--------------------------------------------------------------------------
    | Import driver
    |--------------------------------------------------------------------------
    |
    | Where the wizard's data import actually runs.
    |
    |  auto     - out-of-process on the built-in dev server (it answers one
    |             request at a time, so an inline import would starve the
    |             progress polls for the whole run), inline everywhere else.
    |  process  - always spawn `php artisan setup:import`.
    |  inline   - always run inside the POST request (needs a server that
    |             answers requests in parallel: Apache, FPM).
    |
    | The test suite runs on the CLI SAPI and never sets this, so `auto`
    | always resolves to inline there.
    |
    */

    'import_driver' => env('CONNECTIX_IMPORT_DRIVER', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Environment requirements
    |--------------------------------------------------------------------------
    */

    'min_php' => '8.3',

    'extensions' => ['curl', 'json', 'mbstring', 'openssl', 'pdo', 'fileinfo', 'tokenizer'],

    /*
    |--------------------------------------------------------------------------
    | Environment key to config path
    |--------------------------------------------------------------------------
    |
    | Writing `.env` is not enough for the wizard to continue: PHP has already
    | read the file when the process booted, so a value written now is invisible
    | to `config()` until the next request. EnvWriter::apply() uses this map to
    | push each freshly written value into the running process, which is what
    | lets one request configure the database, connect to it and run the
    | migrations without the operator reloading the page mid-way.
    |
    | A key that is not listed here is still written to `.env`; it simply takes
    | effect on the next request.
    |
    */

    'config_map' => [
        'DB_CONNECTION' => 'database.default',
        'DB_HOST' => 'database.connections.mysql.host',
        'DB_PORT' => 'database.connections.mysql.port',
        'DB_DATABASE' => 'database.connections.mysql.database',
        'DB_USERNAME' => 'database.connections.mysql.username',
        'DB_PASSWORD' => 'database.connections.mysql.password',
        'APP_NAME' => 'app.name',
        'APP_KEY' => 'app.key',
        'APP_URL' => 'app.url',
        'TELEGRAM_BOT_TOKEN' => 'connectix_bot.telegram.token',
        'TELEGRAM_WEBHOOK_SECRET' => 'connectix_bot.telegram.webhook_secret',
        'TELEGRAM_WEBHOOK_URL' => 'connectix_bot.telegram.webhook_url',
        'CONNECTIX_PANEL_TOKEN' => 'connectix_bot.connectix.token',
        'CONNECTIX_API_BASE_URL' => 'connectix_bot.connectix.base_url',
        'CONNECTIX_BOT_APP_NAME' => 'connectix_bot.app_name',
        'CONNECTIX_BOT_SUPPORT_TELEGRAM' => 'connectix_bot.support_telegram',
        'CONNECTIX_BOT_CHANNEL_TELEGRAM' => 'connectix_bot.channel_telegram',
        'CONNECTIX_BOT_TELEGRAM_CHANNEL_ID' => 'connectix_bot.telegram_channel_id',
        'CONNECTIX_BOT_ADMIN_IDS' => 'connectix_bot.admin_ids',
        'CONNECTIX_BOT_CARD_NUMBER' => 'connectix_bot.card.number',
        'CONNECTIX_BOT_CARD_NAME' => 'connectix_bot.card.name',
        'CONNECTIX_BOT_BANK_NAME' => 'connectix_bot.bank.name',
        'CONNECTIX_BOT_ACTIVE' => 'connectix_bot.active',
        'CONNECTIX_BOT_TEST_ENABLED' => 'connectix_bot.test_enabled',
        'CONNECTIX_BOT_FORCE_CHANNEL_JOIN' => 'connectix_bot.force_channel_join',
        'LEGACY_DB_HOST' => 'database.connections.legacy.host',
        'LEGACY_DB_PORT' => 'database.connections.legacy.port',
        'LEGACY_DB_DATABASE' => 'database.connections.legacy.database',
        'LEGACY_DB_USERNAME' => 'database.connections.legacy.username',
        'LEGACY_DB_PASSWORD' => 'database.connections.legacy.password',
    ],

    /*
    | Directories the installer itself writes to, on top of the ones the
    | framework needs. Checked on the first step and on the final validation.
    */
    'writable' => [
        storage_path('app'),
        storage_path('framework'),
        storage_path('logs'),
        base_path('.env'),
    ],

    /*
    | Tables the application needs before it can serve a single request. These
    | are the legacy table names, so an install that is upgraded in place
    | already satisfies them.
    */
    'tables' => [
        'admins',
        'users',
        'wallets',
        'clients',
        'payments',
        'wallet_transactions',
        'sms_payments',
        'panel_settings',
        'telegram_updates',
    ],

    /*
    | The guard that keeps an uninstalled application out of the normal runtime
    | is on in production and off inside the test suite, where each test states
    | explicitly whether it wants the guard by setting `setup.force`. This keeps
    | the other suites independent of the installer.
    */
    'enforce' => (bool) env('CONNECTIX_SETUP_ENFORCE', true),

];
