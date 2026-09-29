<?php

declare(strict_types=1);

namespace App\Services\Telegram;

/**
 * Membership test for bot administrators.
 *
 * Port of the admin array check that legacy performed against the raw update.
 * The legacy application compared the chat id, so the comparison happens on the
 * string form of the user id to stay identical.
 */
class AdminGuard
{
    /**
     * @param  array<int, string>  $ids
     */
    public function __construct(private readonly array $ids)
    {
    }

    public static function fromConfig(): self
    {
        /** @var array<int, string> $ids */
        $ids = (array) config('echovpn.admin_ids', []);

        return new self(array_map(strval(...), $ids));
    }

    public function isAdmin(int|string|null $userId): bool
    {
        if ($userId === null || $this->ids === []) {
            return false;
        }

        return in_array((string) $userId, $this->ids, true);
    }
}
