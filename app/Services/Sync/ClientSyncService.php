<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Exceptions\ConnectixApiException;
use App\Models\Client;
use App\Models\User;
use App\Services\Connectix\ConnectixService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reconcile the local `users` and `clients` tables with the seller panel.
 *
 * Port of `update/clients_update.php`, which paginated `/v1/seller`, fetched
 * every client with `/v1/seller/clients/show` and upserted the two local rows
 * in one transaction each. The pagination, the per-client transaction and the
 * "skip a client whose detail cannot be read" rule are kept.
 *
 * Two legacy behaviours are deliberately not copied:
 *
 *  - legacy wrote `test = 1` onto every user it touched, because the page was
 *    a migration tool for a fresh install. On a live bot that would silently
 *    turn paying customers into test accounts, so an existing user's flag is
 *    left exactly as it is and only a brand new row gets the default;
 *  - legacy had no lock and no time limit, so a second browser tab (or the
 *    PHP timeout on shared hosting) left a half-imported table behind. This
 *    runs inside `Cache::lock()` and takes an explicit page budget, so the
 *    work can be resumed instead of repeated.
 */
class ClientSyncService
{
    public function __construct(
        private readonly ConnectixService $connectix,
    ) {}

    /**
     * @param  callable(string $line): void  $onLine  Progress reporter, one line per client.
     * @param  (callable(array{processed: int, users: int, clients: int, skipped: int, pages: int, total: int}): void)|null  $onPage  called after each page with the running stats
     * @return array{processed: int, users: int, clients: int, skipped: int, pages: int, total: int}
     */
    public function sync(callable $onLine, int $maxPages = 0, ?callable $onPage = null): array
    {
        $stats = ['processed' => 0, 'users' => 0, 'clients' => 0, 'skipped' => 0, 'pages' => 0, 'total' => 0];
        $previousIds = null;

        for ($page = 1; $maxPages < 1 || $page <= $maxPages; $page++) {
            $onLine("Fetching page {$page}");

            $payload = $this->connectix->listClients($page);
            $rows = $payload['clients']['data'] ?? null;

            if (! is_array($rows)) {
                $onLine('The panel returned an unexpected page structure.');

                break;
            }

            $stats['pages'] = $page;

            if ($stats['total'] === 0) {
                $stats['total'] = (int) ($payload['clients']['total'] ?? $payload['total_clients'] ?? 0);
            }

            if ($rows === []) {
                break;
            }

            $ids = array_map(static fn (mixed $row): string => (string) ($row['id'] ?? ''), $rows);

            if ($ids === $previousIds) {
                // The panel ignored the page number, so the same rows came back
                // and following it would loop forever. Legacy followed
                // `next_page_url` instead, which cannot repeat.
                $onLine('The panel repeated the previous page; stopping here.');

                break;
            }

            $previousIds = $ids;

            foreach ($rows as $row) {
                $clientId = (string) ($row['id'] ?? '');

                if ($clientId === '' || $clientId === '0') {
                    continue;
                }

                try {
                    $detail = $this->connectix->getClientData($clientId);
                } catch (ConnectixApiException $e) {
                    $stats['skipped']++;
                    $onLine("  ! {$clientId}: {$e->getMessage()}");

                    continue;
                }

                if ($detail === null) {
                    $stats['skipped']++;

                    continue;
                }

                $result = $this->store($detail);
                $stats['processed']++;
                $stats['users'] += $result['user'];
                $stats['clients'] += $result['client'];
            }

            $onLine(sprintf(
                '  %d clients done (%d users, %d clients, %d skipped)',
                $stats['processed'],
                $stats['users'],
                $stats['clients'],
                $stats['skipped'],
            ));

            if ($onPage !== null) {
                $onPage($stats);
            }
        }

        return $stats;
    }

