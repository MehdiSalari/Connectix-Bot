<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Download\DownloadLinkService;
use App\Services\Guide\GuideService;
use App\Services\Panel\PanelSettingsService;
use App\Services\Setup\ApplicationKey;
use App\Services\Telegram\AdminGuard;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The guard is built from the resolved settings so an install that only
        // configured its administrators in the seller panel still recognises
        // them. Bound here because the guard has a constructor the container
        // cannot satisfy on its own.
        $this->app->singleton(AdminGuard::class, static fn ($app): AdminGuard => AdminGuard::fromSettings(
            $app->make(PanelSettingsService::class)
        ));

        // GuideService and DownloadLinkService take plain strings rather than
        // config arrays so they stay unit testable; the container supplies the
        // configured values here.
        $this->app->singleton(GuideService::class, static fn (): GuideService => new GuideService(
            (string) config('connectix_bot.guides.path'),
            (string) config('connectix_bot.guides.custom_path'),
        ));

        $this->app->singleton(DownloadLinkService::class, static fn (): DownloadLinkService => new DownloadLinkService(
            (string) config('connectix_bot.downloads.source_url'),
            (int) config('connectix_bot.downloads.cache_ttl'),
            (int) config('connectix_bot.downloads.timeout'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningUnitTests()) {
            // The key is a side effect on the developer's own `.env`, and a test
            // suite has no business writing that file. The service itself is
            // tested directly in tests/Feature/SetupTest.php instead.
            return;
        }

        // Before the HTTP kernel: EncryptCookies and the session refuse to boot
        // without a key, so a freshly unpacked release could not even render the
        // installer that is meant to give it one.
        $this->app->make(ApplicationKey::class)->ensure();
    }
}
