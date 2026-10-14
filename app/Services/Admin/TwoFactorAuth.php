<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Admin;

/**
 * Two-Factor Authentication (TOTP, RFC 6238) — pure PHP, no Composer
 * dependency, compatible with Google Authenticator / Authy / Bitwarden /
 * any standard authenticator app.
 *
 * Direct port of the feature fork's services/TwoFactorAuth.php. The one
 * shape change: the fork's verifyAndConsumeBackupCode() took a raw PDO and
 * the admin id; here it takes the Admin model, because this rewrite talks
 * to the database through Eloquent. The behaviour — forgiving input
 * normalisation, one-time use, persisting the shrunk list — is unchanged.
 */
class TwoFactorAuth
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    // ------------------------------------------------------------------
    // Base32 (RFC 4648) — TOTP secrets are conventionally base32
    // ------------------------------------------------------------------

    private static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $b32 = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $b32 .= self::ALPHABET[bindec($chunk)];
        }

        return $b32;
    }

    private static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32) ?? '');
        $bits = '';
        foreach (str_split($b32) as $char) {
            $val = strpos(self::ALPHABET, $char);
            if ($val === false) {
                continue;
            }
            $bits .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) < 8) {
                continue;
            }
            $bytes .= chr(bindec($byte));
        }

        return $bytes;
    }

    // ------------------------------------------------------------------
    // Secret / QR
    // ------------------------------------------------------------------

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function getOtpauthUrl(string $secret, string $accountLabel, string $issuer = 'Connectix Bot'): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$accountLabel)
            .'?secret='.$secret
            .'&issuer='.rawurlencode($issuer)
            .'&algorithm=SHA1&digits=6&period=30';
    }

    // ------------------------------------------------------------------
    // Code generation / verification
    // ------------------------------------------------------------------

    /**
     * The RFC 6238 code for one 30 second step. Public so the feature tests
     * can mint the expected code from a stored secret without a clock mock
     * or a second implementation.
     */
    public static function codeAt(string $secret, int $timestamp, int $period = 30, int $digits = 6): string
    {
        $counter = intdiv($timestamp, $period);
        $binaryCounter = pack('N*', 0).pack('N*', $counter); // 8-byte big-endian
        $secretBinary = self::base32Decode($secret);
        $hash = hash_hmac('sha1', $binaryCounter, $secretBinary, true);
        $offset = ord($hash[19]) & 0x0F;
        $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        $value = $truncated % (10 ** $digits);

        return str_pad((string) $value, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * $window = 1 accepts the previous/current/next 30s step, to tolerate
     * minor clock drift between the server and the user's phone.
     */
    public static function verifyCode(string $secret, string $userCode, int $window = 1): bool
    {
        $userCode = preg_replace('/\s+/', '', (string) $userCode) ?? '';
        if (! preg_match('/^\d{6}$/', $userCode)) {
            return false;
        }

        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            $expected = self::codeAt($secret, $now + ($i * 30));
            if (hash_equals($expected, $userCode)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // One-time backup codes (account recovery if the phone is lost)
    // ------------------------------------------------------------------

    /**
     * Returns ['plain' => [...8 human-readable codes...], 'hashed_json' =>
     * '...'] — store hashed_json in admins.backup_codes, show plain to the
     * admin ONCE (they cannot be retrieved again, only regenerated).
     *
     * @return array{plain: list<string>, hashed_json: string}
     */
    public static function generateBackupCodes(int $count = 8): array
    {
        $plain = [];
        $hashed = [];
        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper(bin2hex(random_bytes(4))); // 8 hex chars
            $formatted = substr($code, 0, 4).'-'.substr($code, 4, 4);
            $plain[] = $formatted;
            $hashed[] = password_hash($formatted, PASSWORD_BCRYPT);
        }

        return ['plain' => $plain, 'hashed_json' => json_encode($hashed)];
    }

    /**
     * Verifies a backup code against the admin's stored hash list and, on
     * success, removes it (one-time use) and persists the updated list.
     */
    public static function verifyAndConsumeBackupCode(Admin $admin, string $inputCode): bool
    {
        // Backup codes are shown as "XXXX-XXXX" — be forgiving if the admin
        // types/pastes it without the hyphen, with extra spaces, or in
        // lowercase; normalize back to the exact stored format before
        // comparing so none of that trips up a real recovery attempt.
        $stripped = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $inputCode) ?? '');
        $inputCode = strlen($stripped) === 8
            ? substr($stripped, 0, 4).'-'.substr($stripped, 4, 4)
            : strtoupper(trim($inputCode));

        $raw = $admin->backup_codes;
        if (! $raw) {
            return false;
        }

        $hashes = json_decode($raw, true);
        if (! is_array($hashes)) {
            return false;
        }

        foreach ($hashes as $index => $hash) {
            if (password_verify($inputCode, $hash)) {
                unset($hashes[$index]);
                $admin->backup_codes = json_encode(array_values($hashes));
                $admin->save();

                return true;
            }
        }

        return false;
    }

    public static function remainingBackupCodesCount(?string $rawJson): int
    {
        if (! $rawJson) {
            return 0;
        }
        $decoded = json_decode($rawJson, true);

        return is_array($decoded) ? count($decoded) : 0;
    }
}