    /**
     * Upsert the user and the client of one panel record.
     *
     * @param  array<string, mixed>  $detail
     * @return array{user: int, client: int} 1 for a created row, 0 for an updated one.
     */
    private function store(array $detail): array
    {
        $clientId = (string) ($detail['id'] ?? '');

        if ($clientId === '' || $clientId === '0') {
            return ['user' => 0, 'client' => 0];
        }

        return DB::transaction(function () use ($clientId, $detail): array {
            $user = $this->syncUser($detail);
            $client = $this->syncClient($clientId, $detail, $user['id']);

            return [
                'user' => $user['created'],
                'client' => $client,
            ];
        });
    }

    /**
     * A user row is only created when the client carries a chat id: without one
     * the person cannot be messaged, which is the only thing this table is for.
     *
     * @param  array<string, mixed>  $detail
     * @return array{id: int|null, created: int}
     */
    private function syncUser(array $detail): array
    {
        $chatId = $this->chatId($detail);

        if ($chatId === null) {
            return ['id' => null, 'created' => 0];
        }

        $values = [
            'telegram_id' => $this->stringOrNull($detail['telegram_id'] ?? null),
            'name' => $this->stringOrNull($detail['name'] ?? null),
            'email' => $this->stringOrNull($detail['email'] ?? null),
            'phone' => $this->stringOrNull($detail['phone'] ?? null),
        ];

        $user = User::query()->where('chat_id', $chatId)->first();

        if ($user !== null) {
            // `test` is intentionally absent: it is a local decision, and
            // legacy overwriting it broke paying customers on a live bot.
            $user->forceFill($values)->save();

            return ['id' => (int) $user->id, 'created' => 0];
        }

        $created = User::query()->create([
            'chat_id' => $chatId,
            'telegram_id' => $values['telegram_id'],
            'name' => $values['name'],
            'email' => $values['email'],
            'phone' => $values['phone'],
            'test' => false,
            'created_at' => now(),
        ]);

        return ['id' => (int) $created->id, 'created' => 1];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return int 1 when the row was created, 0 when an existing one was updated.
     */
    private function syncClient(string $clientId, array $detail, ?int $userId): int
    {
        $client = Client::query()->find($clientId);
        $isNew = $client === null;

        if ($client === null) {
            $client = new Client;
            $client->id = $clientId;
        }

        // A payload without a plan list says nothing about the status, so the
        // value already on the row is kept instead of being cleared.
        $planStatus = array_key_exists('plans', $detail) && is_array($detail['plans'])
            ? Client::planStatusFromPlans($detail['plans'])
            : $client->plan_status;

        $client->forceFill([
            'count_of_devices' => (int) ($detail['count_of_devices'] ?? 0),
            'username' => (string) ($detail['username'] ?? ''),
            'password' => (string) ($detail['password'] ?? ''),
            'expire_date' => $this->expireDate($detail),
            'plan_status' => $planStatus,
            'chat_id' => $this->chatId($detail) ?? '',
            'user_id' => $userId,
            'created_at' => $client->created_at ?? now(),
        ])->save();

        if ($isNew) {
            Log::info('Client sync created a client row.', ['client_id' => $clientId]);
        }

        return $isNew ? 1 : 0;
    }

    /**
     * The expiry arrives as a Jalali timestamp, and sometimes as the literal
     * string "null". Anything unusable becomes an empty value so the profile
     * list shows نامشخص instead of a wrong date.
     */
    private function expireDate(array $detail): ?string
    {
        $expireDate = $detail['expire_date'] ?? null;

        if (! is_string($expireDate)) {
            return null;
        }

        $expireDate = trim($expireDate);

        return ($expireDate === '' || strtolower($expireDate) === 'null') ? null : $expireDate;
    }

    /**
     * The panel writes chat ids as a number, a string or the literal "null".
     */
    private function chatId(array $detail): ?string
    {
        $chatId = $detail['chat_id'] ?? null;

        if ($chatId === null || is_array($chatId) || is_bool($chatId)) {
            return null;
        }

        $chatId = trim((string) $chatId);

        if ($chatId === '' || strtolower($chatId) === 'null') {
            return null;
        }

        return $chatId;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || strtolower($value) === 'null' ? null : $value;
    }
}
