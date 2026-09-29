<?php

declare(strict_types=1);

namespace App\Services\Guide;

use Illuminate\Support\Facades\Log;

/**
 * Guide videos, guide links and the custom guide items.
 *
 * Port of `getGuideLink()`, `getCustomGuideItems()` and `guideButton()`.
 *
 * Legacy built a filesystem path straight from callback data
 * (`"assets/videos/guide/$action.txt"`), which let a crafted callback read
 * arbitrary text files. Every action now has to match a safe token before it
 * reaches the filesystem, and the resolved path is additionally confirmed to
 * stay inside the guide directory.
 */
class GuideService
{
    /**
     * The guide menus legacy rendered, in order.
     *
     * `use` and `install` are the two menu levels; the rest are platforms.
     *
     * @var array<int, string>
     */
    public const PLATFORMS = ['ios', 'android', 'mac', 'windows', 'linux'];

    public function __construct(
        private readonly string $guidePath,
        private readonly string $customPath,
    ) {}

    /**
     * The URL behind a guide action, when the seller shipped a link file.
     *
     * Port of `getGuideLink()`.
     */
    public function linkFor(string $action): ?string
    {
        if (! $this->isSafeAction($action)) {
            return null;
        }

        $path = $this->resolve($this->guidePath.'/'.$action.'.txt');

        if ($path === null || ! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $url = trim($contents);

        return $this->isValidUrl($url) ? $url : null;
    }

    /**
     * The local video for a guide action, when one exists.
     *
     * `use` falls back to `use.mp4`; every other action uses its own name,
     * matching the `switch` in legacy `guide()`.
     */
    public function videoFor(string $action): ?string
    {
        if (! $this->isSafeAction($action)) {
            return null;
        }

        $name = $action === 'use' ? 'use' : $action;

        return $this->resolve($this->guidePath.'/'.$name.'.mp4');
    }

    /**
     * Whether a guide action is safe to turn into a filename.
     *
     * Anything with a separator, a dot or a null byte is refused, which closes
     * both `../` traversal and absolute path injection.
     */
    public function isSafeAction(string $action): bool
    {
        return $action !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $action) === 1;
    }

    /**
     * The seller's own guide entries.
     *
     * Port of `getCustomGuideItems()`. A `.mp4` becomes a video item and a
     * `.txt` becomes a link item, but only when the file actually holds a
     * valid URL. Files are sorted case-insensitively by name, which is the
     * order the custom menu indexes into.
     *
     * @return array<int, array{title: string, type: string, path?: string, url?: string}>
     */
    public function customItems(): array
    {
        $files = array_merge(
            $this->glob('*.mp4'),
            $this->glob('*.txt'),
        );

        usort(
            $files,
            static fn (string $a, string $b): int => strnatcasecmp(pathinfo($a, PATHINFO_FILENAME), pathinfo($b, PATHINFO_FILENAME))
        );

        $items = [];

        foreach ($files as $file) {
            $title = pathinfo($file, PATHINFO_FILENAME);
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            if ($extension === 'mp4') {
                $items[] = [
                    'title' => $title,
                    'type' => 'video',
                    'path' => $file,
                ];

                continue;
            }

            if ($extension === 'txt') {
                $contents = @file_get_contents($file);

                if ($contents === false) {
                    continue;
                }

                $url = trim($contents);

                if ($this->isValidUrl($url)) {
                    $items[] = [
                        'title' => $title,
                        'type' => 'link',
                        'url' => $url,
                    ];
                }
            }
        }

        return $items;
    }

    /**
     * A custom item by its index in the menu, when it is a playable video.
     *
     * Port of the `custom_(\d+)` branch of legacy `guide()`. Legacy
     * answered the callback with an alert when the item was missing, a link,
     * or unreadable on disk.
     *
     * @return array{title: string, type: string, path?: string, url?: string}|null
     */
    public function customVideo(int $index): ?array
    {
        $item = $this->customItems()[$index] ?? null;

        if ($item === null || $item['type'] !== 'video') {
            return null;
        }

        $path = $this->resolve((string) ($item['path'] ?? ''));

        return $path === null ? null : $item;
    }

    /**
     * Parse a `custom_12` callback into its index.
     */
    public function parseCustomAction(string $action): ?int
    {
        return preg_match('/^custom_(\d+)$/', $action, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    /**
     * A keyboard button for a guide action.
     *
     * Port of `guideButton()`: prefer a direct link when the seller provided
     * one, otherwise fall back to a callback that plays or lists the guide.
     *
     * @return array{text: string, url?: string, callback_data?: string}
     */
    public function button(string $text, string $action): array
    {
        $url = $this->linkFor($action);

        if ($url !== null) {
            return ['text' => $text, 'url' => $url];
        }

        return ['text' => $text, 'callback_data' => 'guide_'.$action];
    }

    /**
     * Whether a string is a URL the bot may hand to Telegram or fetch.
     */
    public function isValidUrl(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * List the files of the custom guide directory, or nothing when the seller
     * never created it. Legacy returned an empty array in that case.
     *
     * @return array<int, string>
     */
    private function glob(string $pattern): array
    {
        $path = $this->resolve($this->customPath);

        if ($path === null || ! is_dir($path)) {
            return [];
        }

        $matches = glob($path.'/'.$pattern);

        return $matches === false ? [] : $matches;
    }

    /**
     * Resolve a path and refuse anything outside the guide directories.
     *
     * `realpath()` returns false for a missing file, so callers get null both
     * for a traversal attempt and for a file that is simply not there.
     */
    private function resolve(string $path): ?string
    {
        $real = realpath($path);

        if ($real === false) {
            return null;
        }

        foreach ([$this->guidePath, $this->customPath] as $base) {
            $realBase = realpath($base);

            if ($realBase !== false && str_starts_with($real, $realBase.DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }

        Log::warning('Refused a guide path that escapes the guide directory.', [
            'path' => $path,
        ]);

        return null;
    }
}
