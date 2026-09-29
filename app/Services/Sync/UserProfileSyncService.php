<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\User;
use App\Services\Telegram\TelegramProfileService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Refresh the cached Telegram profile (name and avatar) of every known user.
 *
 * Port of `update/users.php`, which looped over `users.telegram_id` and wrote
 * `name` and `avatar` from a `t.me` scrape. Legacy ran that loop with no pacing
 * and no resume, so an install with thousands of users was rate-limited by
 * t.me and then killed by `max_execution_time`, leaving the rest stale.
 *
 * Here every fetch is bounded by a per-run budget and a pause, the last user id
 * reached is remembered so the next run continues instead of restarting, and a
 * failed fetch only skips that one row.
 */
class UserProfileSyncService
{
    public function __construct(
        private readonly TelegramProfileService $profiles,
    ) {}

    /**
     * @param  callable(string $line): void  $onLine
     * @return array{checked: int, updated: int, skipped: int, last_id: int}
     */
    public function sync(callable $onLine, int $limit = 200, int $fromId = 0, int $delayMs = 700): array
    {
        $stats = ['checked' => 0, 'updated' => 0, 'skipped' => 0, 'last_id' => $fromId];

        $users = User::query()
            ->whereNotNull('telegram_id')
            ->where('telegram_id', '!=', '')
            ->where('id', '>', $fromId)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        if ($users->isEmpty()) {
            $onLine('Nothing to refresh.');

            return $stats;
        }

        foreach ($users as $user) {
            $stats['checked']++;
            $username = $this->username($user->telegram_id);

            if ($username === null) {
                $stats['skipped']++;
                $onLine("  ! user {$user->id}: unusable telegram id");

                continue;
            }

            try {
                $profile = $this->profiles->fetch($username);
            } catch (Throwable $e) {
                $stats['skipped']++;
                $onLine("  ! user {$user->id}: {$e->getMessage()}");

                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }

                continue;
            }

            // `fetch()` never throws: it reports a failure in the result.
            if (! $profile['exists']) {
                $stats['skipped']++;

                if ($profile['error'] !== null) {
                    $onLine("  ! user {$user->id} (@{$username}): {$profile['error']}");
                }

                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }

                continue;
            }

            $user->forceFill(array_filter([
                'name' => $profile['display_name'],
                'avatar' => $profile['avatar'],
            ], fn (mixed $value): bool => $value !== null && $value !== ''))->save();

            $stats['updated']++;
            $onLine("  + user {$user->id} (@{$username})");

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $onLine(sprintf(
            '%d checked, %d updated, %d skipped',
            $stats['checked'],
            $stats['updated'],
            $stats['skipped'],
        ));

        $stats['last_id'] = (int) $users->last()->id;

        return $stats;
    }

    /**
     * t.me addresses are plain usernames; legacy normalised the stored telegram
     * id with the same rules before scraping.
     */
    private function username(mixed $telegramId): ?string
    {
        $username = strtolower(trim((string) $telegramId));
        $username = ltrim($username, '@');
        $username = preg_replace('/[^a-z0-9_]/', '', $username) ?? '';

        if ($username === '') {
            Log::warning('A user row holds an unusable telegram id.', ['telegram_id' => $telegramId]);

            return null;
        }

        return $username;
    }
}
