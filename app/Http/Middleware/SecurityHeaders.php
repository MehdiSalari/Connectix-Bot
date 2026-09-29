<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers the application never sets itself.
 *
 * Prepended outside every other middleware, including the first-run guard, so
 * a redirect to the installer and the JSON 404s of the setup protection carry
 * them too. The policy is deliberately not tight enough to need per-page
 * exceptions: styles and scripts may be inline (every layout writes its own),
 * images may come from anywhere over TLS (avatars are served by Telegram), and
 * the only third-party origin is the font host the landing page asks for.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Content-Security-Policy', $this->policy());

        // Only meaningful over TLS; a browser ignores it on plain HTTP, which
        // is exactly the behaviour wanted until the panel is served securely.
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=15552000');
        }

        return $response;
    }

    /**
     * `localhost:5173` is the Vite dev server; without it the landing page
     * loses its stylesheet under `npm run dev`. Nothing on a server ever
     * answers there, so the allowance costs nothing in production.
     */
    private function policy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "img-src 'self' data: https:",
            "font-src 'self' data: https://fonts.bunny.net",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net http://localhost:5173",
            "script-src 'self' 'unsafe-inline' http://localhost:5173",
            "connect-src 'self' ws://localhost:5173",
        ]);
    }
}
