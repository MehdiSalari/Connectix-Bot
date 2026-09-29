<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The steps of the installation wizard, in the order legacy ran them.
 *
 * Legacy was one form and one script: `setup/index.php` collected the database,
 * panel, bot and admin fields, then `setup/setup.php` ran requirements, panel
 * login, `config.php`, the admin row, the webhook, the bot config fetch and
 * finally the client import, streaming progress into `setup_progress.php`.
 *
 * The order below is that same order, split so each part can be checked,
 * retried and reported on its own:
 *
 *   requirements -> database -> migrations -> connectix -> telegram -> webhook
 *   -> admin -> bot-config -> import -> complete
 *
 * Migrations and bot-config are separated from their neighbours on purpose: they
 * are the two steps that can fail on a database that is already in use, and
 * making them their own step is what lets the operator see which one failed
 * instead of watching a log scroll.
 */
enum SetupStep: string
{
    case Requirements = 'requirements';
    case Database = 'database';
    case Migrations = 'migrations';
    case Connectix = 'connectix';
    case Telegram = 'telegram';
    case Webhook = 'webhook';
    case Admin = 'admin';
    case BotConfig = 'bot-config';
    case Import = 'import';
    case Complete = 'complete';

    /**
     * @return array<int, self>
     */
    public static function ordered(): array
    {
        return self::cases();
    }

    public function label(): string
    {
        return match ($this) {
            self::Requirements => 'بررسی محیط',
            self::Database => 'دیتابیس',
            self::Migrations => 'جدول‌ها',
            self::Connectix => 'اتصال به Connectix',
            self::Telegram => 'ربات تلگرام',
            self::Webhook => 'Webhook',
            self::Admin => 'حساب ادمین',
            self::BotConfig => 'تنظیمات ربات',
            self::Import => 'انتقال اطلاعات',
            self::Complete => 'پایان نصب',
        };
    }

    public function title(): string
    {
        return 'مرحله '.$this->position().' از '.count(self::cases()).': '.$this->label();
    }

    public function position(): int
    {
        return (int) array_search($this, self::cases(), true) + 1;
    }

    /**
     * The checks that must pass before this step can be opened.
     *
     * An empty list means the step is always reachable, which is what the first
     * two steps need: the database step is how an application with no working
     * database becomes installed, so it cannot require a database check.
     *
     * @return array<int, string>
     */
    public function requires(bool $probe = false): array
    {
        return match ($this) {
            self::Requirements, self::Database => [],
            self::Migrations => ['database.connection'],
            self::Connectix => ['database.migrations'],
            self::Telegram => ['connectix.token'],
            self::Webhook => ['telegram.token'],
            self::Admin => ['telegram.webhook_url', 'telegram.webhook_secret'],
            self::BotConfig => ['admin.account'],
            self::Import => ['bot-config.ready'],
            self::Complete => ['admin.account', 'telegram.webhook_url', 'bot-config.ready'],
        };
    }

    /**
     * The checks that mean this step has been done.
     *
     * This is what the wizard uses to decide where to land, and it is the reason
     * an operator who points the new code at a live legacy database opens the
     * installer on the last step instead of being asked to create what is
     * already there.
     *
     * The import step is empty on purpose: importing is optional for a brand new
     * bot with no history, so it must never be the step the wizard waits on.
     *
     * @return array<int, string>
     */
    public function own(): array
    {
        return match ($this) {
            self::Requirements => ['application.php', 'application.extensions', 'application.storage', 'application.key'],
            self::Database => ['database.connection'],
            self::Migrations => ['database.migrations'],
            self::Connectix => ['connectix.token'],
            self::Telegram => ['telegram.token'],
            self::Webhook => ['telegram.webhook_url', 'telegram.webhook_secret'],
            self::Admin => ['admin.account'],
            self::BotConfig => ['bot-config.ready'],
            self::Import, self::Complete => [],
        };
    }

    /**
     * Whether the wizard can consider this step finished.
     *
     * @param  array<string, array{ok: bool}>  $checks
     */
    public function isDone(array $checks): bool
    {
        foreach (array_merge($this->requires(), $this->own()) as $key) {
            if (($checks[$key]['ok'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    public function next(): self
    {
        $cases = self::cases();
        $index = array_search($this, $cases, true);

        return $cases[min($index + 1, count($cases) - 1)];
    }

    public function previous(): ?self
    {
        $cases = self::cases();
        $index = array_search($this, $cases, true);

        return $index === 0 ? null : $cases[$index - 1];
    }
}
