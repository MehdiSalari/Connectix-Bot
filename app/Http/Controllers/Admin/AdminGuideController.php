<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Guide\GuideService;
use App\Services\Panel\PanelSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The guide manager.
 *
 * Port of the guide section of the legacy root index.php (`saveGuideMedia`,
 * `saveGuideUploads`) and of the custom guide entries. Standard platforms are
 * `use`, `android`, `ios`, `windows`, `mac`, `linux`; each keeps either an
 * mp4 video or a `.txt` holding a URL. Custom entries live under a `custom`
 * subdirectory and use a sanitised title as the filename, exactly as legacy
 * did.
 */
class AdminGuideController extends Controller
{
    /** Standard platforms, in the order legacy saved them. */
    private const PLATFORMS = ['use', 'android', 'ios', 'windows', 'mac', 'linux'];

    /** Legacy refused anything over 10 MB with a Persian error message. */
    private const MAX_VIDEO_BYTES = 10 * 1024 * 1024;

    public function show(): View
    {
        $guidePath = $this->guidePath();
        $customPath = $this->customPath();

        $existing = [];

        foreach (self::PLATFORMS as $platform) {
            $mp4 = $guidePath.'/'.$platform.'.mp4';
            $txt = $guidePath.'/'.$platform.'.txt';

            $existing[$platform] = [
                'mp4' => File::exists($mp4) ? [
                    'size' => File::size($mp4),
                ] : null,
                'txt' => File::exists($txt) ? [
                    'url' => trim((string) File::get($txt)),
                ] : null,
            ];
        }

        return view('admin.guides.index', [
            'appName' => app(PanelSettingsService::class)->appName(),
            'platforms' => self::PLATFORMS,
            'existing' => $existing,
            'customItems' => app(GuideService::class)->customItems(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $errors = [];
        $guidePath = $this->guidePath();
        $customPath = $this->customPath();

        File::ensureDirectoryExists($guidePath);
        File::ensureDirectoryExists($customPath);

        foreach (self::PLATFORMS as $platform) {
            $video = $request->file("guide_{$platform}_video");
            $link = trim((string) $request->input("guide_{$platform}_link", ''));

            if ($video instanceof UploadedFile && $video->isValid()) {
                if ($error = $this->validateVideo($video)) {
                    $errors[] = "[$platform] $error";

                    continue;
                }

                File::delete($guidePath.'/'.$platform.'.txt');
                $video->move($guidePath, $platform.'.mp4');

                continue;
            }

            if ($link !== '') {
                if (! app(GuideService::class)->isValidUrl($link)) {
                    $errors[] = "[$platform] لینک معتبر نیست.";

                    continue;
                }

                File::delete($guidePath.'/'.$platform.'.mp4');
                File::put($guidePath.'/'.$platform.'.txt', $link);
            }
        }

        $title = trim((string) $request->input('custom_title', ''));

        if ($title !== '') {
            $slug = $this->safeTitle($title);

            if ($slug === '' || $slug === '.') {
                $errors[] = 'عنوان راهنمای اختصاصی معتبر نیست.';
            } else {
                $video = $request->file('custom_video');
                $link = trim((string) $request->input('custom_link', ''));

                if ($video instanceof UploadedFile && $video->isValid()) {
                    if ($error = $this->validateVideo($video)) {
                        $errors[] = "[$title] $error";
                    } else {
                        File::delete($customPath.'/'.$slug.'.txt');
                        $video->move($customPath, $slug.'.mp4');
                    }
                } elseif ($link !== '') {
                    if (! app(GuideService::class)->isValidUrl($link)) {
                        $errors[] = "[$title] لینک معتبر نیست.";
                    } else {
                        File::delete($customPath.'/'.$slug.'.mp4');
                        File::put($customPath.'/'.$slug.'.txt', $link);
                    }
                }
            }
        }

        if ($errors !== []) {
            return back()->with('guide_errors', $errors);
        }

        return back()->with('success', 'راهنماها با موفقیت ذخیره شدند.');
    }

    /**
     * Remove one standard or custom guide.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['platform', 'custom'])],
            'name' => ['required', 'string', 'max:190'],
        ]);

        $name = $data['name'];

        if ($data['type'] === 'platform') {
            if (! in_array($name, self::PLATFORMS, true)) {
                return back()->with('error', 'پلتفرم نامعتبر است.');
            }

            $base = $this->guidePath().'/'.$name;
        } else {
            if ($name !== $this->safeTitle($name)) {
                return back()->with('error', 'نام راهنما نامعتبر است.');
            }

            $base = $this->customPath().'/'.$name;
        }

        File::delete($base.'.mp4', $base.'.txt');

        return back()->with('success', 'راهنما حذف شد.');
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * @return string|null An error message, or null when the upload is usable.
     */
    private function validateVideo(UploadedFile $video): ?string
    {
        if (strtolower($video->getClientOriginalExtension()) !== 'mp4') {
            return 'فقط فایل MP4 مجاز است.';
        }

        $mime = strtolower((string) $video->getMimeType());

        // Sniffed from the bytes, not taken from the request: `application/octet-stream`
        // was accepted once and made the check cosmetic.
        if ($mime !== 'video/mp4') {
            return 'فایل باید ویدیوی MP4 باشد.';
        }

        if ($video->getSize() > self::MAX_VIDEO_BYTES) {
            return 'حجم ویدیو نباید بیشتر از ۱۰ مگابایت باشد.';
        }

        return null;
    }

    /**
     * Port of the legacy `safeGuideTitle()`: separators that would break the
     * filename are replaced so a title can never escape the guide directory.
     */
    private function safeTitle(string $title): string
    {
        $slug = preg_replace('~[\\\\/:*?"<>|]+~u', '-', $title);

        return is_string($slug) ? trim($slug) : '';
    }

    private function guidePath(): string
    {
        return base_path((string) config('connectix_bot.guides.path'));
    }

    private function customPath(): string
    {
        return base_path((string) config('connectix_bot.guides.custom_path'));
    }
}
