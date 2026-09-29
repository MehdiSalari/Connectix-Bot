<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The state of an installation, as the wizard sees it.
 *
 * Legacy had no explicit state: it inferred "installed" from the presence of
 * `config.php`, and a half-finished install looked exactly like a working one
 * until something failed at runtime. The four states are the minimum needed to
 * tell those apart, which is what the specification asks for.
 */
enum SetupState: string
{
    /** Nothing has been configured yet. */
    case NotInstalled = 'not_installed';

    /** The wizard has been started and is somewhere in the middle. */
    case Configuring = 'configuring';

    /** Every critical check passes. */
    case Configured = 'configured';

    /** A step failed and the operator has to fix it before continuing. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::NotInstalled => 'نصب نشده',
            self::Configuring => 'در حال نصب',
            self::Configured => 'نصب شده',
            self::Failed => 'نصب ناموفق',
        };
    }
}
