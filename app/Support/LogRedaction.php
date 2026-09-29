<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One place that decides what a log line may contain.
 *
 * Two real leaks were found in the audit: the Telegram bot token rides in the
 * request URL, so a single transport timeout hands Guzzle a message containing
 * `.../bot123456:AAxyz.../sendMessage`; and a failed query exception renders
 * its bindings, so a plaintext client password or a legacy admin token lands in
 * `storage/logs/laravel.log` - a file every other application on a shared host
 * may be able to read. `config/database.php` masks the bindings at the driver,
 * and this class masks everything else before it is written anywhere.
 */
class LogRedaction
{
    /**
     * Mask anything credential-shaped and return a single short line.
     *
     * Covers `password=`, `token:`, `Bearer ...` style pairs, the Telegram bot
     * token inside a URL or on its own, and long digit runs (card and phone
     * numbers) while keeping their last four digits.
     */
    public static function mask(?string $message): string
    {
        if ($message === null || $message === '') {
            return '';
        }

        // https://api.telegram.org/bot<id>:<secret>/method, or the bare token.
        $masked = (string) preg_replace('#/bot\d+:[A-Za-z0-9_-]+#', '/bot***', $message);
        $masked = (string) preg_replace('/\b\d{6,}:AA[A-Za-z0-9_-]+/', '***', $masked);

        // "Bearer <token>" has no = or : for the pair rule to latch onto, so it
        // goes first: otherwise the pair rule consumes the word Bearer itself
        // and leaves the token behind it.
        $masked = (string) preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer ***', $masked);

        $masked = (string) preg_replace(
            '/((?:password|passwd|pwd|token|secret|api[_-]?key|authorization|bearer)\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s,;)]+)/i',
            '$1***',
            $masked,
        );

        // A run of twelve or more digits is a card or phone number, never an id
        // this application logs on purpose. The last four stay for correlation.
        $masked = (string) preg_replace_callback(
            '/(?<!\d)\d{12,}(?!\d)/',
            static fn (array $m): string => '***'.substr($m[0], -4),
            $masked,
        );

        return (string) str($masked)->replace(["\r", "\n"], ' ')->squish()->limit(300, '.');
    }
}
