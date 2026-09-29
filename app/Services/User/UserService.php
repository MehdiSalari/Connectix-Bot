<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\Wallet;
use App\Models\User;
use App\Services\Telegram\TelegramProfileService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creation and refresh of Telegram users.
 *
 * Port of the legacy `getUser()` and `userInfo()`.
 */
class UserService
{
    public function __construct(
        private readonly TelegramProfileService $profiles,
        private readonly WalletService $wallets,
    ) {
    }

    /**
     * Find a user by chat id.
     *
     * Legacy `getUser()` returned `null` when no row matched.
     */
    public function findByChatId(string|int $chatId): ?User
    {
        return User::query()->where('chat_id', (string) $chatId)->first();
    }

    /**
     * Find a user, creating the row when it is the first time we see them.
     *
     * Legacy `userInfo()` upserted on chat_id and additionally guaranteed a
     * wallet row existed, which is why every new user starts with a zero
     * balance wallet.
     */
    public function findOrCreateByChatId(string|int $chatId): ?User
    {
        return User::query()->firstOrCreate(['chat_id' => (string) $chatId]);
    }

    /**
     * Refresh the profile of a user and make sure they own a wallet.
     *
     * Port of `userInfo()`. The display name and avatar are re-scraped from
     * the public Telegram page whenever a username is known, falling back to
     * the first name Telegram supplied.
     */
    public function refreshProfile(User $user, ?string $username, ?string $firstName): User
    {
        $avatar = null;
        $displayName = $firstName;

        if (! blank($username)) {
            $profile = $this->profiles->fetch($username);

            if (! empty($profile['avatar'])) {
                $avatar = $profile['avatar'];
            }

            if (! empty($profile['display_name'])) {
                $displayName = $profile['display_name'];
            }
        }

        $user->forceFill([
            'telegram_id' => $username,
            'name' => $displayName,
            'avatar' => $avatar,
        ])->save();

        $this->ensureWallet($user);

        return $user;
    }

    /**
     * Port of `userInfo()` as a whole: clear the conversation state, sync the
     * profile and guarantee a wallet.
     */
    public function sync(UserStateService $states, string|int $chatId, ?string $username, ?string $firstName): ?User
    {
        try {
            $user = $this->findOrCreateByChatId($chatId);

            if ($user === null) {
                return null;
            }

            $states->tryClear($user);

            return $this->refreshProfile($user, $username, $firstName);
        } catch (Throwable $e) {
            Log::error('Failed to synchronize Telegram user profile.', [
                'chat_id' => (string) $chatId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Create the zero balance wallet a user is entitled to, if missing.
     */
    public function ensureWallet(User $user): Wallet
    {
        $wallet = $this->wallets->find($user->chat_id);

        if ($wallet !== null) {
            return $wallet;
        }

        $created = $this->wallets->create((string) $user->chat_id, 0);

        if ($created === null) {
            Log::error('Failed to create wallet for user.', [
                'chat_id' => (string) $user->chat_id,
            ]);
        }

        return $created ?? $this->wallets->find((string) $user->chat_id) ?? new Wallet([
            'chat_id' => (string) $user->chat_id,
            'balance' => '0',
        ]);
    }

    /**
     * Display name used in receipts, matching the legacy fallback chain.
     */
    public function displayNameFor(User $user): string
    {
        if (! blank($user->name)) {
            return $user->name;
        }

        if (! blank($user->telegram_id)) {
            return '@'.$user->telegram_id;
        }

        return 'نامشخص';
    }
}
