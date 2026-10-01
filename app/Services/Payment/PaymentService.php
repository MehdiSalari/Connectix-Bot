<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Order persistence and payment status transitions.
 *
 * Port of `savePayment()`, of the already-decided guard at the top of both
 * `paycheck()` branches, and of the admin payment search query.
 *
 * This service owns the database half only. Provisioning the Connectix
 * account and the Telegram messages belong to the purchase and payment flows,
 * which is why nothing here calls the panel.
 */
class PaymentService
{
    /**
     * Columns the admin search matches, in the order legacy listed them.
     *
     * @var array<int, string>
     */
    private const SEARCHABLE_PAYMENT_COLUMNS = [
        'order_number',
        'chat_id',
        'price',
        'coupon',
        'client_id',
        'plan_id',
    ];

    // -----------------------------------------------------------------
    // Creation
    // -----------------------------------------------------------------

    /**
     * Record an order and assign its order number.
     *
     * Port of `savePayment()`. The order number is `CX{yy}{mm}{dd}{NN}` where
     * NN counts the day's orders, so it is derived after the row exists.
     *
     * Legacy counted the day with a LIKE query and then updated the row, with
     * no lock in between: two orders created in the same second could read the
     * same count and be handed the same number. Here the count and the update
     * run inside a transaction while holding a lock on the table, so the
     * numbers stay unique. The numbers produced for a single request are
     * identical to the ones legacy produced.
     */
    public function create(
        string|int $chatId,
        string $clientId,
        int|string|null $planId,
        int|string $price,
        PaymentMethod $method,
        ?PaymentStatus $status = null,
        ?string $coupon = null,
    ): ?Payment {
        try {
            return DB::transaction(function () use ($chatId, $clientId, $planId, $price, $method, $status, $coupon): ?Payment {
                $payment = Payment::query()->create([
                    'chat_id' => (string) $chatId,
                    'client_id' => $clientId,
                    'plan_id' => (string) $planId,
                    'price' => (string) $price,
                    'coupon' => $coupon,
                    'is_paid' => $status,
                    'method' => $method,
                    'created_at' => now(),
                ]);

                $payment->forceFill([
                    'order_number' => $this->nextOrderNumber(),
                ])->save();

                return $payment;
            });
        } catch (QueryException $e) {
            Log::error('Failed to insert payment.', [
                'chat_id' => (string) $chatId,
                'plan_id' => (string) $planId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The order number for the next order created today.
     *
     * `CX` + two digit year + month + day + a two digit sequence. The prefix
     * is built from the same `date()` calls legacy used.
     */
    public function nextOrderNumber(): string
    {
        $prefix = sprintf('CX%s%s%s', date('y'), date('m'), date('d'));

        $count = Payment::query()
            ->where('order_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->count() + 1;

        return $prefix.str_pad((string) $count, 2, '0', STR_PAD_LEFT);
    }

    // -----------------------------------------------------------------
    // Lookup
    // -----------------------------------------------------------------

    public function find(int $paymentId): ?Payment
    {
        return Payment::query()->find($paymentId);
    }

    public function findByOrderNumber(string $orderNumber): ?Payment
    {
        return Payment::query()->where('order_number', $orderNumber)->first();
    }

    /**
     * Every order for a chat, newest first.
     *
     * @return Collection<int, Payment>
     */
    public function forChat(string|int $chatId, int $limit = 50)
    {
        return Payment::query()
            ->where('chat_id', (string) $chatId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Admin search across the columns legacy matched, joined to the user's
     * name and Telegram id.
     *
     * A blank query returns everything paginated, which is the third branch of
     * the legacy query builder.
     *
     * @return Collection<int, Payment>
     */
    public function search(?string $query = null, int $page = 1, int $perPage = 20)
    {
        $builder = $this->searchQuery($query);

        return $builder
            ->orderByDesc('created_at')
            ->forPage($page, $perPage)
            ->get();
    }

    /**
     * The admin order list as a real paginator.
     *
     * The controller used to call {@see self::search()} and then filter the
     * 20 returned rows in PHP, which meant a page could show three rows out
     * of a page of twenty and the pager it printed counted the *unfiltered*
     * rows - so filtering for a decided order still offered pages that were
     * empty. The filters are part of the query here, so the pager the users
     * and ledger lists already use describes the same set of rows.
     *
     * `id` is the second sort key: `created_at` is second-resolution, so
     * orders placed in the same second used to swap places between pages and
     * a row could be shown twice and skipped once.
     */
    public function paginate(
        ?string $query = null,
        string $status = '',
        string $method = '',
        int $perPage = 20,
    ): LengthAwarePaginator {
        $builder = $this->searchQuery($query);

        // A pending order is the row with a NULL is_paid, not a value: the
        // tri-state is why the enum has a Pending case that writes null.
        $builder->when($status === 'pending', fn (Builder $nested) => $nested->whereNull('payments.is_paid'))
            ->when(in_array($status, ['0', '1'], true), fn (Builder $nested) => $nested->where('payments.is_paid', $status))
            ->when($method !== '', fn (Builder $nested) => $nested->where('payments.method', $method));

        return $builder
            ->orderByDesc('payments.created_at')
            ->orderByDesc('payments.id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * How many rows the same search would return, for admin pagination.
     */
    public function countSearchResults(?string $query = null): int
    {
        return $this->searchQuery($query)->count();
    }

    /**
     * The shared query behind search() and countSearchResults().
     *
     * The user columns are selected so the admin list can show a name without
     * a second query, exactly as the legacy SELECT did.
     */
    private function searchQuery(?string $query): Builder
    {
        $builder = Payment::query()
            ->leftJoin('users', 'payments.chat_id', '=', 'users.chat_id')
            ->select('payments.*')
            ->addSelect([
                // The aliases have to be written out: addSelect() with a plain
                // string value is treated as a column expression and silently
                // drops the alias, which left user_name unset.
                'user_name' => DB::raw('users.name as user_name'),
                'user_telegram' => DB::raw('users.telegram_id as user_telegram'),
                'user_id' => DB::raw('users.id as user_id'),
            ]);

        $query = trim((string) $query);

        if ($query === '') {
            return $builder;
        }

        $like = '%'.$query.'%';

        $builder->where(function (Builder $nested) use ($like): void {
            foreach (self::SEARCHABLE_PAYMENT_COLUMNS as $column) {
                $nested->orWhere("payments.$column", 'like', $like);
            }

            $nested->orWhere('users.name', 'like', $like);
        });

        return $builder;
    }

    // -----------------------------------------------------------------
    // Status transitions
    // -----------------------------------------------------------------

    /**
     * Whether a payment has already been accepted or rejected.
     *
     * Legacy opened both `paycheck()` branches with
     * `if ($paidStatus != null) { ...show current status... }`, so a decided
     * order could never change again. The tri-state is why this matters: only
     * a null `is_paid` counts as undecided.
     */
    public function isDecided(Payment $payment): bool
    {
        return $payment->is_paid->isDecided();
    }

    /**
     * Mark an order as paid.
     *
     * Returns false without writing when the order was already decided, which
     * is what makes a double tap on the admin button harmless.
     */
    public function markPaid(int $paymentId): bool
    {
        return $this->decide($paymentId, PaymentStatus::Paid);
    }

    /**
     * Mark an order as rejected.
     */
    public function markRejected(int $paymentId): bool
    {
        return $this->decide($paymentId, PaymentStatus::Rejected);
    }

    /**
     * Apply a status, but only to an order that is still awaiting a decision.
     */
    private function decide(int $paymentId, PaymentStatus $status): bool
    {
        return DB::transaction(function () use ($paymentId, $status): bool {
            $payment = Payment::query()->where('id', $paymentId)->lockForUpdate()->first();

            if ($payment === null) {
                Log::warning('Cannot change the status of a payment that does not exist.', [
                    'payment_id' => $paymentId,
                    'status' => $status->value,
                ]);

                return false;
            }

            if ($payment->is_paid->isDecided()) {
                return false;
            }

            $payment->forceFill(['is_paid' => $status])->save();

            return true;
        });
    }

    /**
     * Replace the 'new' placeholder with the real client id once the account
     * exists on the panel.
     */
    public function attachClientId(int $paymentId, string $clientId): bool
    {
        $updated = Payment::query()->where('id', $paymentId)->update([
            'client_id' => $clientId,
        ]);

        if ($updated === 0) {
            Log::warning('Could not attach a client id to the payment.', [
                'payment_id' => $paymentId,
                'client_id' => $clientId,
            ]);

            return false;
        }

        return true;
    }

    /**
     * The user who placed the order.
     *
     * `paycheck()` read the user's name, Telegram id and row id off the users
     * table before creating the client.
     */
    public function buyer(Payment $payment): ?User
    {
        return User::query()->where('chat_id', $payment->chat_id)->first();
    }
}
