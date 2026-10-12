<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\PaymentMethod;
use App\Models\Admin;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The dashboard's numbers, not its chrome: comma-formatted prices must sum
 * whole, a points-reward order must read «جایزه امتیاز», a zero previous
 * period must print «—» rather than a percentage, and the two chart series
 * must reach the page as 7-slot data attributes for Chart.js.
 */
class AdminDashboardChartsTest extends TestCase
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

    private function makeUser(string $chatId, ?string $name = 'Ali', ?Carbon $createdAt = null): User
    {
        return User::query()->create([
            'chat_id' => $chatId,
            'telegram_id' => 'tg_'.$chatId,
            'name' => $name,
            'created_at' => $createdAt ?? now(),
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

    /** The price column stores "25,000"; the sum must not stop at the comma. */
    public function test_comma_formatted_prices_sum_whole(): void
    {
        $buyer = $this->makeUser('700001');

        $this->makePayment($buyer, ['price' => '25,000', 'created_at' => Carbon::now('Asia/Tehran')]);
        $this->makePayment($buyer, ['price' => '12,500', 'created_at' => Carbon::now('Asia/Tehran')]);
        // A plain price too, and one yesterday (must not land in today's sum).
        $this->makePayment($buyer, ['price' => '1000', 'created_at' => Carbon::now('Asia/Tehran')]);
        $this->makePayment($buyer, ['price' => '99,999', 'created_at' => Carbon::now('Asia/Tehran')->subDay()]);

        $html = $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()->getContent();

        // 25,000 + 12,500 + 1,000 = 38,500 for today — not 25 + 12 + 1.
        $this->assertStringContainsString('38,500', $html);
    }

    /** A loyalty order is the points-reward purchase; its method reads Persian. */
    public function test_a_loyalty_order_is_labelled_as_points_reward(): void
    {
        $buyer = $this->makeUser('700002');

        // Legacy rows can carry a method outside the enum; the enum cast
        // refuses it on write, so the row goes in the way legacy data does -
        // through the query builder, not the model.
        $payment = $this->makePayment($buyer, [
            'price' => '0',
            'created_at' => Carbon::now('Asia/Tehran'),
        ]);
        Payment::query()->whereKey($payment->getKey())->update(['method' => 'loyalty']);

        $this->actingAsAdmin()
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('جایزه امتیاز');
    }

    /** An empty previous period shows «—», never a fabricated percentage. */
    public function test_a_zero_previous_period_shows_a_dash(): void
    {
        $buyer = $this->makeUser('700003');

        // Revenue exists today, but nothing yesterday and nothing this month
        // before now worth comparing: every baseline is zero.
        $this->makePayment($buyer, ['price' => '5,000', 'created_at' => Carbon::now('Asia/Tehran')]);

        $html = $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('—', $html);
        // The misleading forms the legacy fix retired must not appear.
        $this->assertStringNotContainsString('+100.0%', $html);
        $this->assertStringNotContainsString('+۱۰۰٪', $html);
    }

    /** Both chart series are 7 Tehran-day slots riding along as data attributes. */
    public function test_the_chart_series_are_seven_days_of_data_attributes(): void
    {
        $tz = 'Asia/Tehran';

        // Two users today, one three days ago; revenue on two of the days.
        $this->makeUser('700004', 'Today One', Carbon::now($tz));
        $this->makeUser('700005', 'Today Two', Carbon::now($tz));
        $this->makeUser('700006', 'Midweek', Carbon::now($tz)->subDays(3));

        // Payment owners too: their created_at is pinned or they would also
        // count as "new users today" in the users series.
        $this->makePayment($this->makeUser('700007', 'Buyer A', Carbon::now($tz)->subDays(5)), [
            'price' => '7,000',
            'created_at' => Carbon::now($tz),
        ]);
        $this->makePayment($this->makeUser('700008', 'Buyer B', Carbon::now($tz)->subDays(5)), [
            'price' => '3,000',
            'created_at' => Carbon::now($tz)->subDays(2),
        ]);

        $html = $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()->getContent();

        foreach (['dashRevenueChart' => 'revenue', 'dashUsersChart' => 'users'] as $id => $kind) {
            $this->assertMatchesRegularExpression(
                '/<canvas id="'.$id.'"[^>]*data-chart="'.$kind.'"/s',
                $html,
                $id.' must carry its data-chart kind',
            );
        }

        preg_match('/<canvas id="dashRevenueChart".*?data-values="([^"]*)"/s', $html, $revenue);
        preg_match('/<canvas id="dashUsersChart".*?data-values="([^"]*)"/s', $html, $users);

        $this->assertNotFalse($revenue[1] ?? false, 'the revenue series is missing');
        $this->assertNotFalse($users[1] ?? false, 'the users series is missing');

        $revenueValues = json_decode(html_entity_decode($revenue[1]), true);
        $usersValues = json_decode(html_entity_decode($users[1]), true);

        $this->assertCount(7, $revenueValues, 'the revenue series must span exactly 7 days');
        $this->assertCount(7, $usersValues, 'the users series must span exactly 7 days');

        // Oldest first: today sits last, so 2 days ago is index 4.
        $this->assertSame(7000, (int) end($revenueValues));
        $this->assertSame(3000, (int) $revenueValues[4]);
        $this->assertSame(0, (int) $revenueValues[0]);

        $this->assertSame(2, (int) end($usersValues));
        // 3 days ago sits at index 3 of a 7-slot series (index 6 is today).
        $this->assertSame(1, (int) $usersValues[3]);
        $this->assertSame(0, (int) $usersValues[0]);

        // The label list rides along next to the values, also 7 slots.
        preg_match('/<canvas id="dashUsersChart".*?data-labels="([^"]*)"/s', $html, $userLabels);
        $this->assertCount(7, json_decode(html_entity_decode($userLabels[1]), true));
    }

    /** Chart.js loads from the local vendor path — CSP allows nothing else. */
    public function test_chart_js_is_loaded_from_the_local_vendor_path(): void
    {
        $this->actingAsAdmin()
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('src="'.asset('vendor/chart.umd.min.js').'"', false);
    }
}
