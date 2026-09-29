<?php

declare(strict_types=1);

namespace App\Services\Setup;

use App\Enums\SetupState;
use App\Enums\SetupStep;

/**
 * Where the wizard is, and which step it should show next.
 *
 * The progression is derived from the live checks rather than from the state
 * file, which means an operator who already has a working database, a token and
 * an admin - the normal case for someone replacing the legacy files in place -
 * opens the wizard on the final step instead of being asked for everything
 * again. Re-running a step is always allowed; it is idempotent, and that is what
 * makes the "re-run setup" requirement safe.
 */
class SetupWizard
{
    public function __construct(
        private readonly InstallationService $installation,
        private readonly SetupStateStore $store,
    ) {}

    /**
     * The first step that still has something to do.
     */
    public function next(bool $probe = false): SetupStep
    {
        $checks = $this->installation->critical($probe);

        foreach (SetupStep::ordered() as $step) {
            if (! $step->isDone($checks)) {
                return $step;
            }
        }

        return SetupStep::Complete;
    }

    /**
     * Whether a step may be opened.
     *
     * Used to keep the operator from jumping ahead into a step whose input is not
     * there yet - creating the admin row needs the panel token, the bot needs the
     * panel - and it is why the wizard always lands on a step that can actually
     * be completed.
     */
    public function canOpen(SetupStep $step, bool $probe = false): bool
    {
        if ($step === SetupStep::Requirements || $step === SetupStep::Database) {
            return true;
        }

        $checks = $this->installation->critical($probe);

        foreach ($step->requires($probe) as $key) {
            if (($checks[$key]['ok'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{completed: int, total: int, percent: int, step: SetupStep, state: SetupState}
     */
    public function progress(bool $probe = false): array
    {
        $checks = $this->installation->critical($probe);
        $next = $this->next($probe);
        $completed = $next->position() - 1;

        return [
            'completed' => $completed,
            'total' => count(SetupStep::cases()),
            'percent' => (int) round($completed / count(SetupStep::cases()) * 100),
            'step' => $next,
            'state' => $this->installation->state($probe),
        ];
    }

    /**
     * @return array<int, SetupStep>
     */
    public function steps(): array
    {
        return SetupStep::ordered();
    }

    public function state(): SetupState
    {
        return $this->store->state();
    }
}
