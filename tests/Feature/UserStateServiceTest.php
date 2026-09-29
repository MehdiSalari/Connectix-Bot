<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\User\UserStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The conversation state is stored as a JSON blob in a single column, so every
 * key name and every reset is a compatibility contract with the legacy bot.
 *
 * @covers \App\Services\User\UserStateService
 */
class UserStateServiceTest extends TestCase
{
    use RefreshDatabase;

    private UserStateService $states;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->states = new UserStateService;
        $this->user = User::query()->create(['chat_id' => '555']);
    }

    public function test_an_empty_column_reads_as_null(): void
    {
        $this->assertNull($this->states->get($this->user));
        $this->assertNull($this->states->getValue($this->user, 'plan'));
    }

    public function test_a_malformed_blob_reads_as_null_instead_of_blowing_up(): void
    {
        $this->user->forceFill(['action' => 'not json at all'])->save();

        $this->assertNull($this->states->get($this->user));
    }

    public function test_it_round_trips_the_state(): void
    {
        $this->states->set($this->user, [
            'action' => UserStateService::ACTION_PAY,
            'step' => 'pay',
            'pay' => 'card',
            'plan' => '42',
        ]);

        $state = $this->states->get($this->user->refresh());

        $this->assertSame('pay', $state['action']);
        $this->assertSame('42', $state['plan']);
        $this->assertSame('42', $this->states->getValue($this->user, 'plan'));
        $this->assertNull($this->states->getValue($this->user, 'coupon_code'));
    }

    /**
     * The blob is JSON, so persian group names and coupon codes survive a round
     * trip instead of arriving as escaped ascii.
     */
    public function test_unicode_is_stored_readable(): void
    {
        $this->states->set($this->user, ['action' => 'buy', 'group' => 'بیزینس ساب‌لینک']);

        $this->user->refresh();

        $this->assertStringContainsString('بیزینس ساب‌لینک', (string) $this->user->action);
        $this->assertSame('بیزینس ساب‌لینک', $this->states->getValue($this->user, 'group'));
    }

    public function test_update_merges_and_keeps_untouched_keys(): void
    {
        $this->states->set($this->user, ['action' => 'buy', 'group' => 'default', 'plan' => '7']);

        $state = $this->states->update($this->user, ['plan' => '8', 'coupon_code' => 'SAVE10']);

        $this->assertSame('default', $state['group']);
        $this->assertSame('8', $state['plan']);
        $this->assertSame('SAVE10', $state['coupon_code']);
    }

    public function test_clear_resets_the_column(): void
    {
        $this->states->set($this->user, ['action' => 'pay']);
        $this->states->clear($this->user);

        $this->assertNull($this->user->refresh()->action);
        $this->assertNull($this->states->get($this->user));
    }

    public function test_starting_a_purchase_records_the_group_and_the_new_account_sentinel(): void
    {
        $this->states->startPurchase($this->user, 'Sublink');

        $this->assertSame('buy', $this->states->getValue($this->user, 'action'));
        $this->assertSame('Sublink', $this->states->getValue($this->user, 'group'));
        $this->assertSame('new', $this->states->getValue($this->user, 'acc'));
    }

    public function test_starting_a_renewal_records_the_account(): void
    {
        $this->states->startRenewal($this->user, 'ali_vpn');

        $this->assertSame('renew', $this->states->getValue($this->user, 'action'));
        $this->assertSame('ali_vpn', $this->states->getValue($this->user, 'acc'));
    }

    /**
     * Moving to the payment step must carry the chosen plan and account over,
     * otherwise the receipt could not be matched to an order.
     */
    public function test_starting_a_payment_carries_the_purchase_context_forward(): void
    {
        $this->states->startPurchase($this->user, 'Economic');
        $this->states->update($this->user, ['plan' => '99', 'price' => '159,000']);
        $this->states->startPayment($this->user, 'card');

        $this->assertSame('pay', $this->states->getValue($this->user, 'action'));
        $this->assertSame('card', $this->states->getValue($this->user, 'pay'));
        $this->assertSame('99', $this->states->getValue($this->user, 'plan'));
        $this->assertSame('Economic', $this->states->getValue($this->user, 'group'));
        $this->assertSame('159,000', $this->states->getValue($this->user, 'price'));
    }

    public function test_awaiting_receipt_is_true_only_inside_a_card_payment(): void
    {
        $this->assertFalse($this->states->awaitingReceipt($this->user));

        $this->states->startPayment($this->user, 'card');
        $this->assertTrue($this->states->awaitingReceipt($this->user));
    }

    /**
     * The coupon flow reuses `pay` internally, but the next thing it expects is
     * a code, not a photo. Legacy excluded it explicitly and so must we.
     */
    public function test_awaiting_receipt_is_false_during_a_discount_flow(): void
    {
        $this->states->set($this->user, [
            'action' => UserStateService::ACTION_DISCOUNT,
            'pay' => 'card',
        ]);

        $this->assertFalse($this->states->awaitingReceipt($this->user));
    }

    public function test_starting_a_wallet_deposit_resets_the_amount(): void
    {
        $this->states->startWalletIncrease($this->user);

        $this->assertSame('wallet_increase', $this->states->getValue($this->user, 'action'));
        $this->assertNull($this->states->getValue($this->user, 'amount'));
    }

    public function test_starting_add_account_remembers_a_preset_username(): void
    {
        $this->states->startAddAccount($this->user, 'ali_vpn');

        $this->assertSame('add_account', $this->states->getValue($this->user, 'action'));
        $this->assertSame('ali_vpn', $this->states->getValue($this->user, 'username'));
    }
}
