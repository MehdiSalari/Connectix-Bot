<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Services\Panel\PanelSettingsService;

/**
 * Membership test for bot administrators.
 *
 * Port of the admin array check that legacy performed against the raw update.
 * The legacy application compared the chat id, so the comparison happens on the
 * string form of the user id to stay identical.
 *
 * The id list is supplied rather than read from configuration so the guard
 * stays trivially testable; use {@see fromSettings()} to build it from the
 * local override or, failing that, the seller panel.
 */
class AdminGuard
{
    /**
     * @param  array<int, string>  $ids
     */
    public function __construct(private readonly array $ids) {}

    /**
     * Build the guard from local configuration only.
     */
    public static function fromConfig(): self
    {
        /** @var array<int, string> $ids */
        $ids = (array) config('connectix_bot.admin_ids', []);

        return new self(array_map(strval(...), $ids));
    }

    /**
     * Build the guard from the resolved settings, which fall back to the
     * administrators configured in the seller panel.
     */
    public static function fromSettings(PanelSettingsService $settings): self
    {
        return new self($settings->adminIds());
    }

    public function isAdmin(int|string|null $userId): bool
    {
        if ($userId === null || $this->ids === []) {
            return false;
        }

        return in_array((string) $userId, $this->ids, true);
    }
}
