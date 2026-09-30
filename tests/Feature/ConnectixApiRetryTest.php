<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ConnectixApiException;
use App\Services\Connectix\ConnectixService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The HTTP rules of the Connectix client: retrying idempotent GETs through
 * transient transport failures, never retrying POSTs, and listing clients
 * through the endpoint the live panel actually serves.
 *
 * A live paid order was lost to a single 10s connect timeout on the
 * read-back after clients/store, so idempotent GETs are retried and POSTs
 * are not: a second clients/store would create a second account on the
 * panel. The retries are driven through faked `ConnectionException`s, which
 * is what the HTTP layer throws when cURL reports a transport failure.
 */
class ConnectixApiRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.connectix.get_attempts' => 3,
        ]);

        Http::preventStrayRequests();
    }

    public function test_a_get_survives_transient_transport_failures(): void
    {
        $attempts = 0;

        Http::fake([
            'https://api.connectix.vip/v1/seller/clients/show*' => function () use (&$attempts) {
                $attempts++;

                if ($attempts < 3) {
                    throw new ConnectionException(
                        'cURL error 28: Connection timed out after 10003 milliseconds',
                    );
                }

                return Http::response(['client' => ['id' => 'abc', 'username' => 'u']], 200);
            },
        ]);

        $client = app(ConnectixService::class)->getClientData('abc');

        $this->assertNotNull($client);
        $this->assertSame('u', $client['username']);
        $this->assertSame(3, $attempts, 'the GET is sent until it succeeds or the attempts run out');
    }

    public function test_a_get_that_never_connects_gives_up_after_the_configured_attempts(): void
    {
        $attempts = 0;

        Http::fake([
            'https://api.connectix.vip/v1/seller/clients/show*' => function () use (&$attempts) {
                $attempts++;

                throw new ConnectionException('cURL error 28: Connection timed out');
            },
        ]);

        try {
            app(ConnectixService::class)->getClientData('abc');
            $this->fail('the transport failure must surface once the attempts are spent');
        } catch (ConnectixApiException $e) {
            $this->assertSame(3, $attempts);
            $this->assertStringContainsString('could not be reached', $e->getMessage());
        }
    }

    public function test_a_post_is_never_retried(): void
    {
        $attempts = 0;

        Http::fake([
            'https://api.connectix.vip/v1/seller/clients/store' => function () use (&$attempts) {
                $attempts++;

                throw new ConnectionException('cURL error 28: Connection timed out');
            },
        ]);

        try {
            app(ConnectixService::class)->createClient('Ali', 555, 'ali', 'plan-1');
            $this->fail('the transport failure must surface');
        } catch (ConnectixApiException $e) {
            $this->assertSame(1, $attempts, 'a POST is sent exactly once so no second account appears');
        }
    }

    public function test_the_client_listing_hits_the_endpoint_the_panel_serves(): void
    {
        Http::fake([
            'https://api.connectix.vip/v1/seller/clients*' => Http::response([
                'total_clients' => 1,
                'clients' => ['data' => [['id' => 'abc', 'username' => 'u']]],
            ], 200),
        ]);

        $payload = app(ConnectixService::class)->listClients(2);

        $this->assertSame('abc', $payload['clients']['data'][0]['id']);

        $recorded = Http::recorded(
            static fn (Request $request): bool => str_contains($request->url(), '/v1/seller/clients?page=2'),
        );

        $this->assertTrue($recorded->isNotEmpty(), 'the listing request must carry the requested page');
    }
}
