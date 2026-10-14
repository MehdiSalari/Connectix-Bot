<?php

declare(strict_types=1);

namespace App\Services\Features;

use App\Models\PanelSetting;
use App\Services\Panel\PanelSettingsService;
use App\Services\Telegram\TelegramService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled database backup (بکاپ دیتابیس).
 *
 * Port of the fork's DbBackupService, with two deliberate differences:
 *
 *  - no ZIP and no mysqldump: the dump is a plain SELECT-based INSERT
 *    script written to `storage/app/backups/database-<ts>.sql`. Both
 *    drivers are covered — MySQL gets SHOW TABLES/SHOW CREATE TABLE when
 *    available, SQLite (the test/default install) iterates `sqlite_master`;
 *    the INSERT builder itself is driver-agnostic, so a mixed install still
 *    produces a usable file.
 *  - the Telegram transport is a seam: sendNow(?callable $sender) hands the
 *    built path to $sender and returns ['ok' => bool, 'error' => ?, 'path'].
 *    With no sender (tests, cron without a bot) the dump is built and left
 *    on disk, reported as ok => false with a transport error, never a crash.
 *
 * Setup lock/unlock is intentionally NOT ported: the installer is guarded
 * differently in this rewrite (noted as a deviation).
 *
 * Settings live in `panel_settings` under `db_backup_*` keys.
 */
class DbBackupAdminService
{
    public static function defaults(): array
    {
        return [
            'enabled' => '0',
            'frequency' => 'daily',
            'time' => '03:00',
            'weekday' => '1',
            'target' => 'admin_id',
            'custom_chat_id' => '',
            'last_sent_at' => '',
            'last_attempt_at' => '',
            'last_error' => '',
        ];
    }

    /** @return array<string, mixed> */
    public static function get(): array
    {
        $d = self::defaults();

        return [
            'enabled' => self::read('db_backup_enabled') === '1',
            'frequency' => self::read('db_backup_frequency') === 'weekly' ? 'weekly' : 'daily',
            'time' => preg_match('/^\d{2}:\d{2}$/', (string) self::read('db_backup_time'))
                ? (string) self::read('db_backup_time')
                : $d['time'],
            'weekday' => max(1, min(7, (int) (self::read('db_backup_weekday') ?? $d['weekday']))),
            'target' => in_array(self::read('db_backup_target'), ['admin_id', 'admin_id_2', 'admin_id_3', 'admin2', 'admin3', 'custom'], true)
                ? (string) self::read('db_backup_target')
                : $d['target'],
            'custom_chat_id' => preg_replace('/[^0-9\-]/', '', (string) self::read('db_backup_custom_chat_id')),
            'last_sent_at' => (string) (self::read('db_backup_last_sent_at') ?? ''),
            'last_attempt_at' => (string) (self::read('db_backup_last_attempt_at') ?? ''),
            'last_error' => (string) (self::read('db_backup_last_error') ?? ''),
        ];
    }

    public static function save(array $in): bool
    {
        $time = trim((string) ($in['time'] ?? ''));
        $target = (string) ($in['target'] ?? 'admin_id');

        self::write('db_backup_enabled', ! empty($in['enabled']) ? '1' : '0');
        self::write('db_backup_frequency', ($in['frequency'] ?? '') === 'weekly' ? 'weekly' : 'daily');
        self::write('db_backup_time', preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '03:00');
        self::write('db_backup_weekday', (string) max(1, min(7, (int) ($in['weekday'] ?? 1))));
        self::write('db_backup_target', in_array($target, ['admin_id', 'admin_id_2', 'admin_id_3', 'admin2', 'admin3', 'custom'], true) ? $target : 'admin_id');
        self::write('db_backup_custom_chat_id', preg_replace('/[^0-9\-]/', '', (string) ($in['custom_chat_id'] ?? '')));

        return true;
    }

