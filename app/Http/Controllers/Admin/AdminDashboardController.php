<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Panel\PanelSettingsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The landing page behind the login.
 *
 * Phase 12 placeholder: proves authentication and role checks end to end. The
 * real panel (users, clients, payments, wallet, broadcast) lands in Phase 13.
 */
class AdminDashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $admin = $request->user('admin');

        return view('admin.dashboard', [
            'appName' => app(PanelSettingsService::class)->appName(),
            'admin' => $admin,
        ]);
    }
}
