<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\ConnectixApiException;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Connectix\ConnectixService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Connectix client details for the user profile.
 *
 * Port of the legacy transactions/actions/get_client_details.php endpoint:
 * given a client id, the seller panel is asked for the live record. Unlike the
 * legacy endpoint this one is behind the admin session middleware - legacy had
 * no check at all on its action endpoints.
 */
class AdminClientController extends Controller
{
    public function __construct(
        private readonly ConnectixService $connectix,
    ) {}

    public function show(string $clientId): JsonResponse
    {
        try {
            $client = $this->connectix->getClientData($clientId);
        } catch (ConnectixApiException $e) {
            Log::warning('Client details could not be loaded from the panel.', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'دریافت اطلاعات کاربر از پنل Connectix ممکن نشد.',
            ], 502);
        }

        if ($client === null) {
            return response()->json([
                'error' => 'کاربری با این شناسه پیدا نشد.',
            ], 404);
        }

        $local = Client::query()->whereKey($clientId)->first();

        return response()->json([
            'client' => $client,
            'local' => $local === null ? null : [
                'username' => $local->username,
                'password' => $local->password,
                'count_of_devices' => $local->count_of_devices,
                'created_at' => $local->created_at?->format('Y-m-d H:i'),
            ],
        ]);
    }
}
