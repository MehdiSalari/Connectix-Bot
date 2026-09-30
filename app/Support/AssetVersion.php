<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Cache-busting stamp for the design system's stylesheet.
 *
 * The three layouts used to ask for `?v={{ config('app.version') }}`, but no
 * `version` key is published in config/app.php, so the query string was the
 * literal `4` forever: a browser that had the panel open once kept the old
 * sheet through every later deployment. The stamp is the file's mtime, which
 * changes exactly when the css does, with the app version as the fallback for
 * an installation whose public/ is not readable.
 */
final class AssetVersion
{
    public static function css(): string
    {
        $file = public_path('css/connectix.css');

        if (is_file($file) && is_readable($file)) {
            $modified = filemtime($file);

            if ($modified !== false) {
                return (string) $modified;
            }
        }

        return trim((string) config('app.version', '4')) ?: '4';
    }
}
