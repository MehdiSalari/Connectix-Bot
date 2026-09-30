<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads the public display name and avatar of a Telegram username.
 *
 * Port of the legacy `fetchTelegramProfile()`, which scraped the public
 * t.me preview page. The legacy implementation is preserved, including its
 * result shape, so the sync command and `userInfo()` keep working the same
 * way.
 */
class TelegramProfileService
{
    private const TIMEOUT = 20;

    private const CONNECT_TIMEOUT = 10;

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    /**
     * @return array{username: string, exists: bool, display_name: ?string, avatar: ?string, error: ?string}
     */
    public function fetch(?string $username): array
    {
        $normalized = $this->normalizeUsername($username);

        $profile = [
            'username' => $normalized,
            'exists' => false,
            'display_name' => null,
            'avatar' => null,
            'error' => null,
        ];

        if ($normalized === '') {
            return $profile;
        }

        $url = 'https://t.me/'.rawurlencode($normalized);

        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept-Language' => 'en-US,en;q=0.9',
            ])
                ->withOptions(['allow_redirects' => true])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->get($url);
        } catch (ConnectionException $e) {
            $profile['error'] = $e->getMessage();

            Log::warning('Telegram profile scrape failed.', [
                'username' => $normalized,
                'error' => $e->getMessage(),
            ]);

            return $profile;
        }

        if ($response->failed() || trim($response->body()) === '') {
            $profile['error'] = 'http_'.$response->status();

            return $profile;
        }

        return $this->parse($profile, $response->body());
    }

    /**
     * The bot's own identity, loaded the way the legacy panel loaded it: the
     * username from getMe, then the same public t.me page scrape a user avatar
     * goes through (legacy wrote it to assets/images/avatars/bot-avatar.jpg).
     *
     * The answer is cached for six hours so a page render never waits on
     * Telegram, and a failure is cached for only fifteen minutes so a blip
     * does not leave the header without a photo for the rest of the day.
     * Unit tests return an empty profile on purpose: rendering a page must not
     * scrape the network.
     *
     * @return array{username: ?string, avatar: ?string}
     */
    public function botProfile(): array
    {
        $empty = ['username' => null, 'avatar' => null];

        if (app()->runningUnitTests()) {
            return $empty;
        }

        $cached = Cache::get('telegram.bot.profile');

        if (is_array($cached)) {
            return $cached;
        }

        $profile = $empty;

        try {
            $me = app(TelegramService::class)->getMe();
            $username = is_array($me) ? ($me['username'] ?? null) : null;

            if (is_string($username) && trim($username) !== '') {
                $profile['username'] = trim($username);
                $profile['avatar'] = $this->fetch($profile['username'])['avatar'];
            }
        } catch (Throwable $e) {
            Log::warning('The bot profile could not be loaded.', ['error' => $e->getMessage()]);

            Cache::put('telegram.bot.profile', $profile, now()->addMinutes(15));

            return $profile;
        }

        Cache::put('telegram.bot.profile', $profile, now()->addHours(6));

        return $profile;
    }

    /**
     * Strip the t.me URL wrapper so a full link is accepted as input.
     */
    public function normalizeUsername(?string $username): string
    {
        $username = trim((string) $username);

        if ($username === '') {
            return '';
        }

        $username = (string) preg_replace('#^https?://t\.me/#i', '', $username);
        $username = ltrim($username, '@/');

        $parts = explode('?', $username, 2);

        return trim($parts[0], '/');
    }

    /**
     * @param  array{username: string, exists: bool, display_name: ?string, avatar: ?string, error: ?string}  $profile
     * @return array{username: string, exists: bool, display_name: ?string, avatar: ?string, error: ?string}
     */
    private function parse(array $profile, string $html): array
    {
        $previousState = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;
            $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html);

            if (! $loaded) {
                $profile['error'] = 'invalid_html';

                return $profile;
            }

            $xpath = new DOMXPath($dom);

            $titleNodes = $xpath->query('//div[contains(@class, "tgme_page_title")]//span');
            if ($titleNodes !== false && $titleNodes->length > 0) {
                $displayName = trim($titleNodes->item(0)?->textContent ?? '');
                if ($displayName !== '') {
                    $profile['display_name'] = $displayName;
                }
            }

            $imageMetaNodes = $xpath->query('//meta[@property="og:image"]');
            if ($imageMetaNodes !== false && $imageMetaNodes->length > 0) {
                $url = trim($imageMetaNodes->item(0)?->getAttribute('content') ?? '');
                if ($url !== '') {
                    $profile['avatar'] = $url;
                }
            }

            if ($profile['avatar'] === null) {
                $imageNodes = $xpath->query('//img[contains(@class, "tgme_page_photo_image")]');
                if ($imageNodes !== false && $imageNodes->length > 0) {
                    $url = trim($imageNodes->item(0)?->getAttribute('src') ?? '');
                    if ($url !== '') {
                        $profile['avatar'] = $url;
                    }
                }
            }

            $profile['exists'] = $profile['display_name'] !== null || $profile['avatar'] !== null;

            return $profile;
        } catch (Throwable $e) {
            $profile['error'] = $e->getMessage();

            return $profile;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousState);
        }
    }
}
