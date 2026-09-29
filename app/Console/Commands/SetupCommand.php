<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SetupStep;
use App\Services\Setup\InstallationService;
use App\Services\Setup\SetupStateStore;
use App\Services\Setup\SetupWizard;
use App\Services\Telegram\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * The installer from a terminal, for the operators who have SSH.
 *
 * The web wizard is the primary path because most shared hosts have no shell at
 * all, but it is not always the best one: an installation over SSH does not need
 * a browser, and `connectix:setup status` is the command to paste into a support
 * ticket. The steps are the same code the wizard uses, so the two cannot drift.
 */
class SetupCommand extends Command
{
    protected $signature = 'connectix:setup
                            {action=status : status, requirements, migrate, webhook, complete or reset}
                            {--url= : The webhook URL to register, defaults to TELEGRAM_WEBHOOK_URL}';

    protected $description = 'Inspect and complete the installation without the web wizard';

    public function handle(
        InstallationService $installation,
        SetupWizard $wizard,
        SetupStateStore $store,
        TelegramService $telegram,
    ): int {
        $action = (string) $this->argument('action');

        return match ($action) {
            'status' => $this->status($installation, $wizard),
            'requirements' => $this->requirements($installation),
            'migrate' => $this->migrate(),
            'webhook' => $this->webhook($telegram),
            'complete' => $this->markComplete($installation, $store),
            'reset' => $this->reset($store),
            default => $this->unknown($action),
        };
    }

    private function status(InstallationService $installation, SetupWizard $wizard): int
    {
        $checks = $installation->checks(probe: true);
        $failures = $installation->failures(probe: true);
        $progress = $wizard->progress(probe: true);

        $this->newLine();
        $this->line('  <options=bold>Installation Check</>');
        $this->newLine();

        foreach ($checks as $check) {
            $mark = $check['ok'] ? '<fg=green>[OK]</>' : ($check['critical'] ? '<fg=red>[!!]</>' : '<fg=yellow>[--]</>');

            $this->line(sprintf('  %-9s %s', $mark, $check['label']));
            $this->line(sprintf('            %s', $check['message']));
        }

        $this->newLine();
        $this->line('  State:    '.$progress['state']->label());
        $this->line('  Next step: '.$progress['step']->title());
        $this->line('  Progress:  '.$progress['completed'].'/'.$progress['total'].' ('.$progress['percent'].'%)');
        $this->newLine();

        if ($failures !== []) {
            $this->error('Setup is incomplete. Start at: php artisan connectix:setup requirements');

            return self::FAILURE;
        }

        $this->info('The application is configured and can be used.');

        return self::SUCCESS;
    }

    private function requirements(InstallationService $installation): int
    {
        $environment = array_filter(
            $installation->checks(),
            static fn (array $check): bool => str_starts_with($check['key'], 'application.'),
        );

        $failed = 0;

        foreach ($environment as $check) {
            $check['ok']
                ? $this->line(sprintf('  <fg=green>[OK]</> %s: %s', $check['label'], $check['message']))
                : $this->error(sprintf('  [!!] %s: %s', $check['label'], $check['message']));
            $failed += $check['ok'] ? 0 : 1;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * `migrate --force` only. There is deliberately no flag here that can drop a
     * table: the database this runs against is a production one more often than not.
     */
    private function migrate(): int
    {
        $exit = Artisan::call('migrate', ['--force' => true], $output = new BufferedOutput);

        $this->line(trim($output->fetch()));

        return $exit === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function webhook(TelegramService $telegram): int
    {
        $url = (string) ($this->option('url') ?: config('connectix_bot.telegram.webhook_url'));
        $secret = (string) config('connectix_bot.telegram.webhook_secret');

        if ($url === '') {
            $this->error('No webhook URL. Pass --url or set TELEGRAM_WEBHOOK_URL.');

            return self::FAILURE;
        }

        try {
            if ($this->option('url') !== null) {
                $telegram->setWebhook($url, array_filter(['secret_token' => $secret]));
            }

            $info = $telegram->getWebhookInfo() ?? [];
        } catch (Throwable $e) {
            $this->error('Telegram did not answer: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('  URL:    '.$info['url'] ?? '—');
        $this->line('  Status: '.$info['status'] ?? '—');

        $matches = rtrim((string) ($info['url'] ?? ''), '/') === rtrim($url, '/');

        if (! $matches) {
            $this->error('Telegram has a different URL registered. Pass --url to replace it.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function markComplete(InstallationService $installation, SetupStateStore $store): int
    {
        $failures = $installation->failures(probe: true);

        if ($failures !== []) {
            $store->markFailed(SetupStep::Complete->value, implode(',', $failures));

            $this->error('Setup is not complete. Failing checks: '.implode(', ', $failures));

            return self::FAILURE;
        }

        $installation->markCompleted();

        $this->info('Setup marked as complete at '.now()->toDateTimeString().'.');

        return self::SUCCESS;
    }

    /**
     * Forget the wizard's progress. Nothing else is touched: no table, no admin,
     * no .env value. The next `status` re-derives the real state from the live
     * checks, which is what makes a re-run safe.
     */
    private function reset(SetupStateStore $store): int
    {
        $store->reset();

        $this->info('The setup state file was removed. No data was deleted.');

        return self::SUCCESS;
    }

    private function unknown(string $action): int
    {
        $this->error('Unknown action: '.$action);
        $this->line('  Available: status, requirements, migrate, webhook, complete, reset');

        return self::FAILURE;
    }
}
