<?php

declare(strict_types=1);

namespace App\Services\Purchase;

use App\Exceptions\TelegramApiException;
use App\Models\Client;
use App\Models\Payment;
use App\Models\User;
use App\Services\Connectix\ConnectixService;
use App\Services\Payment\PaymentService;
use App\Services\Plan\PlanService;
use App\Services\Telegram\TelegramService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turning a paid order into a working Connectix account.
 *
 * Port of the `accept` branch of `paycheck()`: create the client when the order
 * is for a new account, add the plan when it renews an existing one, store the
 * credentials locally, replace the 'new' placeholder with the real client id,
 * and tell the buyer.
 *
 * Both payment routes need this, so it lives here rather than inside either
 * flow: the wallet route completes immediately, the card route completes when
 * an admin accepts the receipt.
 *
 * The buyer is told about the account only after the panel work and the local
 * writes have both succeeded, so a message never promises an account that does
 * not exist.
 */
class ClientProvisioner
{
    public function __construct(
        private readonly ConnectixService $connectix,
        private readonly PlanService $plans,
        private readonly PaymentService $payments,
        private readonly TelegramService $telegram,
    ) {}

    /**
     * Provision whatever the order calls for, then notify the buyer.
     *
     * The result tells the caller which of the three things happened, so the
     * admin flow can answer with the right caption instead of guessing.
     */
    public function provision(Payment $payment): ProvisionResult
    {
        if ($this->payments->isDecided($payment)) {
            // Legacy answered a second approval with the current status and
            // changed nothing, which is what makes a double press harmless.
            return ProvisionResult::alreadyDecided($payment);
        }

        $plan = $this->plans->findSellableById((string) $payment->plan_id);

        if ($plan === null) {
            Log::error('The plan on the order is no longer offered by the panel.', [
                'payment_id' => $payment->id,
                'plan_id' => $payment->plan_id,
            ]);

            return ProvisionResult::failed($payment, 'The plan is no longer offered.');
        }

        $user = $this->payments->buyer($payment);

        if ($user === null) {
            Log::error('The order has no buyer to provision for.', [
                'payment_id' => $payment->id,
                'chat_id' => $payment->chat_id,
            ]);

            return ProvisionResult::failed($payment, 'The buyer is unknown.');
        }

        // Captured before the panel work: creating the account replaces the
        // 'new' placeholder with the real id, and the success message has to
        // describe the operation that was ordered, not the final column value.
        $isNewAccount = $payment->isNewClient();

        try {
            $client = $isNewAccount
                ? $this->createAccount($payment, $user, $plan)
                : $this->renewAccount($payment, $plan);
        } catch (\Throwable $e) {
            Log::error('Provisioning failed for a paid order.', [
                'payment_id' => $payment->id,
                'client_id' => $payment->client_id,
                'error' => $e->getMessage(),
            ]);

            $this->notifyFailure($payment);

            return ProvisionResult::failed($payment, $e->getMessage());
        }

        // Legacy marked the order paid after the account was ready, so a
        // panel failure leaves the order open for a retry instead of quietly
        // recording a purchase that did not happen.
        $this->payments->markPaid((int) $payment->id);

        $this->notifySuccess($payment, $user, $client, $isNewAccount);

        return ProvisionResult::provisioned($payment, $user, $client);
    }

    /**
     * Create a brand new client and store its credentials.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed> The panel's view of the new client.
     */
    private function createAccount(Payment $payment, User $user, array $plan): array
    {
        $response = $this->connectix->createClient(
            name: $user->name,
            chatId: $user->chat_id,
            telegramUsername: $user->telegram_id,
            planId: (string) $plan['id'],
        );

        $clientId = (string) ($response['client_id'] ?? '');

        if ($clientId === '') {
            throw new \RuntimeException('The panel did not return a client id.');
        }

        // The panel is the source of truth for the generated credentials, so
        // the record is read back rather than assembled from the create call.
        $client = $this->connectix->getClientData($clientId) ?? $response;

        $this->storeClient($clientId, $user, $client);
        $this->payments->attachClientId((int) $payment->id, $clientId);

        $payment->forceFill(['client_id' => $clientId])->syncOriginal();

        return $client;
    }

    /**
     * Add the plan to an existing client.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed> The refreshed client.
     */
    private function renewAccount(Payment $payment, array $plan): array
    {
        $clientId = (string) $payment->client_id;

        $this->connectix->addPlanToClient($clientId, (string) $plan['id']);

        $client = $this->connectix->getClientData($clientId) ?? [];

        // Legacy left the local device count stale after a renewal. The panel
        // is asked for the same plan list the buyer is about to be shown, so
        // keeping the local column in step only removes a wrong number.
        if (isset($client['count_of_devices'])) {
            Client::query()
                ->whereKey($clientId)
                ->update(['count_of_devices' => (int) $client['count_of_devices']]);
        }

        return $client;
    }

