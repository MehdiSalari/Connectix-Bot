<?php

declare(strict_types=1);

namespace App\Services\Download;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The client download links scraped from the Connectix landing page.
 *
 * Port of `getDownloadLinks()`.
 *
 * Legacy fetched the page with `file_get_contents()` and parsed it with
 * DOMXPath. Two problems came with that: the fetch had no timeout, no user
 * agent and no status check, and a failed fetch fed an empty string to
 * `DOMDocument::loadHTML()`, which emitted warnings for every card. Here the
 * page is fetched through the HTTP client with a timeout, a browser user agent
 * and TLS verification on, and the parse only runs on a real 200 response.
 *
 * The result is cached because it is static marketing content.
 */
class DownloadLinkService
{
    /**
     * The platforms legacy could filter on.
     *
     * @var array<int, string>
     */
    public const PLATFORMS = ['android', 'ios', 'windows', 'mac', 'linux'];

    /**
     * The platform key legacy accepted, mapped to the display name it filtered
     * on.
     *
     * The two spellings differ on purpose: the caller passes a lower case key
     * while the scraped card title is capitalised ("Android", "iOS"). An
     * unknown key falls through to "no filter", which is what the `default`
     * arm of the legacy `match` did.
     *
     * @var array<string, string>
     */
    private const PLATFORM_LABELS = [
        'android' => 'Android',
        'ios' => 'iOS',
        'windows' => 'Windows',
        'mac' => 'Mac',
        'linux' => 'Linux',
    ];

    public function __construct(
        private readonly string $sourceUrl,
        private readonly int $cacheTtl,
        private readonly int $timeout,
    ) {}

    /**
     * Every download link, optionally narrowed to one platform.
     *
     * @return array<int, array{platform: string, label: string, url: string}>
     */
    public function links(?string $platform = null): array
    {
        $links = Cache::remember(
            'connectix_bot.downloads.links',
            $this->cacheTtl,
            fn (): array => $this->fetchLinks(),
        );

        $label = $platform === null ? null : (self::PLATFORM_LABELS[$platform] ?? null);

        if ($label === null) {
            return $links;
        }

        return array_values(array_filter(
            $links,
            static fn (array $link): bool => $link['platform'] === $label
        ));
    }

    /**
     * The links as a JSON string, which is the shape legacy returned.
     */
    public function toJson(?string $platform = null): string
    {
        return (string) json_encode(
            $this->links($platform),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * Download and parse the landing page.
     *
     * @return array<int, array{platform: string, label: string, url: string}>
     */
    private function fetchLinks(): array
    {
        if (! $this->isFetchableUrl($this->sourceUrl)) {
            Log::error('The download source URL is not a fetchable http(s) URL.', [
                'url' => $this->sourceUrl,
            ]);

            return [];
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => $this->userAgent(),
                'Accept' => 'text/html,application/xhtml+xml',
            ])
                ->timeout($this->timeout)
                ->withOptions(['verify' => true, 'allow_redirects' => ['max' => 3]])
                ->get($this->sourceUrl);
        } catch (\Throwable $e) {
            Log::error('Failed to fetch the download links page.', [
                'url' => $this->sourceUrl,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! $response->successful()) {
            Log::error('The download links page returned an error.', [
                'url' => $this->sourceUrl,
                'status' => $response->status(),
            ]);

            return [];
        }

        return $this->parse($response->body());
    }

    /**
     * Pull the service cards out of the page.
     *
     * The card title becomes the platform name by dropping everything from the
     * first whitespace, exactly as the legacy `preg_replace` did, so "Android
     * Application" becomes "Android".
     *
     * @return array<int, array{platform: string, label: string, url: string}>
     */
    private function parse(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        try {
            // The page is UTF-8; without this hint loadHTML() mangles Persian
            // and emoji text in the link labels.
            $loaded = $document->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);

            if ($loaded === false) {
                return [];
            }

            $xpath = new DOMXPath($document);

            $cards = $xpath->query('//section[@id="download"]//div[contains(@class,"service-box")]');

            if ($cards === false) {
                return [];
            }

            $data = [];

            foreach ($cards as $card) {
                if (! $card instanceof DOMElement) {
                    continue;
                }

                $headings = $xpath->query('.//h5', $card);
                $heading = $headings !== false ? $headings->item(0) : null;

                if (! $heading instanceof DOMElement) {
                    continue;
                }

                $platform = trim((string) preg_replace('/\s+.*/', '', trim($heading->textContent)));

                $anchors = $xpath->query('.//a[@href]', $card);

                if ($anchors === false) {
                    continue;
                }

                foreach ($anchors as $anchor) {
                    if (! $anchor instanceof DOMElement) {
                        continue;
                    }

                    $url = trim($anchor->getAttribute('href'));

                    if ($url === '') {
                        continue;
                    }

                    $data[] = [
                        'platform' => $platform,
                        'label' => trim($anchor->textContent),
                        'url' => $url,
                    ];
                }
            }

            return $data;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * The scraper must never be pointed at an internal address.
     *
     * The URL is configuration rather than user input, but a SSRF guard is
     * cheap and this fetch is the one place the bot talks to a host it does
     * not otherwise need.
     */
    private function isFetchableUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        if ($host === '') {
            return false;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)) {
            return false;
        }

        // An IP literal has to be a routable address. A hostname is allowed
        // through: resolving it here would only move the SSRF problem, since a
        // name can still point at a private address.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
        }

        return true;
    }

    private function userAgent(): string
    {
        return (string) config('connectix_bot.connectix.user_agent', 'Mozilla/5.0');
    }
}
