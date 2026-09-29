<?php

declare(strict_types=1);

namespace App\Services\Connectix;

use App\Exceptions\ConnectixApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for the Connectix seller panel API.
 *
 * Replaces the scattered cURL calls in the legacy code (getClientData,
 * createClient, updateClient, getClientByUsername, getSellerPlans,
 * checkCoupon, addAccount). All endpoints live under /v1/seller and are
 * authenticated with a static bearer token taken from configuration.
 *
 * The legacy calls disabled TLS peer verification in several places. That is
 * not reproduced: verification is on by default and can only be disabled
 * through CONNECTIX_VERIFY_TLS for troubleshooting.
 *
 * @see https://connectix.vip
 */
class ConnectixService
{
    // -----------------------------------------------------------------
    // Clients
    // -----------------------------------------------------------------

    /**
     * Full client record, including its plans.
     *
     * Port of `getClientData()`. Returns null when the panel reports no client
     * or answers with an error envelope.
     *
     * @return array<string, mixed>|null
     *
     * @throws ConnectixApiException
     */
    public function getClientData(string $clientId): ?array
    {
        $payload = $this->get('/v1/seller/clients/show', ['id' => $clientId]);

        $client = $payload['client'] ?? null;

        if (! is_array($client)) {
            Log::warning('Connectix returned no client record.', ['client_id' => $clientId]);

            return null;
        }

        return $client;
    }

    /**
     * Find a client by its Connectix username.
     *
     * Port of `getClientByUsername()`, which returns the first match.
     *
     * @return array<string, mixed>|null
     *
     * @throws ConnectixApiException
     */
    public function getClientByUsername(string $username): ?array
    {
        $payload = $this->get('/v1/seller/clients', ['username' => $username]);

        $client = $payload['clients']['data'][0] ?? null;

        return is_array($client) ? $client : null;
    }

    /**
     * Create a client together with its first plan.
     *
     * Port of `createClient()`. The random five character password is
     * generated here, exactly as legacy did with `rand(0, 35)`.
     *
     * @return array<string, mixed> The decoded response, containing `client_id`.
     *
     * @throws ConnectixApiException
     */
    public function createClient(
        ?string $name,
        string|int $chatId,
        ?string $telegramUsername,
        string $planId,
        ?string $password = null,
    ): array {
        $password ??= $this->generatePassword();

        $body = [
            'id' => null,
            'name' => $name,
            'email' => null,
            'created_at' => null,
            'remains_days' => null,
            'expire_date' => null,
            'count_of_plans' => null,
            'plans' => [],
            'count_of_devices' => 0,
            'added_by' => null,
            'password' => $password,
            'phone' => null,
            'chat_id' => $chatId,
            'telegram_id' => $telegramUsername,
            'group_id' => null,
            'plan_id' => $planId,
            'enable_plan_after_first_login' => true,
            'username' => '',
            'group_name' => '',
            'plan_name' => '',
            'used_traffic' => '',
            'is_active' => false,
            'is_expired' => false,
            'connection_status' => '',
            'last_active_date' => '',
            'subscription_link' => '',
            'used_devices' => [
                'os' => '',
                'model' => '',
            ],
            'outline_link' => '',
            'is_child_protection_enabled' => false,
            'notes' => '',
        ];

        return $this->post('/v1/seller/clients/store', $body);
    }

    /**
     * Attach a plan to an existing client, which is how a renewal works.
     *
     * Port of `updateClient()`, which despite its name calls `clients/add-plan`.
     *
     * @return array<string, mixed>
     *
     * @throws ConnectixApiException
     */
    public function addPlanToClient(string $clientId, string $planId): array
    {
        return $this->post('/v1/seller/clients/add-plan', [
            'id' => $clientId,
            'plan_id' => $planId,
        ]);
    }

    /**
     * Update a client's credentials, device count and Telegram linkage.
     *
     * Port of the inline cURL block in `addAccount()`.
     *
     * @return array<string, mixed>
     *
     * @throws ConnectixApiException
     */
    public function updateClientCredentials(
        string $clientId,
        string $password,
        int $countOfDevices,
        string|int $chatId,
        ?string $telegramUsername,
    ): array {
        return $this->post('/v1/seller/clients/update', [
            'id' => $clientId,
            'password' => $password,
            'count_of_devices' => $countOfDevices,
            'chat_id' => $chatId,
            'telegram_id' => $telegramUsername,
        ]);
    }

    /**
     * One page of the seller client list, used by the sync command.
     *
     * @return array<string, mixed>
     *
     * @throws ConnectixApiException
     */
    public function listClients(int $page = 1): array
    {
        return $this->get('/v1/seller', ['page' => $page]);
    }

