<?php

declare(strict_types=1);

namespace App\Services\Setup;

use App\Enums\SetupState;
use RuntimeException;

/**
 * The installer's own record of its progress.
 *
 * Legacy wrote `setup/setup_progress.json` for the progress bar and created
 * `config.php` as the "this is installed" marker. This is the equivalent of the
 * first: one small file in `storage/app/connectix/` holding the state, the steps
 * that ran and when. It never holds a credential, so it is safe in a backup and
 * safe to write on a host where the document root is the only writable place.
 *
 * Deleting the file is the documented way to re-run the wizard; it removes no
 * data, and `InstallationService` still re-derives the real state from live
 * checks.
 */
class SetupStateStore
{
    /**
     * @return array{state: string, updated_at: ?string, completed_at: ?string, step: ?string, error: ?string}
     */
    public function read(): array
    {
        $defaults = [
            'state' => SetupState::NotInstalled->value,
            'updated_at' => null,
            'completed_at' => null,
            'step' => null,
            'error' => null,
        ];

        $path = $this->path();

        if (! is_file($path)) {
            return $defaults;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return $defaults;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return $defaults;
        }

        return array_merge($defaults, array_intersect_key($decoded, $defaults));
    }

    public function state(): SetupState
    {
        return SetupState::tryFrom((string) $this->read()['state']) ?? SetupState::NotInstalled;
    }

    public function startedAt(): ?string
    {
        return $this->read()['updated_at'];
    }

    public function completedAt(): ?string
    {
        return $this->read()['completed_at'];
    }

    public function markStarted(string $step): void
    {
        $this->write([
            'state' => SetupState::Configuring->value,
            'step' => $step,
            'error' => null,
        ]);
    }

    public function markFailed(string $step, string $error): void
    {
        // The message is written as given; callers must pass something that is
        // already safe to display, which is why no exception message with a
        // credential in it is ever passed here.
        $this->write([
            'state' => SetupState::Failed->value,
            'step' => $step,
            'error' => mb_substr($error, 0, 300),
        ]);
    }

    public function markCompleted(): void
    {
        $this->write([
            'state' => SetupState::Configured->value,
            'step' => null,
            'error' => null,
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Forget the wizard's progress. No data is touched: this is what "re-run
     * setup" means, and it is the only thing the reset button does.
     */
    public function reset(): void
    {
        $path = $this->path();

        if (is_file($path) && ! @unlink($path)) {
            throw new RuntimeException('The setup state file could not be removed.');
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function write(array $changes): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('The setup directory could not be created: '.$directory);
        }

        if (! is_writable($directory)) {
            throw new RuntimeException('The setup directory is not writable: '.$directory);
        }

        $data = array_merge($this->read(), $changes, ['updated_at' => now()->toIso8601String()]);

        if (file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            throw new RuntimeException('The setup state file could not be written: '.$path);
        }
    }

    private function path(): string
    {
        $path = (string) config('setup.state_file');

        if ($path === '') {
            throw new RuntimeException('setup.state_file is not configured.');
        }

        return $path;
    }
}
