<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\SmsPaymentType;
use App\Models\SmsPayment;
use App\Services\Panel\PanelSettingsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Inbound bank SMS deposits, and the auto-payment matching built on them.
 *
 * Port of the `smsPayment()` helper in functions.php plus the amount parsing
 * in bank/sms.php.
 *
 * The flow legacy implemented, and this service reproduces, is:
 *
 *  1. the bank gateway POSTs a raw SMS to a webhook;
 *  2. the amount is pulled out of the message with the configured bank's
 *     pattern and converted from Rial to Toman;
 *  3. the message is stored for five minutes, unmatched;
 *  4. the next card receipt or wallet deposit for exactly that amount claims
 *     it, and the row is linked to the order it paid for.
 *
 * Nothing polls: a deposit is only ever matched at the moment a receipt
 * arrives, so the whole feature works on a plain webhook install.
 */
class SmsPaymentService
{
    public function __construct(
        private readonly PanelSettingsService $settings,
    ) {}

    /**
     * How long an unmatched deposit stays claimable, in minutes.
     *
     * Legacy wrote `DATE_ADD(NOW(), INTERVAL 5 MINUTE)`.
     */
    private const MATCH_WINDOW_MINUTES = 5;

    /**
     * Whether a bank is configured, which is what switches auto-payment on.
     *
     * Legacy read the same thing as `$bot_config->bank->name ? true : false`.
     */
    public function isEnabled(): bool
    {
        return $this->bankName() !== null;
    }

    /**
     * The configured bank key, or null when no bank is set up.
     */
    public function bankName(): ?string
    {
        $name = trim($this->settings->text('connectix_bot.bank.name', 'bank.name'));

        return $name === '' ? null : $name;
    }

    /**
     * Whether the gateway should be told about a deposit as it arrives.
     */
    public function botNotice(): bool
    {
        return $this->settings->flag('connectix_bot.bank.bot_notice', 'bank.bot_notice', true);
    }

    /**
     * The chat id legacy sent the gateway notice to.
     *
     * Legacy used the first administrator only. The notice is informational,
     * so the first configured administrator is the faithful choice.
     */
    public function noticeRecipient(): ?string
    {
        /** @var array<int, string> $ids */
        $ids = (array) config('connectix_bot.admin_ids', []);

        return $ids === [] ? null : (string) $ids[0];
    }

    /**
     * Extract the deposit amount from a raw SMS, in Toman.
     *
     * Port of the parsing in bank/sms.php: the bank's first capturing group
     * holds the amount in Rial with thousands separators, which is stripped
     * and then divided by ten.
     *
     * Null means the message is not a deposit this bot understands, which the
     * webhook reports as "amount not found".
     */
    public function parseAmount(string $message): ?int
    {
        $pattern = $this->bankPattern();

        if ($pattern === null) {
            return null;
        }

        // The pattern comes from reseller configuration, so a malformed one is
        // a configuration mistake rather than a bug: it is reported as "not
        // found" instead of emitting a warning on every gateway call.
        $matched = @preg_match($pattern, $message, $matches);

        if ($matched !== 1 || ! isset($matches[1])) {
            return null;
        }

        $rial = str_replace(',', '', (string) $matches[1]);

        if (! is_numeric($rial)) {
            return null;
        }

        // Integer division, exactly as legacy converted Rial to Toman. A
        // deposit that divides down to zero is not usable and is reported as
        // "not found" by the caller refusing a non-positive amount.
        return (int) ($rial / 10);
    }

    /**
     * The PCRE pattern of the configured bank, or null when it has none.
     */
    public function bankPattern(): ?string
    {
        $name = $this->bankName();

        if ($name === null) {
            return null;
        }

        $banks = (array) config('connectix_bot.bank.banks', []);

        $method = $banks[$name]['method'] ?? null;

        if (! is_string($method) || trim($method) === '') {
            Log::error('The configured bank has no amount pattern.', ['bank' => $name]);

            return null;
        }

        return $method;
    }

    /**
     * Store a deposit for later matching.
     *
     * Port of the `save` branch of `smsPayment()`.
     */
    public function record(string $message, int $amount, ?string $bank = null): ?SmsPayment
    {
        $this->pruneExpired();

        return SmsPayment::query()->create([
            'message' => $message,
            'amount' => $amount,
            'bank' => $bank ?? $this->bankName(),
            // Matched rows keep the column; the row only expires while it is
            // still waiting for an order, which is what makes the prune below
            // safe to run on every request.
            'payment_id' => null,
            'payment_type' => null,
            'expired_at' => now()->addMinutes(self::MATCH_WINDOW_MINUTES),
            'created_at' => now(),
        ]);
    }

    /**
     * The deposit that can pay for an amount of this size, if there is
     * exactly one.
     *
     * Port of the `check` branch: an unmatched, unexpired row with the same
     * amount. Two candidates for one amount is treated as no match at all,
     * because either one could be the customer's real transfer and guessing
     * would credit the wrong order.
     */
    public function claim(int $amount): ?SmsPayment
    {
        $this->pruneExpired();

        $matches = $this->candidates($amount);

        if ($matches->count() !== 1) {
            return null;
        }

        return $matches->first();
    }

    /**
     * Link a claimed deposit to the order it paid for.
     *
     * Port of the `pay` branch. The write is conditional on the row still
     * being unmatched, so a deposit that two checkouts raced for is only ever
     * linked once.
     */
    public function link(SmsPayment $sms, int|string $paymentId, SmsPaymentType $type): bool
    {
        $linked = SmsPayment::query()
            ->whereKey($sms->getKey())
            ->whereNull('payment_id')
            ->update([
                'payment_id' => (string) $paymentId,
                'payment_type' => $type->value,
            ]);

        if ($linked === 0) {
            Log::warning('A bank SMS deposit was already matched to another order.', [
                'sms_id' => $sms->getKey(),
                'payment_id' => (string) $paymentId,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Delete deposits nobody claimed before their window elapsed.
     *
     * Port of the cleanup `smsPayment()` ran on every call:
     * `DELETE FROM sms_payments WHERE expired_at < NOW() AND payment_id IS
     * NULL`. Matched rows are kept, because they are the audit trail of what
     * paid for which order.
     */
    public function pruneExpired(): int
    {
        return SmsPayment::query()
            ->whereNull('payment_id')
            ->where('expired_at', '<', now())
            ->delete();
    }

    /**
     * The deposits that could pay this amount right now.
     *
     * @return Collection<int, SmsPayment>
     */
    private function candidates(int $amount): Collection
    {
        return SmsPayment::query()
            ->available()
            ->where('amount', $amount)
            ->get();
    }
}
