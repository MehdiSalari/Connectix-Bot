<?php

declare(strict_types=1);

namespace App\Services\Setup;

/**
 * Start a setup import in its own process, outside the web request.
 *
 * The built-in dev server (`php -S`, what `artisan serve` runs on Windows)
 * serves ONE request at a time: while a long import POST is open, the
 * progress endpoint cannot answer a single poll, so the import step sits
 * frozen for minutes with a pile of pending requests behind it. Running the
 * import in a separate process frees the server — the tab polls a file the
 * child keeps writing, and the rest of the application stays usable while
 * the import runs.
 *
 * The request never waits for that child: closing a proc_open handle waits
 * for the process to exit, which is the whole bug, so the handle is released
 * and the child keeps going on its own.
 *
 * Inputs travel through a payload file rather than argv: the legacy database
 * password would otherwise be visible in the process list of anyone on the
 * box. The password itself never reaches the file — the controller has
 * already written it to `.env` by the time this runs, and the child reads it
 * from there.
 */
class ImportSpawner
{
    /** Relative to storage/app; outside the document root, not web readable. */
    public const PAYLOAD_FILE = 'connectix/import_request.json';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $action, array $payload): bool
    {
        $path = self::payloadPath();

        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }

        $json = json_encode(['action' => $action] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false || @file_put_contents($path, $json) === false) {
            return false;
        }

        $log = storage_path('logs/setup-import.log');
        $command = sprintf('"%s" "%s" setup:import', PHP_BINARY, base_path('artisan'));

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ];

        if (PHP_OS_FAMILY === 'Windows') {
            // DETACHED_PROCESS | CREATE_NEW_PROCESS_GROUP: no console window,
            // and the child survives the request (and the tab) that spawned it.
            $process = @proc_open($command, $descriptors, $pipes, base_path(), null, [
                'bypass_shell' => true,
                'create_process_flags' => 0x00000008 | 0x00000200,
            ]);
        } else {
            $process = @proc_open('nohup '.$command, $descriptors, $pipes, base_path(), null, []);
        }

        if (! is_resource($process)) {
            return false;
        }

        foreach ($pipes as $pipe) {
            @fclose($pipe);
        }

        // Deliberately never proc_close()d — that call waits for the child,
        // which is exactly the freeze this class exists to remove. The handle
        // is released when the request ends; the child keeps running.
        unset($process);

        return true;
    }

    public static function payloadPath(): string
    {
        return storage_path('app/'.self::PAYLOAD_FILE);
    }
}
