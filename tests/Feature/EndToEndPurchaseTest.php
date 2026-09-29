<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The card purchase end to end, over the real HTTP webhook.
 *
 * Every other test drives a single handler with a hand built update; this
 * one posts updates the way Telegram delivers them - through the route, the
 * secret check, the update-id ledger and the gateway - from /start to the
 * paid order, with the administrator's approval as the last step. It is the
 * regression net for the business flow as a whole.
 */
class EndToEndPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a-very-long-random-secret';

    private const CHAT = 555;

    private const ADMIN = 1;

    private int $updateId = 7000;

    private int $messageId = 40;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.telegram.webhook_secret' => self::SECRET,
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.active' => true,
            'connectix_bot.force_channel_join' => false,
            'connectix_bot.app_name' => 'Acme VPN',
            'connectix_bot.admin_ids' => [(string) self::ADMIN],
            'connectix_bot.messages' => [],
            'connectix_bot.panel.enabled' => false,
            'connectix_bot.card.number' => '6037-9999-0000-0000',
            'connectix_bot.card.name' => 'Acme Corp',
            'connectix_bot.bank.name' => null,
        ]);

        Http::preventStrayRequests();

        $this->fakePanel();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function fakePanel(): void
    {
        Http::fake([
            'https://api.connectix.vip/v1/seller/clients/store' => Http::response(['client_id' => 'new-client-uuid'], 200),
            'https://api.connectix.vip/v1/seller/clients/add-plan' => Http::response(['ok' => true], 200),
            'https://api.connectix.vip/v1/seller/clients/show?id=*' => Http::response([
                'client' => [
                    'id' => 'new-client-uuid',
                    'username' => 'acme-user',
                    'password' => 'pass1234',
                    'count_of_devices' => 1,
                    'subscription_link' => 'https://sub.example/abc',
                    'plans' => [[
                        'name' => '(1x) Unlimited-1M',
                        'is_active' => true,
                        'is_in_queue' => false,
                    ]],
                ],
            ], 200),
            'https://api.connectix.vip/v1/seller/seller-plans' => Http::response([
                'groups' => [['name' => 'default']],
                'seller_plan_group' => [
                    [
                        'name' => 'default',
                        'seller_plans' => [
                            [
                                'id' => 11,
                                'type' => 'Premium',
                                'is_displayed_in_robot' => true,
                                'group_name_translations' => ['en' => 'default'],
                                'title' => '(1x) Unlimited-1M',
                                'count_of_devices' => 1,
                                'sell_price' => '120,000',
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
            'https://t.me/*' => Http::response('<html></html>', 200),
        ]);
    }

    // -----------------------------------------------------------------
    // Delivery
    // -----------------------------------------------------------------

    /**
     * Post one update through the webhook and require the acknowledgement
     * Telegram expects before it stops redelivering.
     *
     * @param  array<string, mixed>  $payload
     */
    private function deliver(array $payload): void
    {
        $payload['update_id'] ??= ++$this->updateId;

        $this->postJson('/telegram/webhook', $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => self::SECRET,
        ])->assertOk()->assertExactJson(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function withCallback(string $data, int $chatId): array
    {
        return [
            'callback_query' => [
                'id' => 'cb-'.$this->updateId,
                'from' => [
                    'id' => $chatId,
                    'is_bot' => false,
                    'first_name' => $chatId === self::ADMIN ? 'Admin' : 'Ali',
                ],
                'message' => [
                    'message_id' => ++$this->messageId,
                    'chat' => ['id' => $chatId],
                    'text' => 'menu',
                ],
                'data' => $data,
            ],
        ];
    }

    private function press(string $data, int $chatId): void
    {
        $this->deliver($this->withCallback($data, $chatId));
    }

    private function receiptPayload(int $chatId): array
    {
        return [
            'message' => [
                'message_id' => ++$this->messageId,
                'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'Ali'],
                'chat' => ['id' => $chatId, 'username' => 'ali'],
                'photo' => [
                    ['file_id' => 'small', 'file_unique_id' => 'sm', 'width' => 90, 'height' => 90],
                    ['file_id' => 'AgAC-receipt', 'file_unique_id' => 'lg', 'width' => 1080, 'height' => 1080],
                ],
            ],
        ];
    }

    /**
     * Walk the menus to the card screen, exactly the steps a buyer takes.
     */
    private function walkToCard(): void
    {
        $this->deliver([
            'message' => [
                'message_id' => ++$this->messageId,
                'from' => ['id' => self::CHAT, 'is_bot' => false, 'first_name' => 'Ali'],
                'chat' => ['id' => self::CHAT, 'username' => 'ali'],
                'text' => '/start',
            ],
        ]);

        foreach (['buy', 'group', 'buy_group:default', 'buy_count:1', 'buy_plan:11', 'pay_card:120,000'] as $data) {
            $this->press($data, self::CHAT);
        }
    }

    // -----------------------------------------------------------------
    // Assertions
    // -----------------------------------------------------------------

    private function recorded(): Collection
    {
        return collect(Http::recorded())->map(fn (array $pair) => $pair[0]);
    }

    /**
     * @return list<string>
     */
    private function sentTexts(): array
    {
        return $this->recorded()
            ->filter(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'sendMessage'))
            ->map(fn ($request) => (string) $request['text'])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function editCaptions(): array
    {
        return $this->recorded()
            ->filter(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'editMessageCaption'))
            ->map(fn ($request) => (string) $request['caption'])
            ->values()
            ->all();
    }

    // -----------------------------------------------------------------
    // The flow
    // -----------------------------------------------------------------

    public function test_a_card_purchase_is_paid_end_to_end(): void
    {
        $this->walkToCard();

        $this->deliver($this->receiptPayload(self::CHAT));

        $payment = Payment::query()->firstOrFail();

        $this->assertSame(PaymentStatus::Pending, $payment->is_paid);
        $this->assertSame('120,000', $payment->price);
        $this->assertSame('11', $payment->plan_id);
        $this->assertSame((string) self::CHAT, $payment->chat_id);

        // The receipt photo carries the two decision buttons to the admins.
        $this->assertStringContainsString('📃 سند واریزی مورد تایید میباشد?', $this->lastPhotoCaption());

        $this->press('payment_accept:'.$payment->id, self::ADMIN);

        $payment->refresh();

        $this->assertSame(PaymentStatus::Paid, $payment->is_paid);
        $this->assertSame('new-client-uuid', $payment->client_id);

        // The buyer received the credentials...
        $this->assertStringContainsString(
            'اکانت شما با موفقیت ایجاد شد.',
            implode("\n", $this->sentTexts()),
        );

        // ...and the receipt was captioned with the decision.
        $captions = $this->editCaptions();

        $this->assertNotSame([], $captions);
        $this->assertStringContainsString(
            '✅ سفارش شماره <code>'.$payment->order_number.'</code> با موفقیت تایید شد',
            $captions[array_key_last($captions)],
        );
    }

    /**
     * Telegram redelivers an update it never saw acknowledged. The ledger
     * behind the webhook drops the retry instead of writing a second order.
     */
    public function test_a_redelivered_receipt_is_acknowledged_without_a_second_order(): void
    {
        $this->walkToCard();

        $payload = $this->receiptPayload(self::CHAT);
        $payload['update_id'] = ++$this->updateId;

        $this->deliver($payload);
        $this->deliver($payload);

        $this->assertSame(1, Payment::query()->count());
    }

    private function lastPhotoCaption(): string
    {
        $caption = null;

        foreach (Http::recorded() as [$request, $response]) {
            if ($request->method() === 'POST' && str_contains($request->url(), 'sendPhoto') && $request['caption'] !== null) {
                $caption = (string) $request['caption'];
            }
        }

        $this->assertIsString($caption, 'no sendPhoto with a caption was recorded');

        return $caption;
    }
}
