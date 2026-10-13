<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stand-in for the admin sections whose code has not landed yet.
 *
 * The chrome lists every section of the Airoxnet fork ahead of the code that
 * backs it: each placeholder route keeps its sidebar entry alive (the layout
 * renders a nav row only when Route::has() answers yes) while answering 404
 * until the wave that owns the section replaces the registration.
 */
class PlaceholderController extends Controller
{
    public function __invoke(): Response
    {
        abort(404);
    }
}