    /**
     * Write the provisioned client into the local `clients` table.
     *
     * @param  array<string, mixed>  $client
     */
    private function storeClient(string $clientId, User $user, array $client): void
    {
        DB::transaction(function () use ($clientId, $user, $client): void {
            Client::query()->updateOrCreate(
                ['id' => $clientId],
                [
                    'count_of_devices' => (int) ($client['count_of_devices'] ?? 0),
                    'username' => (string) ($client['username'] ?? ''),
                    'password' => (string) ($client['password'] ?? ''),
                    'chat_id' => (string) $user->chat_id,
                    'user_id' => $user->id,
                    'created_at' => now(),
                ],
            );
        });
    }

    // -----------------------------------------------------------------
    // Messages
    // -----------------------------------------------------------------

    /**
     * Tell the buyer the account is ready.
     *
     * Port of the two `paycheck()` success messages, including the order
     * number line that only a renewal carries.
     *
     * @param  array<string, mixed>  $client
     */
    private function notifySuccess(Payment $payment, User $user, array $client, bool $isNewAccount): void
    {
        $username = (string) ($client['username'] ?? '');
        $password = (string) ($client['password'] ?? '');
        $planName = $this->currentPlanName($client, $payment);

        if ($isNewAccount) {
            $sublink = (string) ($client['subscription_link'] ?? '');

            $body = "\n\n👤 نام کاربری: <code>{$username}</code>\n🔑 رمز عبور: <code>{$password}</code>\n📦 پلن:\n{$planName}\n";

            if ($sublink !== '') {
                $body .= "\n🔗 لینک سابسکریبشن: <code>{$sublink}</code>";
            }

            $text = 'اکانت شما با موفقیت ایجاد شد.'.$body;
        } else {
            $body = "\n\n👤 نام کاربری: <code>{$username}</code>\n📦 پلن:\n {$planName}";
            $text = "اکانت شما با موفقیت تمدید شد.\n\n🛍 شماره سفارش: <code>{$payment->order_number}</code>\n".$body;
        }

        $this->send((string) $user->chat_id, $text);
    }

    /**
     * Tell the buyer that provisioning failed.
     *
     * Legacy logged the panel error and stopped, which left the user with a
     * debit and no explanation. A message is sent instead, because the money
     * has already been taken and silence reads as a bug.
     */
    private function notifyFailure(Payment $payment): void
    {
        $text = "❌ ساخت اکانت شما با خطا مواجه شد.\n"
            ."شماره سفارش: <code>{$payment->order_number}</code>\n\n"
            .'مبلغ پرداختی شما نزد ما باقی می‌ماند و پس از بررسی، برای ساخت اکانت استفاده خواهد شد. '
            .'لطفاً برای پیگیری با پشتیبانی تماس بگیرید.';

        $this->send((string) $payment->chat_id, $text);
    }

    /**
     * Send an HTML message, swallowing a Telegram outage.
     *
     * The account exists by this point, so a failed notification must not turn
     * a completed purchase into a reported failure.
     */
    private function send(string $chatId, string $text): void
    {
        $keyboard = json_encode([
            'inline_keyboard' => [
                [['text' => '📦 | اکانت های من', 'callback_data' => 'accounts']],
                [['text' => '🏡 | خانه', 'callback_data' => 'main_menu']],
            ],
        ], JSON_UNESCAPED_UNICODE);

        try {
            $this->telegram->sendMessage($chatId, $text, [
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard,
            ]);
        } catch (TelegramApiException $e) {
            Log::warning('Could not deliver the account message to the buyer.', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The plan label shown to the buyer.
     *
     * Legacy read `plans[0]` off the client and parsed its name. The ordered
     * plan is used as a fallback, because a response without plans would
     * otherwise render a blank line.
     *
     * @param  array<string, mixed>  $client
     */
    private function currentPlanName(array $client, Payment $payment): string
    {
        $plans = $client['plans'] ?? [];

        if (is_array($plans) && isset($plans[0]['name'])) {
            return (string) ($this->plans->parsePlanTitle((string) $plans[0]['name'])['text'] ?? '');
        }

        $plan = $this->plans->findSellableById((string) $payment->plan_id);

        if ($plan !== null) {
            return (string) ($this->plans->parsePlanTitle((string) ($plan['title'] ?? ''))['text'] ?? '');
        }

        return (string) $payment->plan_id;
    }
}
