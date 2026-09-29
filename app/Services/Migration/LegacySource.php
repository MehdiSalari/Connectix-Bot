<?php

declare(strict_types=1);

namespace App\Services\Migration;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Read-only access to the tables of the legacy installation.
 *
 * The rewrite kept the legacy table names and column sets (see
 * `0001_01_01_000000_create_legacy_schema_tables.php`), so an install that is
 * moved to the new code on the same database needs no import at all. What this
 * class serves is the other case: a fresh Laravel database that has to be
 * filled from an old one, which is what a reseller taking over a customer does.
 *
 * Nothing in the request path ever opens this connection.
 */
class LegacySource
{
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
    ) {}

    /**
     * The legacy connection, or a clear error explaining what is missing.
     */
    public function connection(): Connection
    {
        $config = config('database.connections.legacy');

        if (blank($config['database'] ?? null)) {
            throw new RuntimeException(
                'The legacy database is not configured. Set LEGACY_DB_DATABASE '
                .'(plus LEGACY_DB_USERNAME and LEGACY_DB_PASSWORD) in .env first.'
            );
        }

        if (! $this->configured()) {
            throw new RuntimeException(
                'The legacy database has no username. A server connection needs one.'
            );
        }

        return $this->connections->connection('legacy');
    }

    public function configured(): bool
    {
        $config = config('database.connections.legacy');

        if (blank($config['database'] ?? null)) {
            return false;
        }

        // A file export of the old database is imported as often as a live
        // server, and SQLite needs no credentials.
        if (($config['driver'] ?? null) === 'sqlite') {
            return true;
        }

        return filled($config['username'] ?? null);
    }

    /**
     * The tables this importer knows how to read, in dependency order.
     *
     * @return array<int, string>
     */
    public function tables(): array
    {
        return array_keys(LegacyImportService::TABLES);
    }

    public function hasTable(string $table): bool
    {
        return $this->connection()->getSchemaBuilder()->hasTable($table);
    }

    /**
     * How many rows a table holds, or null when the table does not exist.
     */
    public function count(string $table): ?int
    {
        if (! $this->hasTable($table)) {
            return null;
        }

        return (int) $this->connection()->table($table)->count();
    }

    /**
     * Read a table in chunks, ordered by its primary key.
     *
     * Chunking matters: a live legacy table with a hundred thousand payments
     * cannot be loaded into memory, and `orderBy` keeps the walk stable.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(string $table, string $orderBy = 'id', int $chunk = 500, int $offset = 0)
    {
        return $this->connection()
            ->table($table)
            ->orderBy($orderBy)
            ->offset($offset)
            ->limit($chunk)
            ->get();
    }

    /**
     * A test/verification connection is disposable, so tests can point it at a
     * throwaway SQLite file.
     */
    public function purge(): void
    {
        $this->connections->purge('legacy');
    }
}
