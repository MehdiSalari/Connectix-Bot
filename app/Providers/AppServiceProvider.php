<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Download\DownloadLinkService;
use App\Services\Guide\GuideService;
use App\Services\Panel\PanelSettingsService;
use App\Services\Setup\ApplicationKey;
use App\Services\Telegram\AdminGuard;
use App\Services\Telegram\TelegramProfileService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
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
        $this->shareBotProfile();

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

        // An https APP_URL has to keep producing https URLs through the
        // tunnel — the origin only ever sees plain http — but forcing it
        // unconditionally broke the local entry: the admin login form then
        // posted to https://127.0.0.1:8000, where nothing speaks TLS, so the
        // button appeared dead. Force https only for a request that really
        // arrived over TLS (the tunnel forwards `X-Forwarded-Proto`), and let
        // the session cookie's Secure flag follow the same signal: marked
        // Secure on the tunnel, dropped on plain http, where the browser
        // would otherwise throw the cookie away and log the admin straight
        // back out.
        $secure = $this->requestArrivedOverTls();

        if ($secure && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        config(['session.secure' => $secure]);

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

    /**
     * The bot's own photo, available to every view.
     *
     * The sidebar brand is on every panel page, so the profile cannot be
     * passed down by each controller that happens to render one. The service
     * caches the answer for hours and returns an empty profile in tests, so
     * sharing it costs nothing: one cache read per request, no network.
     */
    private function shareBotProfile(): void
    {
        View::composer('*', static function (ViewContract $view): void {
            $view->with('botProfile', app(TelegramProfileService::class)->botProfile());
        });
    }

    /**
     * Whether the request reached the origin over TLS — directly, or through
     * the tunnel, which forwards the scheme Cloudflare saw.
     *
     * The origin only ever listens on plain http, so `isSecure()` on its own
     * always answers no and every generated URL would come out http://, the
     * hop the Secure session cookie does not survive. The forwarded header is
     * the only place the tunnel's scheme is visible from here.
     */
    private function requestArrivedOverTls(): bool
    {
        $request = $this->app['request'];

        if ($request->isSecure()) {
            return true;
        }

        $forwarded = (string) $request->headers->get('X-Forwarded-Proto', '');
        $scheme = strtolower(trim(explode(',', $forwarded)[0]));

        return $scheme === 'https';
    }
}
