<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Download\DownloadLinkService;
use App\Services\Guide\GuideService;
use App\Services\Panel\PanelSettingsService;
use App\Services\Setup\ApplicationKey;
use App\Services\Telegram\AdminGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        $this->configureLoginThrottle();

        if (! config('app.debug')) {
            // Exception traces keep the first fifteen characters of every
            // argument of every frame; on a shared host the log file is shared
            // too. Debug mode keeps them for local work.
            ini_set('zend.exception_ignore_args', '1');
        }

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

    /**
     * Cap the attempts against one address from one place.
     *
     * The panel had no rate limit at all: a script that knew an admin's email
     * could guess passwords as fast as the box could hash them, and nothing
     * would ever say so. Five a minute per email+IP is far above what a person
     * at a login form does, and the failures are logged with the address so an
     * attempt is visible after the fact.
     */
    private function configureLoginThrottle(): void
    {
        RateLimiter::for('admin-login', static function (Request $request): Limit {
            $email = Str::lower((string) $request->input('email', ''));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });
    }
}