    /**
     * Build the dump and hand it to $sender.
     *
     * @param  (callable(array{path: string, name: string, chat_id: ?string}): bool)|null  $sender
     * @return array{ok: bool, error: string|null, path: string|null}
     */
    public static function sendNow(?callable $sender = null): array
    {
        try {
            $path = self::buildDump();
        } catch (\Throwable $e) {
            self::rememberAttempt($e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage(), 'path' => null];
        }

        $chatId = self::targetChatId();

        if ($sender === null) {
            $error = 'ارسال انجام نشد: فرستنده تنظیم نشده است (فایل بکاپ ساخته شد).';
            self::rememberAttempt($error, false);

            return ['ok' => false, 'error' => $error, 'path' => $path];
        }

        $ok = false;
        $error = null;
        try {
            $ok = (bool) $sender([
                'path' => $path,
                'name' => basename($path),
                'chat_id' => $chatId,
            ]);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        if (! $ok) {
            $error ??= 'ارسال ناموفق بود.';
        }

        self::rememberAttempt($ok ? '' : (string) $error, $ok);

        return ['ok' => $ok, 'error' => $ok ? null : $error, 'path' => $path];
    }

    /**
     * Is the scheduled backup due right now?
     *
     * Port of the fork's `DbBackupService::isDue` — the same "most recent
     * scheduled slot that is <= now" arithmetic, evaluated on Tehran time
     * (the fork ran with a Tehran PHP default timezone; this app runs UTC).
     * The latest-slot trick is what makes a missed cron run or a server
     * outage catch up instead of silently skipping a whole day/week.
     */
    public static function isDue(?Carbon $now = null): bool
    {
        $settings = self::get();

        if (! $settings['enabled']) {
            return false;
        }

        $nowTehran = ($now ?? now('Asia/Tehran'))->setTimezone('Asia/Tehran');
        [$hour, $minute] = array_map('intval', explode(':', $settings['time']));

        $slot = $nowTehran->copy()->setTime($hour, $minute, 0);

        if ($settings['frequency'] === 'weekly') {
            $diff = (((int) $nowTehran->format('N')) - (int) $settings['weekday'] + 7) % 7;
            if ($diff > 0) {
                $slot->subDays($diff);
            }
            if ($slot->gt($nowTehran)) {
                $slot->subDays(7);
            }
        } elseif ($slot->gt($nowTehran)) {
            $slot->subDay();
        }

        if ($settings['last_sent_at'] === '') {
            return true;
        }

        try {
            $last = Carbon::parse($settings['last_sent_at'], 'Asia/Tehran');
        } catch (\Throwable) {
            return true;
        }

        return $last->lt($slot);
    }

    /**
     * The scheduler entry point (fork: cron/db_backup_cron.php — fires every
     * 15 minutes and lets isDue() decide). When due, builds the dump and
     * uploads it to the configured target chat through the bot.
     *
     * @param  callable|null  $sender  receives path/name/chat_id; defaults to
     *                                 a Telegram sendDocument to the target.
     * @return array{ok: bool, skipped: bool, error: string|null, path: string|null}
     */
    public static function runDue(?callable $sender = null): array
    {
        if (! self::isDue()) {
            return ['ok' => false, 'skipped' => true, 'error' => null, 'path' => null];
        }

        $sender ??= function (array $payload): bool {
            $response = app(TelegramService::class)->sendMultipart(
                'sendDocument',
                $payload['chat_id'],
                'document',
                $payload['path'],
                ['caption' => 'Connectix database backup — '.$payload['name']],
            );

            return (bool) ($response['ok'] ?? false);
        };

        $result = self::sendNow($sender);
        $result['skipped'] = false;

        return $result;
    }

    /** Where the dump lands: storage/app/backups/database-<ts>.sql. */
    public static function directory(): string
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }

    /**
     * SELECT-based INSERT dump of every table. No shell, no mysqldump.
     */
    public static function buildDump(): string
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        $dir = self::directory();
        if (! is_writable($dir)) {
            throw new \RuntimeException('پوشه بکاپ قابل نوشتن نیست.');
        }

