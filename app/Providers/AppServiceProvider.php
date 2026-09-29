<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Panel\PanelSettingsService;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
