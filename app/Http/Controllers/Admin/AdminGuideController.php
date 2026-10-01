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

    /**
     * Persian names shown in the panel.
     *
     * The keys stay the legacy English ones because they are file names
     * (use.mp4, ios.txt) and the bot addresses them by key; only the label the
     * administrator reads is translated. `use` is not a platform at all - it is
     * the general how-to-use guide - which is why the page shows it apart.
     */
    private const LABELS = [
        'use' => 'نحوه استفاده کلی',
        'android' => 'اندروید',
        'ios' => 'آی‌اواس',
        'windows' => 'ویندوز',
        'mac' => 'مک',
        'linux' => 'لینوکس',
    ];

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
            'labels' => self::LABELS,
            'existing' => $existing,
            'customItems' => app(GuideService::class)->customItems(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $errors = [];
        $touched = false;
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

                $touched = true;

                continue;
            }

            if ($link !== '') {
                if (! app(GuideService::class)->isValidUrl($link)) {
                    $errors[] = "[$platform] لینک معتبر نیست. لینک باید با http:// یا https:// شروع شود.";

                    continue;
                }

                File::delete($guidePath.'/'.$platform.'.mp4');
                File::put($guidePath.'/'.$platform.'.txt', $link);

                $touched = true;
            }

            // A row that submits neither is left alone rather than refused.
            // The six platforms share one table, and that table is also the
            // edit form: setting one platform means the other five sit there
            // empty, so treating "untouched" as "invalid" would make every
            // save fail until all six were filled in. The rule that matters is
            // on the entries that are being created, below.
        }

        $title = trim((string) $request->input('custom_title', ''));

        if ($title !== '') {
            $slug = $this->safeTitle($title);

            if ($slug === '' || $slug === '.') {
                $errors[] = 'عنوان آموزش اختصاصی معتبر نیست.';
            } else {
                $video = $request->file('custom_video');
                $link = trim((string) $request->input('custom_link', ''));

                if ($video instanceof UploadedFile && $video->isValid()) {
                    if ($error = $this->validateVideo($video)) {
                        $errors[] = "[$title] $error";
                    } else {
                        File::delete($customPath.'/'.$slug.'.txt');
                        $video->move($customPath, $slug.'.mp4');
                        $touched = true;
                    }
                } elseif ($link !== '') {
                    if (! app(GuideService::class)->isValidUrl($link)) {
                        $errors[] = "[$title] لینک معتبر نیست. لینک باید با http:// یا https:// شروع شود.";
                    } else {
                        File::delete($customPath.'/'.$slug.'.mp4');
                        File::put($customPath.'/'.$slug.'.txt', $link);
                        $touched = true;
                    }
                } else {
                    // A titled custom guide with neither a video nor a link is
                    // an entry the bot would offer and then be unable to
                    // deliver, so it is refused instead of stored as a title
                    // with no content behind it. This is the rule "a guide
                    // takes a link or a video" actually has to enforce.
                    $errors[] = "[$title] آموزشی ثبت نشد: یا ویدیو بگذارید یا لینک بدهید.";
                }
            }
        }

        if (! $touched && $errors === []) {
            $errors[] = 'هیچ آموزشی برای ذخیره نبود: در هر ردیف یا ویدیو بگذارید یا لینک بدهید.';
        }

        if ($errors !== []) {
            return back()->with('guide_errors', $errors);
        }

        return back()->with('success', 'آموزش‌ها با موفقیت ذخیره شدند.');
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
                return back()->with('error', 'نام آموزش نامعتبر است.');
            }

            $base = $this->customPath().'/'.$name;
        }

        File::delete($base.'.mp4', $base.'.txt');

        return back()->with('success', 'آموزش حذف شد.');
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
