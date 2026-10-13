<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\PaymentMethod;
use App\Models\Admin;
use App\Models\Payment;
use App\Models\Reseller;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The dashboard's KPI wave: the fork's counter row (blocked users, distinct
 * buyers, open tickets, active resellers, the parsed wallet pool), the
 * transaction-status split behind the doughnut, the top-coupon table and the
 * range selector. Numbers on this page are Persian digits with thousands
 * separators — the panel's own digit convention — while the money cards keep
 * their Latin `number_format` so the pinned chart tests keep matching.
 */
class DashboardKpiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.connectix.base_url' => 'https://api.connectix.vip',
            'connectix_bot.connectix.panel_url' => 'https://seller.connectix.vip',
            'connectix_bot.app_name' => 'Acme VPN',
            'connectix_bot.panel.enabled' => false,
            'connectix_bot.broadcast.delay_us' => 0,
        ]);

        Http::preventStrayRequests();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function actingAsAdmin()
    {
        $admin = Admin::query()->create([
            'email' => 'admin-'.uniqid().'@acme.test',
            'password' => 'secret-pass',
            'token' => 'panel-token-a',
            'chat_id' => '10',
            'role' => AdminRole::Admin,
        ]);

        return $this->actingAs($admin, 'admin');
    }

    private function makeUser(string $chatId, bool $blocked = false): User
    {
        return User::query()->create([
            'chat_id' => $chatId,
            'telegram_id' => 'tg_'.$chatId,
            'name' => 'User '.$chatId,
            'is_blocked' => $blocked,
            'created_at' => now(),
        ]);
    }

    private function makePayment(User $user, array $overrides = []): Payment
    {
        return Payment::query()->create(array_merge([
            'chat_id' => $user->chat_id,
            'client_id' => 'new',
            'plan_id' => 'plan-x',
            'price' => '25,000',
            'is_paid' => '1',
            'method' => PaymentMethod::Card,
            'created_at' => now(),
        ], $overrides));
    }

    // -----------------------------------------------------------------
    // KPI counters
    // -----------------------------------------------------------------

    /** Blocked users, open tickets, active resellers and the parsed wallet pool. */
    public function test_the_kpi_row_counts_blocked_tickets_resellers_and_wallet(): void
    {
        $this->makeUser('800001');
        $this->makeUser('800002', blocked: true);
        $this->makeUser('800003', blocked: true);

        Ticket::query()->create(['ticket_number' => 'T-2001', 'chat_id' => '800001', 'subject' => 'اتصال', 'status' => 'OPEN']);
        Ticket::query()->create(['ticket_number' => 'T-2002', 'chat_id' => '800002', 'subject' => 'پلن', 'status' => 'CLOSED']);

        Reseller::query()->create(['chat_id' => '800001', 'ref_code' => 'REF-A', 'status' => 'ACTIVE']);
        Reseller::query()->create(['chat_id' => '800002', 'ref_code' => 'REF-B', 'status' => 'PENDING']);

        // Comma-formatted balances: a plain SUM would read 100 as the sum of
        // "25,000" and "75,000" stopped at the separator.
        Wallet::query()->create(['chat_id' => '800001', 'balance' => '25,000']);
        Wallet::query()->create(['chat_id' => '800002', 'balance' => '75,000']);

        $html = $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()->getContent();

        // Persian digits + separators: ۲ مسدود, ۱ تیکت باز, ۱ نمایندهٔ فعال.
        $this->assertMatchesRegularExpression('/<div class="k">کل کاربران<\/div>\s*<div class="v">۳<\/div>/u', $html);
        $this->assertMatchesRegularExpression('/<div class="k">تیکتهای باز<\/div>\s*<div class="v">۱<\/div>/u', $html);
        $this->assertMatchesRegularExpression('/<div class="k">نمایندگان فعال<\/div>\s*<div class="v">۱<\/div>/u', $html);
        $this->assertStringContainsString('۲ مسدود', $html);
        $this->assertStringContainsString('موجودی کیف پول کل: ۱۰۰,۰۰۰', $html);
    }

    /** Buyers are distinct paying chat_ids; a loyalty gift is not a purchase. */
    public function test_loyalty_payments_are_excluded_from_the_buyers_count(): void
    {
        // One real card buyer, one loyalty-only "buyer", one pending, one
        // rejected — only the card buyer may count.
        $cardBuyer = $this->makeUser('810001');
        $this->makePayment($cardBuyer, ['price' => '10,000']);
        $this->makePayment($cardBuyer, ['price' => '5,000']); // same chat: still one buyer

        $loyaltyOnly = $this->makeUser('810002');
        $loyalty = $this->makePayment($loyaltyOnly, ['price' => '0']);
        Payment::query()->whereKey($loyalty->getKey())->update(['method' => 'loyalty']);

        $pending = $this->makeUser('810003');
        $this->makePayment($pending, ['is_paid' => null, 'price' => '9,000']);

        $rejected = $this->makeUser('810004');
        $this->makePayment($rejected, ['is_paid' => '0', 'price' => '9,000']);

        $html = $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()->getContent();

        // Exactly one buyer, and the card payments are what got them there.
        $this->assertMatchesRegularExpression('/<div class="k">مشتریان \(حداقل ۱ خرید موفق\)<\/div>\s*<div class="v">۱<\/div>/u', $html);
        // The loyalty-only chat is nowhere in the buyers figure's neighbourhood.
        $this->assertStringNotContainsString('۲ خرید موفق', $html);
    }

    /** The doughnut's data attributes carry the success/rejected/pending split. */
    public function test_the_status_doughnut_carries_the_payment_split(): void
    {
        $buyer = $this->makeUser('820001');

        // Two approved ('1'), one rejected ('0'), one pending (NULL), plus a
        // legacy '2' approval written through the query builder — the model's
        // is_paid mutator maps unknown markers to Pending, exactly as legacy
        // rows with '2' are read by the buyer parity rules.
        $this->makePayment($buyer, ['price' => '1,000']);
        $this->makePayment($buyer, ['price' => '2,000']);
        $legacy = $this->makePayment($buyer, ['price' => '3,000']);
        Payment::query()->whereKey($legacy->getKey())->update(['is_paid' => '2']);
        $this->makePayment($buyer, ['price' => '4,000', 'is_paid' => '0']);
        $this->makePayment($buyer, ['price' => '5,000', 'is_paid' => null]);

        $html = $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()->getContent();

        preg_match('/<canvas id="dashStatusChart".*?data-values="([^"]*)"/s', $html, $match);
        $this->assertNotFalse($match[1] ?? false, 'the status doughnut is missing');

        $values = json_decode(html_entity_decode($match[1]), true);

        // 1 + 1 + legacy '2' = 3 approved, 1 rejected, 1 pending.
        $this->assertSame([3, 1, 1], $values);

        preg_match('/<canvas id="dashStatusChart".*?data-labels="([^"]*)"/s', $html, $labels);
        $this->assertSame(
            ['پرداخت شده', 'رد شده', 'در انتظار'],
            json_decode(html_entity_decode($labels[1]), true),
        );
    }

    /** Top coupons: approved, non-loyalty, non-empty codes inside the window. */
    public function test_the_top_coupons_table_counts_uses_and_revenue(): void
    {
        $buyer = $this->makeUser('830001');

        // Two SAVE10 uses approved; a pending SAVE10 and an empty coupon must
        // not row up; an out-of-window SAVE10 leaves when range is 7.
        $this->makePayment($buyer, ['price' => '25,000', 'coupon' => 'SAVE10']);
        $this->makePayment($buyer, ['price' => '8,000', 'coupon' => 'SAVE10']);
        $this->makePayment($buyer, ['price' => '99,000', 'coupon' => 'SAVE10', 'is_paid' => null]);
        $this->makePayment($buyer, ['price' => '99,000', 'coupon' => '']);
        $this->makePayment($buyer, ['price' => '7,000', 'coupon' => 'OLD10', 'created_at' => now()->subDays(40)]);

        $html = $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('پراستفادهترین کدهای تخفیف', $html);
        // 2 uses × (25,000 + 8,000) = ۲ use, 33,000 revenue; Persian uses,
        // Latin money — the money columns stay number_format everywhere.
        $this->assertMatchesRegularExpression('/<td class="ltr">SAVE10<\/td>\s*<td>۲<\/td>\s*<td>33,000<\/td>/u', $html);
        // The 40-day-old coupon is outside the default seven-day window.
        $this->assertStringNotContainsString('OLD10', $html);
    }

    // -----------------------------------------------------------------
    // Range selector
    // -----------------------------------------------------------------

    /** range=30 re-queries both series with thirty slots and pins the select. */
    public function test_the_range_select_re_queries_the_series(): void
    {
        $tz = 'Asia/Tehran';

        // Ten days ago: invisible in the default 7-day window, present in 30.
        $old = $this->makeUser('840001');
        $old->forceFill(['created_at' => Carbon::now($tz)->subDays(10)])->save();
        $this->makePayment($old, ['price' => '44,000', 'created_at' => Carbon::now($tz)->subDays(10)]);

        // Default page: seven slots, no trace of the ten-day-old revenue.
        $default = $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()->getContent();
        preg_match('/<canvas id="dashRevenueChart".*?data-values="([^"]*)"/s', $default, $revenue);
        $seven = json_decode(html_entity_decode($revenue[1]), true);
        $this->assertCount(7, $seven, 'the default series must stay at 7 slots');
        $this->assertNotContains(44000, array_map('intval', $seven));

        // range=30: thirty slots, and the old payment lands at index 19
        // (29 days ago is index 0, so ten days ago is 30 - 1 - 10 = 19).
        $wide = $this->actingAsAdmin()->get(route('admin.dashboard', ['range' => 30]))->assertOk()->getContent();
        preg_match('/<canvas id="dashRevenueChart".*?data-values="([^"]*)"/s', $wide, $revenue30);
        $thirty = json_decode(html_entity_decode($revenue30[1]), true);
        $this->assertCount(30, $thirty, 'range=30 must span 30 days');
        $this->assertSame(44000, (int) $thirty[19]);

        preg_match('/<canvas id="dashUsersChart".*?data-values="([^"]*)"/s', $wide, $users30);
        $this->assertCount(30, json_decode(html_entity_decode($users30[1]), true), 'the users series follows the range too');

        // The selector pins the chosen option (Blade's @selected prints the
        // bare attribute, not selected="selected").
        $this->assertMatchesRegularExpression('/<option value="30" selected>/', $wide);

        // A junk range falls back to seven — the fork clamps likewise.
        $junk = $this->actingAsAdmin()->get(route('admin.dashboard', ['range' => 'nonsense']))->assertOk()->getContent();
        preg_match('/<canvas id="dashRevenueChart".*?data-values="([^"]*)"/s', $junk, $revenueJunk);
        $this->assertCount(7, json_decode(html_entity_decode($revenueJunk[1]), true));
    }
}
