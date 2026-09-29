<?php

declare(strict_types=1);

namespace App\Telegram\Contracts;

use App\Models\User;
use App\Telegram\TelegramUpdate;

/**
 * One unit of Telegram update handling.
 *
 * Handlers are deliberately coarse: a command, a callback family or a media
 * flow. Anything a handler cannot serve returns false from `supports()` so the
 * next handler gets a chance, and the routers keep the ordering explicit.
 */
interface UpdateHandler
{
    /**
     * Whether this handler owns the given update.
     */
    public function supports(TelegramUpdate $update, User $user): bool;

    /**
     * Act on the update. Implementations may throw; the gateway contains the
     * failure so a single bad update cannot take the bot offline.
     */
    public function handle(TelegramUpdate $update, User $user): void;
}