    // -----------------------------------------------------------------
    // Plans
    // -----------------------------------------------------------------

    /**
     * The full seller plan catalogue, including groups and periods.
     *
     * This is the single call behind every `getSellerPlans($type)` branch.
     *
     * @return array<string, mixed>
     *
     * @throws ConnectixApiException
     */
    public function getSellerPlansPayload(): array
    {
        return $this->get('/v1/seller/seller-plans');
    }

    // -----------------------------------------------------------------
    // Coupons
    // -----------------------------------------------------------------

    /**
     * Coupons configured on the seller panel.
     *
     * Port of `checkCoupon()`; filtering by code happens in CouponService.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ConnectixApiException
     */
    public function getCoupons(): array
    {
        $payload = $this->get('/v1/seller/seller-plans/coupons');

        $coupons = $payload['coupons'] ?? [];

        return is_array($coupons) ? array_values($coupons) : [];
    }

    // -----------------------------------------------------------------
    // Seller
    // -----------------------------------------------------------------

    /**
     * Identity of the token holder, used to validate a stored panel token.
     *
     * @return array<string, mixed>|null
     *
     * @throws ConnectixApiException
     */
    public function getSellerData(): ?array
    {
        $payload = $this->get('/v1/seller/seller-data');

        $seller = $payload['data']['seller'] ?? $payload['seller'] ?? null;

        return is_array($seller) ? $seller : null;
    }

    /**
     * Push the Telegram bot token to the seller panel.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws ConnectixApiException
     */
    public function storeTelegramBot(array $body): array
    {
        return $this->post('/v1/seller/telegram-bot', $body);
    }

    /**
     * The reseller's own branding and bot copy, as configured in the seller
     * panel. Legacy `setup.php` called this endpoint once at install time and
     * stored the result in setup/bot_config.json; see PanelSettingsService for
     * the runtime equivalent.
     *
     * @return array{
     *     bot: array<string, mixed>,
     *     telegram_messages: array<string, string>
     * }
     *
     * @throws ConnectixApiException
     */
    public function getTelegramBotConfig(): array
    {
        $payload = $this->get('/v1/seller/telegram-bot');

        return [
            'bot' => Arr::wrap($payload['bot'] ?? []),
            'telegram_messages' => Arr::wrap($payload['telegramMessages'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws ConnectixApiException
     */
    public function updateTelegramBot(array $body): array
    {
        return $this->post('/v1/seller/telegram-bot/update-bot', $body);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Five lowercase alphanumeric characters, matching legacy `createClient()`.
     */
    private function generatePassword(): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
        $password = '';

        for ($i = 0; $i < 5; $i++) {
            $password .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return $password;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws ConnectixApiException
     */
    private function get(string $endpoint, array $query = []): array
    {
        return $this->send('get', $endpoint, ['query' => $query]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws ConnectixApiException
     */
    private function post(string $endpoint, array $body): array
    {
        return $this->send('post', $endpoint, ['json' => $body]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws ConnectixApiException
     */
    private function send(string $verb, string $endpoint, array $options): array
    {
        $token = config('connectix_bot.connectix.token');

        if (blank($token)) {
            throw new ConnectixApiException(
                'Connectix panel token is not configured.',
                $endpoint
            );
        }

        $request = $this->client()->withToken($token);

        try {
            $response = $verb === 'get'
                ? $request->get($this->url($endpoint), $options['query'] ?? [])
                : $request->post($this->url($endpoint), $options['json'] ?? []);
        } catch (ConnectionException $e) {
            Log::error('Connectix API transport failure.', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            throw ConnectixApiException::transport($endpoint, $e->getMessage());
        }

        return $this->decode($endpoint, $response);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ConnectixApiException
     */
    private function decode(string $endpoint, Response $response): array
    {
        $payload = $response->json();

        if ($response->failed() || ! is_array($payload)) {
            $exception = ConnectixApiException::fromResponse(
                $endpoint,
                $response->status(),
                $response->body()
            );

            Log::error('Connectix API call failed.', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
            ]);

            throw $exception;
        }

        return $payload;
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders([
                'User-Agent' => (string) config('connectix_bot.connectix.user_agent'),
            ])
            ->connectTimeout((int) config('connectix_bot.connectix.connect_timeout'))
            ->timeout((int) config('connectix_bot.connectix.timeout'))
            ->withOptions([
                'verify' => (bool) config('connectix_bot.connectix.verify_tls', true),
            ]);
    }

    private function url(string $endpoint): string
    {
        return rtrim((string) config('connectix_bot.connectix.base_url'), '/').$endpoint;
    }
}
