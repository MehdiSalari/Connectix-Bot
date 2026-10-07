<?php

declare(strict_types=1);

namespace App\Services\Setup;

/**
 * Live counters for the setup import step.
 *
 * Mirrors the legacy setup/setup_progress.php hand-off: the import runs as one
 * long POST, a second GET reads this file while the POST is still going, and
 * the import step's script paints percent/phase/log from it. The file is the
 * only shared state, so it works wherever PHP serves requests in parallel
 * processes (FPM, Apache, the built-in dev server).
 */
final class ImportProgress
{
    private const FILE = 'connectix/import_progress.json';

    /**
     * Snapshot for the polling reader (log trimmed to the last 8 lines).
     *
     * @return array<string, mixed>
     */
    public static function read(): array
    {
        $state = self::raw();
        $state['active'] = $state['action'] !== null && $state['failed'] !== true && $state['done'] !== true;
        $lines = is_array($state['lines']) ? $state['lines'] : [];
        $state['lines'] = array_slice(array_map('strval', $lines), -8);

        return $state;
    }

    public static function start(string $action, string $phase): void
    {
        self::write([
            'action' => $action,
            'phase' => $phase,
            'percent' => 0,
            'processed' => null,
            'total' => null,
            'lines' => [],
            'done' => false,
            'failed' => false,
            'message' => null,
            'report' => null,
        ]);
    }

    public static function update(int $percent, string $phase, ?int $processed = null, ?int $total = null): void
    {
        $state = self::state();
        $state['percent'] = max(0, min(100, $percent));
        $state['phase'] = $phase;

        if ($processed !== null) {
            $state['processed'] = $processed;
        }

        if ($total !== null) {
            $state['total'] = $total;
        }

        self::write($state);
    }

    public static function line(string $line): void
    {
        $state = self::state();
        $state['lines'][] = rtrim($line);
        $state['lines'] = array_slice($state['lines'], -50);

        self::write($state);
    }

    /**
     * The import is over: mark it done and (optionally) park the closing
     * report here.
     *
     * The flash dies with the request that set it, and an out-of-process
     * import has no request of its own when it finishes — so the report
     * travels in this file, and the import step falls back to it when the
     * session has nothing left to show.
     *
     * @param  array<mixed>|null  $report
     */
    public static function finish(string $message, ?array $report = null): void
    {
        $state = self::state();
        $state['percent'] = 100;
        $state['done'] = true;
        $state['failed'] = false;
        $state['message'] = $message;
        $state['report'] = $report;

        self::write($state);
    }

    public static function fail(string $message): void
    {
        $state = self::state();
        $state['failed'] = true;
        $state['done'] = false;
        $state['message'] = $message;

        self::write($state);
    }

    /**
     * File contents with defaults filled in; empty state when absent/corrupt.
     *
     * @return array<string, mixed>
     */
    private static function raw(): array
    {
        $raw = @file_get_contents(self::path());
        $state = $raw !== false ? json_decode($raw, true) : null;

        if (! is_array($state)) {
            return self::defaults();
        }

        return array_merge(self::defaults(), $state);
    }

    /** Writer view: full state without the computed `active` flag. */
    private static function state(): array
    {
        $state = self::raw();
        unset($state['active']);

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function write(array $state): void
    {
        $state['updated_at'] = microtime(true);
        $path = self::path();

        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }

        // LOCK_EX: the writer updates while a reader polls it concurrently.
        @file_put_contents(
            $path,
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            LOCK_EX,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaults(): array
    {
        return [
            'action' => null,
            'phase' => null,
            'percent' => 0,
            'processed' => null,
            'total' => null,
            'lines' => [],
            'done' => false,
            'failed' => false,
            'message' => null,
            'report' => null,
            'updated_at' => null,
            'active' => false,
        ];
    }

    private static function path(): string
    {
        return storage_path('app/'.self::FILE);
    }
}