        $path = $dir.'/database-'.now('Asia/Tehran')->format('Ymd-His').'-'.substr(uniqid(), -4).'.sql';

        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('ساخت فایل بکاپ ممکن نشد.');
        }

        try {
            fwrite($handle, "-- Connectix database backup\n-- Generated: ".now('Asia/Tehran')->format('Y-m-d H:i:s')."\n-- Driver: {$driver}\n\n");

            foreach (self::tables() as $table) {
                self::dumpTable($handle, $table);
            }

            fwrite($handle, "\n-- end of backup\n");
        } finally {
            fclose($handle);
        }

        if (filesize($path) === 0) {
            @unlink($path);
            throw new \RuntimeException('فایل بکاپ خالی است.');
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    public static function tables(): array
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'sqlite') {
            $rows = $connection->select(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
            );

            return array_map(static fn ($row): string => (string) $row->name, $rows);
        }

        try {
            $rows = $connection->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        } catch (\Throwable $e) {
            $rows = $connection->select('SHOW TABLES');
        }

        $tables = [];
        foreach ($rows as $row) {
            $values = array_values((array) $row);
            if ($values !== []) {
                $tables[] = (string) $values[0];
            }
        }

        return $tables;
    }

    /**
     * @param  resource  $handle
     */
    private static function dumpTable($handle, string $table): void
    {
        $quoted = '`'.str_replace('`', '``', $table).'`';

        fwrite($handle, "-- ----------------------------\n-- Table: {$table}\n-- ----------------------------\n");

        $connection = DB::connection();

        if ($connection->getDriverName() !== 'sqlite') {
            try {
                $create = $connection->select("SHOW CREATE TABLE {$quoted}");
                $createRow = array_values((array) ($create[0] ?? []));
                if (count($createRow) > 1) {
                    fwrite($handle, 'DROP TABLE IF EXISTS '.$quoted.";\n".(string) $createRow[1].";\n\n");
                }
            } catch (\Throwable $e) {
                // SHOW CREATE unavailable — the INSERTs below still restore the data.
            }
        }

        $rows = $connection->select("SELECT * FROM {$quoted}");
        if ($rows === []) {
            fwrite($handle, "-- (no rows)\n\n");

            return;
        }

        $columns = array_keys((array) $rows[0]);
        $colList = '`'.implode('`,`', array_map(
            static fn (string $c): string => str_replace('`', '``', $c),
            $columns
        )).'`';

        $batch = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ((array) $row as $v) {
                $values[] = self::literal($v);
            }
            $batch[] = '('.implode(',', $values).')';

            if (count($batch) === 500) {
                fwrite($handle, "INSERT INTO {$quoted} ({$colList}) VALUES\n".implode(",\n", $batch).";\n");
                $batch = [];
            }
        }

        if ($batch !== []) {
            fwrite($handle, "INSERT INTO {$quoted} ({$colList}) VALUES\n".implode(",\n", $batch).";\n");
        }

        fwrite($handle, "\n");
    }

    private static function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value)."'";
    }

    public static function targetChatId(?array $c = null): ?string
    {
        $c = $c ?? self::get();

        if ($c['target'] === 'custom') {
            return $c['custom_chat_id'] !== '' ? $c['custom_chat_id'] : null;
        }

        $key = match ($c['target']) {
            'admin_id_2', 'admin2' => 'admin_id_2',
            'admin_id_3', 'admin3' => 'admin_id_3',
            default => 'admin_id',
        };

        $id = PanelSetting::query()->where('setting_key', $key)->value('setting_value');
        if ($id === null || $id === '') {
            $ids = app(PanelSettingsService::class)->adminIds();

            if ($ids === []) {
                return null;
            }
            $index = match ($key) {
                'admin_id_2' => 1,
                'admin_id_3' => 2,
                default => 0,
            };

            return $ids[$index] ?? ($ids[0] ?? null);
        }

        return (string) $id;
    }

    private static function rememberAttempt(string $error, bool $sent = false): void
    {
        self::write('db_backup_last_attempt_at', now('Asia/Tehran')->format('Y-m-d H:i:s'));
        self::write('db_backup_last_error', $error);

        if ($sent) {
            self::write('db_backup_last_sent_at', now('Asia/Tehran')->format('Y-m-d H:i:s'));
        }
    }

    private static function read(string $key): ?string
    {
        return PanelSetting::query()->where('setting_key', $key)->value('setting_value');
    }

    private static function write(string $key, string $value): void
    {
        PanelSetting::query()->updateOrInsert(
            ['setting_key' => $key],
            ['setting_value' => $value]
        );
    }
}
