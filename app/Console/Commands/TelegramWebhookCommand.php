<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\TelegramApiException;
use App\Services\Telegram\TelegramService;
use Illuminate\Console\Command;

/**
 * Manage the Telegram webhook from the command line.
 *
 * The legacy application had no tooling for this, so the webhook had to be
 * configured by hand and silently fell back to long polling. This command
 * covers the three operations needed on a shared host: register, inspect and
 * remove.
 */
class TelegramWebhookCommand extends Command
{
    protected $signature = 'telegram:webhook
                            {action : set, info or remove}
                            {--allowed-updates=* : Restrict the update types Telegram delivers}';

    protected $description = 'Register, inspect or remove the Telegram webhook';

    public function handle(TelegramService $telegram): int
    {
        $action = (string) $this->argument('action');

        try {
            return match ($action) {
                'set' => $this->set($telegram),
                'info' => $this->showInfo($telegram),
                'remove' => $this->remove($telegram),
                default => $this->unknown($action),
            };
        } catch (TelegramApiException $e) {
            $this->error('Telegram rejected the request: '.$e->description());

            return self::FAILURE;
        }
    }

    private function set(TelegramService $telegram): int
    {
        $url = (string) config('echovpn.telegram.webhook_url');
        $secret = (string) config('echovpn.telegram.webhook_secret');

        if ($url === '') {
            $this->error('TELEGRAM_WEBHOOK_URL is not set in the environment.');

            return self::FAILURE;
        }

        if ($secret === '') {
            $this->error('TELEGRAM_WEBHOOK_SECRET is not set. The endpoint would accept forged updates.');

            return self::FAILURE;
        }

        $options = [
            'secret_token' => $secret,
            'max_connections' => 40,
            'allowed_updates' => array_values($this->option('allowed-updates')) ?: [
                'message',
                'edited_message',
                'callback_query',
            ],
        ];

        $telegram->setWebhook($url, $options);

        $this->info('Webhook registered at '.$url);

        return self::SUCCESS;
    }

    private function showInfo(TelegramService $telegram): int
    {
        $info = $telegram->getWebhookInfo();

        if ($info === null) {
            $this->warn('Telegram returned no webhook information.');

            return self::FAILURE;
        }

        foreach (['url', 'status', 'pending_update_count', 'last_error_message', 'last_error_date', 'ip_address', 'allowed_updates'] as $key) {
            if (array_key_exists($key, $info)) {
                $value = is_array($info[$key])
                    ? implode(', ', $info[$key])
                    : (string) $info[$key];

                $this->line(str_pad($key, 22).': '.($value === '' ? '-' : $value));
            }
        }

        return self::SUCCESS;
    }

    private function remove(TelegramService $telegram): int
    {
        $telegram->deleteWebhook();

        $this->info('Webhook removed. Telegram will fall back to getUpdates.');

        return self::SUCCESS;
    }

    private function unknown(string $action): int
    {
        $this->error("Unknown action [{$action}]. Expected set, info or remove.");

        return self::FAILURE;
    }
}
