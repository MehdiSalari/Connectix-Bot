<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `.env.example` is the contract a production deploy starts from: the keys
 * the first-run wizard writes, and the ones an operator has to fill in by
 * hand, must all be documented there before anyone copies the file.
 */
class EnvExampleTest extends TestCase
{
    /**
     * Every key `SetupWizardController` and `App\Services\Setup` ever write
     * into `.env`.
     *
     * @var list<string>
     */
    private const WIZARD_KEYS = [
        'APP_KEY',
        'DB_CONNECTION',
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
        'TELEGRAM_BOT_TOKEN',
        'TELEGRAM_WEBHOOK_URL',
        'TELEGRAM_WEBHOOK_SECRET',
        'CONNECTIX_PANEL_TOKEN',
        'CONNECTIX_API_BASE_URL',
        'CONNECTIX_BOT_APP_NAME',
        'CONNECTIX_BOT_SUPPORT_TELEGRAM',
        'CONNECTIX_BOT_CHANNEL_TELEGRAM',
        'CONNECTIX_BOT_CARD_NUMBER',
        'CONNECTIX_BOT_CARD_NAME',
        'CONNECTIX_BOT_TEST_ENABLED',
        'CONNECTIX_BOT_ACTIVE',
        'CONNECTIX_BOT_FORCE_CHANNEL_JOIN',
        'CONNECTIX_BOT_ADMIN_IDS',
        'LEGACY_DB_HOST',
        'LEGACY_DB_PORT',
        'LEGACY_DB_DATABASE',
        'LEGACY_DB_USERNAME',
        'LEGACY_DB_PASSWORD',
    ];

    /**
     * The knobs a deploy has to get right without a wizard step for them.
     *
     * @var list<string>
     */
    private const DEPLOY_KEYS = [
        'APP_ENV',
        'APP_DEBUG',
        'APP_URL',
        'LOG_CHANNEL',
        'LOG_LEVEL',
        'CACHE_STORE',
        'QUEUE_CONNECTION',
        'SESSION_SAME_SITE',
        'BANK_SMS_SECRET',
    ];

    /**
     * @return list<string>
     */
    private function exampleKeys(): array
    {
        $path = dirname(__DIR__, 2).'/.env.example';

        $this->assertFileExists($path, 'the deploy starts from .env.example');

        $keys = [];

        foreach (file($path) as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=/', $line, $matches) === 1) {
                $keys[] = $matches[1];
            }
        }

        return $keys;
    }

    public function test_it_documents_every_key_the_setup_wizard_writes(): void
    {
        $keys = $this->exampleKeys();

        foreach (self::WIZARD_KEYS as $key) {
            $this->assertContains($key, $keys, "{$key} is written by the wizard but missing from .env.example");
        }
    }

    public function test_it_documents_the_keys_a_hand_deploy_needs(): void
    {
        $keys = $this->exampleKeys();

        foreach (self::DEPLOY_KEYS as $key) {
            $this->assertContains($key, $keys, "{$key} decides production behaviour and must be documented");
        }
    }

    public function test_it_ships_with_production_safe_defaults(): void
    {
        $contents = (string) file_get_contents(dirname(__DIR__, 2).'/.env.example');

        $this->assertStringContainsString('APP_ENV=production', $contents);
        $this->assertStringContainsString('APP_DEBUG=false', $contents);
        $this->assertMatchesRegularExpression('/^QUEUE_CONNECTION=sync$/m', $contents, 'shared hosting has no queue worker');
        $this->assertMatchesRegularExpression('/^CACHE_STORE=database$/m', $contents, 'the scheduler locks live in the cache tables');
        $this->assertMatchesRegularExpression('/^# SESSION_SECURE_COOKIE=true$/m', $contents, 'secure cookies stay opt-in for plain HTTP hosts');
    }
}
