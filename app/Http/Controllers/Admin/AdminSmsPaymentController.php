<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\SmsPaymentType;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\SmsPayment;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Panel\PanelSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Inbound bank SMS deposits.
 *
 * Port of the legacy transactions/sms_payments.php list: the list itself, the
 * bank and date filters around it, and the per-row deposit sheet.
 */
class AdminSmsPaymentController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $bank = trim((string) $request->query('bank', ''));
        $from = $this->dateOrNull($request->query('from'));
        $to = $this->dateOrNull($request->query('to'));

        // The message body is written with Persian digits while `amount` is a
        // Latin number, so one query is matched in all of its shapes: whatever
        // the admin types, both the text and the amount get their chance.
        $latin = $this->toLatinDigits($search);
        $variants = array_values(array_unique(array_filter([$search, $latin, $this->toPersianDigits($search)])));
        $digits = preg_replace('/\D+/', '', $latin) ?? '';

        $deposits = SmsPayment::query()
            ->when($bank !== '', fn ($query) => $query->where('bank', $bank))
            ->when($from !== null, fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('created_at', '<=', $to))
            ->when($search !== '', function ($query) use ($variants, $digits): void {
                // One wrapping where(): without it an orWhere would escape the
                // bank and date clauses above it and match everything.
                $query->where(function ($nested) use ($variants, $digits): void {
                    foreach ($variants as $variant) {
                        $nested->orWhere('message', 'like', "%{$variant}%")
                            ->orWhere('bank', 'like', "%{$variant}%")
                            ->orWhere('payment_id', 'like', "%{$variant}%");
                    }
                    if ($digits !== '') {
                        $nested->orWhere('amount', 'like', "%{$digits}%");
                    }
                });
            })
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        // Only the banks that are actually in the table: an option list of
        // every bank the product supports would offer filters that match
        // nothing.
        $banks = SmsPayment::query()
            ->whereNotNull('bank')
            ->distinct()
            ->orderBy('bank')
            ->pluck('bank')
            ->values();

        return view('admin.sms-payments.index', [
            'appName' => app(PanelSettingsService::class)->appName(),
            'deposits' => $deposits,
            'search' => $search,
            'bank' => $bank,
            'from' => $from,
            'to' => $to,
            'banks' => $banks,
        ]);
    }

    /**
     * Everything the deposit sheet shows, for one row.
     *
     * The row itself answers "how much, from where, when"; the buyer is only
     * reachable through what the deposit was matched to - `payment_id` points
     * at `payments` for a purchase and at `wallet_transactions` for a wallet
     * top-up, and `payment_type` is what tells the two apart, because their
     * ids come from different tables and guessing would collide. The purchase
     * card is the order sheet's own data and is fetched through its endpoint
     * instead of being assembled a second time here.
     *
     * The parameter must be named after the route wildcard ({smsPayment}) or
     * the container resolves an empty model instead of binding the row.
     */
    public function details(SmsPayment $smsPayment): JsonResponse
    {
        $matched = $smsPayment->isMatched();
        $payment = null;
        $transaction = null;

        if ($matched) {
            if ($smsPayment->type() === SmsPaymentType::Wallet) {
                $transaction = WalletTransaction::query()->find($smsPayment->payment_id);
            } else {
                $payment = Payment::query()->find($smsPayment->payment_id);
            }
        }

        $chatId = $payment?->chat_id ?? $transaction?->chat_id;
        $user = $chatId === null
            ? null
            : User::query()->where('chat_id', (string) $chatId)->first();

        [$label, $tone] = $smsPayment->status();

        return response()->json([
            'deposit' => [
                'amount_text' => number_format($smsPayment->amount),
                'status_label' => $label,
                'status_tone' => $tone,
                'created_at' => $smsPayment->created_at?->format('Y-m-d H:i'),
                'type_label' => $smsPayment->typeLabel(),
                'bank' => $smsPayment->bank,
            ],
            'user' => $user === null ? null : [
                'name' => $user->name,
                'chat_id' => $user->chat_id,
                'telegram' => $user->telegram_id,
                'profile_url' => route('admin.users.show', $user),
            ],
            'message' => $smsPayment->message,
            'order_endpoint' => $payment === null ? null : route('admin.orders.details', $payment),
        ]);
    }

    /** A date input's value, or null when it is empty or not a real date. */
    private function dateOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $date = \DateTime::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function toLatinDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    private function toPersianDigits(string $value): string
    {
        return strtr($value, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }
}
