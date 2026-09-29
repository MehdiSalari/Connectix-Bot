<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Setup\InstallationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides who may open the installer.
 *
 * Two different rules, because "is the installer open" and "may this person use
 * it" are not the same question:
 *
 *  - an application that is not installed yet has no admin to authenticate, so
 *    the wizard is open. There is nothing to protect yet, which is exactly the
 *    state the first-run flow exists for.
 *  - an installed application exposes the wizard only to a signed in
 *    administrator with the `admin` role. For anyone else it answers 404 rather
 *    than 403: the existence of the installer is itself information, and legacy
 *    left `/setup/setup.php` reachable for years.
 *
 * This is the "Setup must not allow creating a new Admin without authorization"
 * requirement: the admin step, which creates or updates an admins row, runs
 * behind this middleware.
 */
class ProtectSetup
{
    public function __construct(
        private readonly InstallationService $installation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->installation->isInstalled()) {
            return $next($request);
        }

        // The one request that follows a successful install is still the person
        // who installed it, and they are not signed in anywhere: the application
        // only became installed a moment ago. Their own session is the proof, so
        // the closing report is reachable and every other step stays shut.
        if ($request->routeIs('setup.done') && $this->justCompleted($request)) {
            return $next($request);
        }

        $admin = Auth::guard('admin')->user();

        if ($admin !== null && $admin->isAdmin()) {
            return $next($request);
        }

        abort(404);
    }

    private function justCompleted(Request $request): bool
    {
        return $request->hasSession() && (bool) $request->session()->get('setup.completed', false);
    }
}
