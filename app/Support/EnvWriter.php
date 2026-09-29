<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reads and writes single keys in the project's `.env` file.
 *
 * This is the piece legacy `setup.php` got wrong in the most damaging way: it
 * wrote the database password, the panel token and the bot token into a PHP file
 * in the document root, `config.php`, where any misconfigured web server would
 * happily serve it. The rewrite keeps those values in `.env`, which is outside
 * the document root by construction, and this class is the only thing allowed to
 * write there.
 *
 * Two rules make it safe to point at a real file:
 *
 *  - only `[A-Z0-9_]` keys are accepted, so a value can never inject a new
 *    setting or a comment;
 *  - values are quoted and escaped, so a password with a space, a `#` or a `$`
 *    survives the round trip instead of being truncated.
 *
 * Nothing here logs or returns a value; callers decide what to show, and the
 * wizard never shows a secret back.
 */
class EnvWriter
{
    /**
     * The keys touched by the last set() call.
     *
     * @var array<int, string>
     */
    private array $lastWritten = [];

    public function path(): string
    {
        return (string) config('setup.env_file', base_path('.env'));
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    public function isWritable(): bool
    {
        $path = $this->path();

        return is_file($path) ? is_writable($path) : is_writable(dirname($path));
    }

    /**
     * The current value of a key, or null when it is absent or empty.
     */
    public function get(string $key): ?string
    {
        $this->assertKey($key);

        foreach ($this->lines() as $line) {
            if (! str_starts_with(trim($line), $key.'=')) {
                continue;
            }

            $value = $this->unquote(trim(substr(trim($line), strlen($key) + 1)));

            return $value === '' ? null : $value;
        }

        return null;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Write one or more keys, creating the file from `.env.example` when it does
     * not exist yet.
     *
     * @param  array<string, string|null>  $values  A null value removes the key.
     */
    public function set(array $values): static
    {
        $this->ensureFile();

        $lines = $this->lines();
        $written = [];

        foreach ($values as $key => $value) {
            $this->assertKey($key);

            $line = $value === null ? null : $key.'='.$this->quote($value);
            $index = $this->indexOf($lines, $key);

            if ($index === null) {
                if ($line !== null) {
                    $lines[] = $line;
                    $written[] = $key;
                }

                continue;
            }

            if ($line === null) {
                unset($lines[$index]);
            } else {
                $lines[$index] = $line;
            }

            $written[] = $key;
        }

        $contents = rtrim(implode(PHP_EOL, array_values($lines)));

        if ($contents !== '') {
            $contents .= PHP_EOL;
        }

        if (@file_put_contents($this->path(), $contents, LOCK_EX) === false) {
            // The key names are safe to show; the values are not, and they are
            // not in this message.
            throw new RuntimeException('The .env file could not be written. Check its permissions: '.$this->path());
        }

        $this->lastWritten = $written;

        return $this;
    }

    /**
     * The keys touched by the last set() call.
     *
     * @return array<int, string>
     */
    public function lastWritten(): array
    {
        return $this->lastWritten;
    }

    /**
     * A 32 byte hex secret, for the webhook secret and the like.
     */
    public static function randomSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Push values that were just written into the running process.
     *
     * The wizard configures the application one step per request, so a value
     * written to `.env` has to be visible immediately: `config()` was populated
     * from the old file when the process booted. Each key in the `config_map` is
     * therefore re-read from disk, pushed into the environment the way Dotenv
     * would have, and written to the config path it feeds, keeping the type the
     * existing value already had (a bool stays a bool, a list stays a list).
     *
     * The database connections are purged when a connection setting changed, so
     * the next query opens a connection with the credentials that were just
     * saved. Keys that only affect the bot, the panel or the app name do not
     * reopen anything: a wizard step should not cost a reconnect, and for a
     * shared host that is a real cost.
     *
     * @param  array<int, string>|null  $keys  Defaults to the keys the last set() call wrote.
     * @return array<int, string> The keys that were applied.
     */
    public function apply(?array $keys = null): array
    {
        /** @var array<string, string> $map */
        $map = (array) config('setup.config_map', []);

        // Only what was just written: the file may also hold values from
        // `.env.example` or from an earlier deployment, and pushing those back
        // would undo whatever this install had already configured.
        $keys ??= $this->lastWritten;
        $map = array_intersect_key($map, array_flip($keys));

        $applied = [];

        foreach ($map as $key => $path) {
            $value = $this->get($key);

            if ($value === null) {
                continue;
            }

            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;

            config([$path => $this->cast($path, $value)]);
            $applied[] = (string) $key;
        }

        $this->mirrorConnectionSettings($applied);

        if (array_intersect($applied, $this->connectionKeys()) !== []) {
            // The old connection objects hold the credentials that were just
            // replaced, so they are dropped and reopened on demand.
            DB::purge();
        }

        return $applied;
    }

    /**
     * The `.env` keys that describe a database connection rather than a feature.
     *
     * @return array<int, string>
     */
    private function connectionKeys(): array
    {
        return array_values(array_filter(
            array_keys((array) config('setup.config_map', [])),
            static fn (string $key): bool => $key === 'DB_CONNECTION' || str_contains($key, 'DB_'),
        ));
    }

    /**
     * Copy the database name onto the connection that is actually in use.
     *
     * `config_map` can only name one destination for `DB_DATABASE`, and MySQL is
     * the one production uses, but the wizard also has to configure SQLite and
     * the tests run on it. Without this the value would land on `mysql` and the
     * connection the application just switched to would keep its old, empty one.
     *
     * @param  array<int, string>  $applied
     */
    private function mirrorConnectionSettings(array $applied): void
    {
        if (in_array('DB_CONNECTION', $applied, true) || in_array('DB_DATABASE', $applied, true)) {
            $name = (string) config('database.default');
            $source = (string) config('setup.config_map.DB_DATABASE');
            $value = config($source);

            if ($value !== null && config("database.connections.{$name}.database") !== $value) {
                config(["database.connections.{$name}.database" => $value]);
            }
        }
    }

    /**
     * Keep the type the configuration already uses at that path.
     */
    private function cast(string $path, string $value): mixed
    {
        $current = config($path);

        if (is_bool($current)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
        }

        if (is_int($current)) {
            return (int) $value;
        }

        if (is_array($current)) {
            return array_values(array_filter(
                array_map('trim', explode(',', $value)),
                static fn (string $item): bool => $item !== '',
            ));
        }

        return $value;
    }

    private function ensureFile(): void
    {
        if ($this->exists()) {
            return;
        }

        $example = base_path('.env.example');

        if (is_file($example) && @copy($example, $this->path())) {
            return;
        }

        if (@file_put_contents($this->path(), '', LOCK_EX) !== false) {
            return;
        }

        throw new RuntimeException('The .env file could not be created: '.$this->path());
    }

    /**
     * @return array<int, string>
     */
    private function lines(): array
    {
        $contents = is_file($this->path()) ? file_get_contents($this->path()) : '';

        if ($contents === false) {
            return [];
        }

        $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function indexOf(array $lines, string $key): ?int
    {
        foreach ($lines as $index => $line) {
            if (str_starts_with(trim($line), $key.'=')) {
                return $index;
            }
        }

        return null;
    }

    private function assertKey(string $key): void
    {
        if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            throw new RuntimeException('Refusing to write a .env key that is not an upper case name: '.$key);
        }
    }

    /**
     * Double quoted, with the three characters dotenv treats specially escaped.
     */
    private function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }

    private function unquote(string $value): string
    {
        $value = trim($value);

        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return str_replace(['\\"', '\\$', '\\\\'], ['"', '$', '\\'], substr($value, 1, -1));
        }

        if (strlen($value) >= 2 && str_starts_with($value, "'") && str_ends_with($value, "'")) {
            return substr($value, 1, -1);
        }

        // An unquoted value ends at the first inline comment.
        $value = (string) preg_replace('/\s+#.*$/', '', $value);

        return trim($value, '"');
    }
}
