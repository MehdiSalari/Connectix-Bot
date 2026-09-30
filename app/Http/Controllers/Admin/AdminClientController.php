<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\ConnectixApiException;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Connectix\ConnectixService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function show(Request $request, string $clientId): JsonResponse
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

        // The modal is the only place the full panel record is read, so keep
        // the status columns on the local row in step with it: the accounts
        // list on the profile page reads those columns instead of asking the
        // panel once per row on every page load.
        if ($local !== null) {
            $refresh = [];

            if (array_key_exists('expire_date', $client)) {
                $expireDate = $client['expire_date'];
                $refresh['expire_date'] = is_string($expireDate) && trim($expireDate) !== ''
                    ? trim($expireDate)
                    : null;
            }

            if (array_key_exists('plans', $client) && is_array($client['plans'])) {
                $refresh['plan_status'] = Client::planStatusFromPlans($client['plans']);
            }

            if ($refresh !== []) {
                $local->forceFill($refresh)->save();
            }
        }

        $payload = [
            'client' => $client,
            'local' => $local === null ? null : [
                'username' => $local->username,
                'count_of_devices' => $local->count_of_devices,
                'created_at' => $local->created_at?->format('Y-m-d H:i'),
            ],
        ];

        // An editor may open the record but not take the end user's password
        // away with it: only the role that may change things sees the field.
        $admin = $request->user('admin');

        if ($local !== null && $admin !== null && $admin->isAdmin()) {
            $payload['local']['password'] = $local->password;
        }

        return response()->json($payload);
    }

    /**
     * Delete an account on the panel first, then drop the local row.
     *
     * The panel is the system of record: if it refuses (missing ability on the
     * token, record already gone, transport failure) nothing is deleted here
     * either, so the bot never thinks an account is gone while the panel still
     * sells it. The reason is passed back as an error flash instead of being
     * swallowed, because the usual cause - a token without the delete ability -
     * is something the operator has to fix in the panel.
     */
    public function destroy(Request $request, Client $client): RedirectResponse
    {
        try {
            $this->connectix->deleteClient($client->getKey());
        } catch (ConnectixApiException $e) {
            Log::warning('Client could not be deleted from the panel.', [
                'client_id' => $client->getKey(),
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'حذف از پنل Connectix انجام نشد: '.$e->getMessage());
        }

        $client->delete();

        return back()->with('success', 'اکانت حذف شد.');
    }
}
