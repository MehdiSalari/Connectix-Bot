<?php

declare(strict_types=1);

namespace App\Services\Setup;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Proves a database connection works before anything is written down.
 *
 * Legacy connected with a bare `new PDO(...)` inside `setConfig()` and wrote
 * `config.php` only after that succeeded, which was the right order; this keeps
 * it, with two differences that matter:
 *
 *  - the probe runs on the credentials the operator just typed, not on the ones
 *    the current process booted with, so the first-run case works;
 *  - the error the operator sees is a sentence, not the driver's message. A
 *    driver error such as "Access denied for user 'reseller'@'localhost'" is
 *    both a credential and a host detail, and the specification forbids showing
 *    credentials in the page. The driver code is kept, since "1045" versus "2002"
 *    is the difference between a wrong password and a wrong host.
 */
class DatabaseTester
{
    /**
     * @param  array<string, string>  $input  host, port, database, username, password
     * @return array{ok: bool, message: string, code: int|string}
     */
    public function test(string $driver, array $input, bool $create = false): array
    {
        $driver = strtolower($driver);

        if ($driver === 'sqlite') {
            return $this->testSqlite($input);
        }

        if ($create && $driver === 'mysql') {
            $this->createMysqlDatabase($input);
        }

        try {
            $this->pdo($driver, $input);

            return ['ok' => true, 'message' => 'اتصال برقرار شد', 'code' => 0];
        } catch (PDOException $e) {
            // The driver's own text can contain the username and the host, so it
            // is logged as a code only and replaced with a sentence on screen.
            return [
                'ok' => false,
                'message' => $this->explain($driver, $e->getCode()),
                'code' => (string) $e->getCode(),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'اتصال برقرار نشد', 'code' => $e::class];
        }
    }

    /**
     * The keys the wizard writes for the given driver.
     *
     * @return array<int, string>
     */
    public function keysFor(string $driver): array
    {
        return match (strtolower($driver)) {
            'sqlite' => ['DB_DATABASE'],
            default => ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'],
        };
    }

    /**
     * @param  array<string, string>  $input
     */
    private function pdo(string $driver, array $input): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ];

        // A DSN is a semicolon-separated key=value list, so a host, port or
        // schema name carrying `;` or `=` would rewrite it. Each part is cut
        // back to what it may contain before it is placed in the string; the
        // database name follows the same rule `createMysqlDatabase()` applies.
        $host = (string) preg_replace('~[^A-Za-z0-9.\-_\[\]:]~', '', (string) ($input['host'] ?? 'localhost'));
        $port = isset($input['port']) ? (string) preg_replace('~\D~', '', (string) $input['port']) : null;
        $database = (string) preg_replace('~[^A-Za-z0-9_$\x{0080}-\x{FFFF}\-]~u', '', (string) ($input['database'] ?? ''));

        return match ($driver) {
            'mysql' => new PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $host,
                    $port ?? '3306',
                    $database,
                ),
                $input['username'] ?? '',
                $input['password'] ?? '',
                $options
            ),
            'pgsql' => new PDO(
                sprintf(
                    'pgsql:host=%s;port=%s;dbname=%s',
                    $host,
                    $port ?? '5432',
                    $database
                ),
                $input['username'] ?? '',
                $input['password'] ?? '',
                $options
            ),
            'sqlsrv' => new PDO(
                sprintf(
                    'sqlsrv:Server=%s,%s;Database=%s',
                    $host,
                    $port ?? '1433',
                    $database
                ),
                $input['username'] ?? '',
                $input['password'] ?? '',
                $options
            ),
            default => throw new RuntimeException('The installer cannot create a '.$driver.' connection.'),
        };
    }

    /**
     * Create the schema, but only when the operator asked for it.
     *
     * This is additive and idempotent. `migrate:fresh`, `db:wipe` and `DROP` are
     * never used anywhere in the installer, because on a shared host the
     * database being pointed at is very often one that already holds a running
     * install.
     *
     * @param  array<string, string>  $input
     */
    private function createMysqlDatabase(array $input): void
    {
        $name = (string) ($input['database'] ?? '');

        // Not a parameter: MySQL does not allow a placeholder for an identifier,
        // so the name is restricted to what a schema name can legally contain.
        if (! preg_match('/^[A-Za-z0-9_$\x{0080}-\x{FFFF}-]+$/u', $name)) {
            throw new RuntimeException('The database name contains characters that are not allowed.');
        }

        $pdo = $this->pdo('mysql', ['host' => $input['host'] ?? 'localhost', 'port' => $input['port'] ?? '3306']);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    /**
     * @param  array<string, string>  $input
     * @return array{ok: bool, message: string, code: int|string}
     */
    private function testSqlite(array $input): array
    {
        $path = (string) ($input['database'] ?? '');

        if ($path === '' || $path === ':memory:') {
            return ['ok' => true, 'message' => 'حافظه موقت', 'code' => 0];
        }

        $directory = dirname($path);

        if (! is_dir($directory)) {
            return ['ok' => false, 'message' => 'پوشه فایل دیتابیس وجود ندارد', 'code' => 'no-directory'];
        }

        if (! is_writable($directory)) {
            return ['ok' => false, 'message' => 'پوشه فایل دیتابیس قابل نوشتن نیست', 'code' => 'not-writable'];
        }

        return ['ok' => true, 'message' => 'فایل دیتابیس قابل ساخت است', 'code' => 0];
    }

    private function explain(string $driver, int|string $code): string
    {
        return match ((string) $code) {
            '1045', '28000' => 'دسترسی رد شد. نام کاربری یا رمز عبور درست نیست.',
            '1049', '3D000' => 'این دیتابیس وجود ندارد. می‌توانید ساخت آن را درخواست کنید.',
            '2002', '2003', 'HY000' => 'سرور دیتابیس در دسترس نیست. آدرس و پورت را بررسی کنید.',
            '42S02', '3F000' => 'این دیتابیس وجود ندارد.',
            default => 'اتصال دیتابیس برقرار نشد (کد '.$code.'). نام دیتابیس، کاربر و رمز را بررسی کنید.',
        };
    }
}
