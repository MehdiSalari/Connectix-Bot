<?php

declare(strict_types=1);

namespace App\Services\Setup;

use App\Support\EnvWriter;

/**
 * Guarantees the application has an encryption key before the first request.
 *
 * A freshly unpacked release has no `APP_KEY`, and this is a trap the legacy
 * installer did not have: `EncryptCookies` and the session need the key to boot
 * the HTTP kernel, so a wizard that "generates the key in step two" can never
 * render step one. The wizard is exactly the screen a fresh install has to see.
 *
 * So the key is resolved at boot instead, and never per request:
 *
 *  1. `config('app.key')` when it is already set, which is every normal request;
 *  2. otherwise the value in the project's `.env`;
 *  3. otherwise a key cached in `storage/app/connectix/app.key`;
 *  4. otherwise a freshly generated key, written to whichever of the two files
 *     the process can write so the next request reads the same one.
 *
 * Step 4 keeps the sessions and CSRF tokens of the wizard working across
 * requests, which is the whole point: a key that changed on every request would
 * invalidate the operator's session after each click.
 */
class ApplicationKey
{
    /**
     * Make sure an encryption key exists.
     *
     * @return string|null The key that was created, or null when one was already there.
     */
    public function ensure(): ?string
    {
        $current = config('app.key');

        if (is_string($current) && trim($current) !== '') {
            return null;
        }

        $stored = $this->storedKey();

        if ($stored === null) {
            $stored = 'base64:'.base64_encode(random_bytes(32));
            $this->persist($stored);
        }

        $this->activate($stored);

        return $stored;
    }

    /**
     * The key the environment file or the cache file already holds.
     */
    private function storedKey(): ?string
    {
        $fromEnv = app(EnvWriter::class)->get('APP_KEY');

        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            return $fromEnv;
        }

        $file = $this->cachePath();

        if (! is_file($file)) {
            return null;
        }

        $contents = trim((string) @file_get_contents($file));

        return $contents === '' ? null : $contents;
    }

    /**
     * Keep the key in both places when possible: `.env` so it survives a deploy,
     * the cache file so a read only project root does not regenerate it.
     */
    private function persist(string $key): void
    {
        $writer = app(EnvWriter::class);

        if ($writer->isWritable()) {
            try {
                $writer->set(['APP_KEY' => $key]);

                return;
            } catch (\RuntimeException) {
                // Fall through to the cache file below: a read only document root
                // is a perfectly normal hosting setup, and the wizard still has
                // to be able to run.
            }
        }

        $file = $this->cachePath();
        $directory = dirname($file);

        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        if (is_dir($directory) && is_writable($directory)) {
            @file_put_contents($file, $key);
            @chmod($file, 0600);
        }
    }

    /**
     * Put the key in front of the encryption service, which reads its value
     * once when it is resolved and would otherwise hold the empty one.
     */
    private function activate(string $key): void
    {
        putenv('APP_KEY='.$key);
        $_ENV['APP_KEY'] = $key;
        $_SERVER['APP_KEY'] = $key;

        config(['app.key' => $key]);
        app()->forgetInstance('encrypter');

        $this->activateEncrypter($key);
    }

    private function activateEncrypter(string $key): void
    {
        try {
            app('encrypter')->getKey();
        } catch (\Throwable) {
            // Nothing to do: the request that reaches a wizard step will render
            // the report instead, and the requirements step will say the key is
            // missing, which is the truth if we could not store it anywhere.
        }
    }

    private function cachePath(): string
    {
        return (string) config('setup.key_file', storage_path('app/connectix/app.key'));
    }
}
