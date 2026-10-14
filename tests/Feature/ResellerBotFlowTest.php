<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Reseller;
use App\Models\ResellerRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Telegram\Handlers\ResellerHandler;
use App\Telegram\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The bot side of نمایندگی: the status screen, sending a request and the
 * inline approve/reject buttons the fork put on the administrator's
 * notification (bot.php `reseller`, `reseller_apply`, repreqok/repreqno).
 *
 * Driven through ResellerHandler directly — the registry gains it in a later
 * wave.
 */
class ResellerBotFlowTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = 1;

    private const ME = 555;

    private const OTHER = 666;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.active' => true,
            'connectix_bot.admin_ids' => [(string) self::ADMIN],
            'connectix_bot.messages' => [],
            'connectix_bot.panel.enabled' => false,
        ]);

        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200)]);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function makeUser(int $chatId, string $name = 'Ali'): User
    {
        return User::query()->create([
            'chat_id' => (string) $chatId,
            'telegram_id' => (string) $chatId,
            'name' => $name,
            'test' => false,
        ]);
    }

    private function makeWallet(int $chatId, int $balance): void
    {
        Wallet::query()->create([
            'chat_id' => (string) $chatId,
            'balance' => (string) $balance,
        ]);
    }

    // ------------------------------------------------------------------
    // Driving
    // ------------------------------------------------------------------

    private function press(string $data, int $chatId = self::ME): void
    {
        $user = User::query()->where('chat_id', (string) $chatId)->firstOrFail();
        $handler = $this->app->make(ResellerHandler::class);
        $update = TelegramUpdate::fromArray([
            'update_id' => 1,
            'callback_query' => [
                'id' => 'cb-1',
                'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'Ali'],
                'message' => [
                    'message_id' => 42,
                    'chat' => ['id' => $chatId],
                    'text' => 'menu',
                ],
                'data' => $data,
            ],
        ]);

        $this->assertTrue($handler->supports($update, $user), "handler must claim {$data}");

        $handler->handle($update, $user);
    }

    /** The last recorded sendMessage/editMessageText text. */
    private function lastText(): string
    {
        $texts = [];

        foreach (Http::recorded() as [$request]) {
            if ($request->method() !== 'POST') {
                continue;
            }

            foreach (['sendMessage', 'editMessageText'] as $method) {
                if (str_contains($request->url(), $method)) {
                    $texts[] = (string) $request['text'];
                }
            }
        }

        return $texts === [] ? '' : (string) end($texts);
    }

    /** The text of the last answerCallbackQuery (the fork's callback alert). */
    private function lastAnswer(): string
    {
        $answer = '';

        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'POST' && str_contains($request->url(), 'answerCallbackQuery')) {
                $answer = (string) ($request['text'] ?? '');
            }
        }

        return $answer;
    }

    /** The recorded Telegram request whose text contains $needle. */
    private function requestFor(string $needle, ?string $chatId = null)
    {
        foreach (Http::recorded() as [$request]) {
            if ($request->method() !== 'POST') {
                continue;
            }

            if (! str_contains((string) ($request['text'] ?? ''), $needle)) {
                continue;
            }

            if ($chatId !== null && (string) $request['chat_id'] !== $chatId) {
                continue;
            }

            return $request;
        }

        return null;
    }

    /** The inline keyboard of a recorded request, as a lookup string. */
    private function keyboardOf($request): string
    {
        if ($request === null) {
            return '';
        }

        $markup = $request['reply_markup'] ?? '';

        return is_string($markup) ? $markup : json_encode($markup, JSON_UNESCAPED_UNICODE);
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function test_the_reseller_menu_offers_the_application_to_a_plain_user(): void
    {
        $this->makeUser(self::ME);
        $this->makeWallet(self::ME, 50_000);

        $this->press('reseller');

        $text = $this->lastText();

        $this->assertStringContainsString('درخواست نمایندگی', $text);
        $this->assertStringContainsString('50,000 تومان', $text, 'the current balance is shown');

        $request = $this->requestFor('درخواست نمایندگی');
        $keyboard = $this->keyboardOf($request);

        $this->assertStringContainsString('reseller_apply', $keyboard);
        $this->assertStringContainsString('main_menu', $keyboard);
    }

    public function test_applying_creates_a_pending_request_and_notifies_the_admin_with_decision_buttons(): void
    {
        $this->makeUser(self::ME);
        $this->makeWallet(self::ME, 50_000);

        $this->press('reseller_apply');

        $request = ResellerRequest::query()->where('chat_id', (string) self::ME)->firstOrFail();

        $this->assertSame('PENDING', $request->status);
        $this->assertSame(50_000, (int) $request->wallet_balance_at_request);

        // The confirmation the applicant sees.
        $this->assertStringContainsString('درخواست نمایندگی شما ثبت شد', $this->lastText());

        // The administrator's notification carries both decision buttons.
        $admin = $this->requestFor('درخواست نمایندگی جدید', (string) self::ADMIN);
        $this->assertNotNull($admin, 'the administrator was notified');

        $keyboard = $this->keyboardOf($admin);
        $this->assertStringContainsString('repreqok_'.$request->id, $keyboard, 'the approve button carries the request id');
        $this->assertStringContainsString('repreqno_'.$request->id, $keyboard, 'the reject button carries the request id');
    }

    public function test_applying_twice_is_refused_while_the_first_is_pending(): void
    {
        $this->makeUser(self::ME);
        $this->makeWallet(self::ME, 50_000);

        $this->press('reseller_apply');
        $this->press('reseller_apply');

        $this->assertSame(1, ResellerRequest::query()->where('chat_id', (string) self::ME)->count());
        $this->assertStringContainsString('درخواست قبلی شما هنوز در حال بررسی است', $this->lastAnswer(),
            'the fork answered a refused apply with a callback alert');
    }

    public function test_a_non_admin_cannot_approve_with_a_crafted_callback(): void
    {
        $this->makeUser(self::ME);
        $this->makeWallet(self::ME, 50_000);
        $this->press('reseller_apply');

        $pending = ResellerRequest::query()->where('chat_id', (string) self::ME)->firstOrFail();

        // The applicant fires the admin's own button.
        $this->press('repreqok_'.$pending->id, self::ME);

        $this->assertStringContainsString('⛔ دسترسی غیرمجاز.', $this->lastText());
        $this->assertSame('PENDING', $pending->fresh()->status, 'the request was not approved');
        $this->assertNull(Reseller::query()->where('chat_id', (string) self::ME)->first());

        // The same goes for the reject button.
        $this->press('repreqno_'.$pending->id, self::ME);

        $this->assertSame('PENDING', $pending->fresh()->status, 'the request was not rejected');
    }

    public function test_the_admin_approving_activates_the_representative_and_tells_the_applicant(): void
    {
        $this->makeUser(self::ADMIN, 'Admin');
        $this->makeUser(self::ME);
        $this->makeWallet(self::ME, 50_000);
        $this->press('reseller_apply');

        $pending = ResellerRequest::query()->where('chat_id', (string) self::ME)->firstOrFail();

        $this->press('repreqok_'.$pending->id, self::ADMIN);

        $this->assertSame('APPROVED', $pending->fresh()->status);
        $this->assertSame(self::ADMIN, (int) $pending->fresh()->processed_by);

        $reseller = Reseller::query()->where('chat_id', (string) self::ME)->firstOrFail();
        $this->assertTrue($reseller->isActive());

        // The inline button answered, and the applicant was told.
        $this->assertStringContainsString('✅ نمایندگی با موفقیت تایید شد.', $this->lastText());
        $this->assertNotNull($this->requestFor('درخواست نمایندگی شما تایید شد', (string) self::ME));

        // Pressing the stale button again is answered as «not found».
        $this->press('repreqok_'.$pending->id, self::ADMIN);
        $this->assertStringContainsString('این درخواست یافت نشد یا قبلاً بررسی شده است', $this->lastText());
    }

    public function test_the_admin_rejecting_marks_the_request_and_leaves_no_representative(): void
    {
        $this->makeUser(self::ADMIN, 'Admin');
        $this->makeUser(self::OTHER, 'Mina');
        $this->makeWallet(self::OTHER, 50_000);

        $this->press('reseller_apply', self::OTHER);

        $pending = ResellerRequest::query()->where('chat_id', (string) self::OTHER)->firstOrFail();

        $this->press('repreqno_'.$pending->id, self::ADMIN);

        $this->assertSame('REJECTED', $pending->fresh()->status);
        $this->assertNull(Reseller::query()->where('chat_id', (string) self::OTHER)->first());
        $this->assertStringContainsString('❌ درخواست رد شد.', $this->lastText());
        $this->assertNotNull($this->requestFor('درخواست نمایندگی شما رد شد', (string) self::OTHER));

        // A rejected applicant sees the re-apply wording on the menu.
        $this->press('reseller', self::OTHER);
        $this->assertStringContainsString('درخواست قبلی شما رد شده بود', $this->lastText());
    }

    public function test_an_approved_representative_sees_the_summary_instead_of_the_apply_button(): void
    {
        $this->makeUser(self::ME);
        $this->makeWallet(self::ME, 50_000);
        Reseller::query()->create([
            'user_id' => null,
            'chat_id' => (string) self::ME,
            'ref_code' => 'rep_test01',
            'status' => 'ACTIVE',
            'commission_rate' => '10',
            'balance' => '120000',
        ]);

        $this->press('reseller');

        $text = $this->lastText();

        $this->assertStringContainsString('شما نماینده تاییدشده هستید', $text);
        $this->assertStringContainsString('120,000 تومان', $text, 'the representative balance summary');
        $this->assertStringNotContainsString(
            'reseller_apply',
            $this->keyboardOf($this->requestFor('نماینده تاییدشده')),
            'an approved representative never sees the apply button',
        );
    }
}
