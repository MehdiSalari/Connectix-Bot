<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Setup\InstallationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps an uninstalled application out of its own runtime.
 *
 * Legacy had no equivalent: the bot answered every message and the panel threw
 * a database error, and the only hint of what was wrong was a fatal in the PHP
 * log. The rewrite detects an incomplete installation before the request reaches
 * any application code and sends it to the wizard instead.
 *
 * Three cases, which is all the specification asks for:
 *
 *  - installed          -> the request is served normally;
 *  - not installed      -> the wizard is opened for a browser, and a machine
 *                          (the Telegram webhook, the bank gateway) gets a JSON
 *                          503 rather than a redirect it cannot follow;
 *  - a request that the
 *    installer needs    -> `/setup` and the health endpoint are always allowed,
 *                          otherwise the wizard could never be reached on a
 *                          fresh deployment.
 *
 * The wizard itself decides who may see it once the application is installed;
 * that is SetupWizardController's job, not this one's.
 */
class EnsureInstalled
{
    /**
     * The routes that must answer even on a fresh deployment: the installer and
     * the health probe a host or a load balancer uses to see the app is up.
     */
    private const ALWAYS_ALLOWED = ['setup', 'setup/*', 'up'];

    /**
     * Server-to-server endpoints. They get a JSON 503 because there is no one to
     * show a redirect to, and the operator sees the reason in the Telegram or
     * bank logs instead of an HTML login page.
     */
    private const MACHINE = ['telegram/*', 'bank/*', 'api/*'];

    public function __construct(
        private readonly InstallationService $installation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Off outside production, and switched on per test by the setup suite.
        if (config('setup.enforce', true) !== true) {
            return $next($request);
        }

        if ($this->isAlwaysAllowed($request) || $this->installation->isInstalled()) {
            return $next($request);
        }

        if ($this->isMachine($request) || $request->expectsJson()) {
            return response()->json([
                'message' => 'The application is not configured yet. Run the installer at /setup.',
                'setup_url' => route('setup.index'),
            ], 503);
        }

        // A browser keeps its intended URL so a logged in operator who is caught
        // by a half finished re-run lands back where they were.
        if ($request->hasSession() && ! $request->isMethodSafe()) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('setup.index');
    }

    private function isAlwaysAllowed(Request $request): bool
    {
        foreach (self::ALWAYS_ALLOWED as $path) {
            if ($request->is($path)) {
                return true;
            }
        }

        return false;
    }

    private function isMachine(Request $request): bool
    {
        foreach (self::MACHINE as $path) {
            if ($request->is($path)) {
                return true;
            }
        }

        return false;
    }
}
