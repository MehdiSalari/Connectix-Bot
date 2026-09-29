<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bank;

use App\Exceptions\TelegramApiException;
use App\Http\Controllers\Controller;
use App\Services\Payment\SmsPaymentService;
use App\Services\Telegram\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Bank gateway webhook for inbound transfer SMS.
 *
 * Replaces `bank/sms.php`: the gateway POSTs the raw message, the amount is
 * extracted with the configured bank's pattern, and the deposit is stored for
 * the SMS auto-payment matching. No order is decided here; that only happens
 * when a receipt for that amount arrives.
 *
 * The response codes are the ones legacy returned, so an existing gateway
 * integration keeps working unchanged:
 *
 *  - 202 Accepted with the parsed amount on a stored deposit;
 *  - 400 for a missing message, a non-positive amount, or no bank configured;
 *  - 405 for anything but POST (enforced by the route);
 *  - 406 for a non-JSON body;
 *  - 500 when the message does not match the bank's pattern.
 */
class BankSmsController extends Controller
{
    public function __construct(
        private readonly SmsPaymentService $sms,
        private readonly TelegramService $telegram,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $contentType = strtolower(trim((string) $request->header('Content-Type')));

        if (! in_array($contentType, ['application/json', 'application/json; charset=utf-8'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Content type must be application/json or application/json; charset=utf-8',
            ], 406);
        }

        // The gateway posts to a fixed URL anyone can reach. An unset secret
        // keeps the legacy contract (the old bank integration sends nothing
        // extra); a set one must arrive in the header, compared without a
        // timing tell.
        $secret = (string) config('connectix_bot.bank.secret', '');

        if ($secret !== '') {
            $provided = (string) $request->header('X-Bank-Sms-Secret', '');

            if (! hash_equals($secret, $provided)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized',
                ], 403);
            }
        }

        $message = trim((string) $request->input('msg'));

        if ($message === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Missing required field [msg]',
            ], 400);
        }

        $bank = $this->sms->bankName();

        if ($bank === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bank method not configured properly, please check admin panel settings.',
            ], 400);
        }

        $amount = $this->sms->parseAmount($message);

        if ($amount === null) {
            Log::error('Failed to extract amount from bank SMS.', [
                'bank' => $bank,
                // A stranger's card and phone numbers are in this text; the
                // log file on a shared host has no business holding them.
                'message' => $this->safeSmsBody($message),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Amount not found in message',
                'bank' => $bank,
            ], 500);
        }

        if ($amount <= 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Amount must be greater than 0',
                'bank' => $bank,
            ], 400);
        }

        $deposit = $this->sms->record($message, $amount, $bank);

        // A repeated delivery inside the match window is answered exactly like a
        // first one, so a retrying gateway sees the success it expects. It is
        // not stored twice, and the administrator is not notified again.
        if ($deposit !== null && $this->sms->botNotice()) {
            $this->notifyAdmin($bank, $amount, $message);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'amount' => number_format($amount),
                'bank' => $bank,
            ],
        ], 202);
    }

    /**
     * The raw SMS, minus what a stranger would not want in a log file.
     *
     * Runs of six or more digits are card, account or phone numbers; the last
     * four stay so a support question can still be correlated, and the body is
     * cut short because only the shape of the message explains a pattern miss.
     */
    private function safeSmsBody(string $message): string
    {
        $masked = (string) preg_replace_callback(
            '/\d{6,}/',
            static fn (array $m): string => '***'.substr($m[0], -4),
            $message,
        );

        return mb_strlen($masked) > 120 ? mb_substr($masked, 0, 120).'…' : $masked;
    }

    /**
     * The optional Telegram notice to the first administrator, port of the
     * `bot_notice` branch of `bank/sms.php`.
     */
    private function notifyAdmin(string $bank, int $amount, string $message): void
    {
        $recipient = $this->sms->noticeRecipient();

        if ($recipient === null) {
            return;
        }

        $text = "💰 <b>New SMS Payment Received</b>\n\n"
            .'🏦 <b>Bank:</b> '.htmlspecialchars($bank)."\n"
            .'💵 <b>Amount:</b> '.number_format($amount)." Toman\n\n"
            ."<i>Message:</i>\n".htmlspecialchars($message);

        try {
            $this->telegram->sendMessage($recipient, $text, [
                'parse_mode' => 'HTML',
            ]);
        } catch (TelegramApiException $e) {
            Log::warning('Could not send the bank SMS notice to the administrator.', [
                'admin_id' => $recipient,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
