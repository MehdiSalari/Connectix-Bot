<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AdminRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role gate for the web admin panel.
 *
 * The legacy `admins.role` column holds `admin` or `editor`; the legacy pages
 * never read it, so this middleware defines where the two roles separate.
 * Anything else, including a guest, answers 403 like any other page that is
 * not theirs.
 */
class EnsureAdminRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $admin = $request->user('admin');

        $allowed = array_map(
            fn (string $role): string => AdminRole::tryFrom($role)?->value ?? $role,
            $roles,
        );

        if ($admin !== null && $admin->role !== null && in_array($admin->role->value, $allowed, true)) {
            return $next($request);
        }

        abort(403);
    }
}
