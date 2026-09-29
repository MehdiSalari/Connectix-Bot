<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guard for the web admin panel.
 *
 * Port of the `$_SESSION['admin_id']` check at the top of index.php and the
 * other legacy pages: a visitor without a session is sent to the login page
 * and brought back to the page they tried to open once they log in.
 */
class AuthenticateAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('admin')->check()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(401);
        }

        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->guest(route('admin.login'));
    }
}
