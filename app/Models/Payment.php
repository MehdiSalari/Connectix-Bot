<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A purchase or renewal order.
 *
 * Legacy table: `payments`. `client_id` holds the literal string 'new' until
 * the account is actually created on the panel, at which point it is replaced
 * with the real client UUID.
 */
class Payment extends Model
{
    protected $table = 'payments';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * Normalise the tri-state `is_paid` column.
     *
     * The column is a nullable VARCHAR, and a plain enum cast cannot express
     * the pending state: Laravel hands back null for a null attribute instead
     * of an enum, so every call to `isPending()` on a fresh card order would
     * have been "call a method on null".
     *
     * Reading therefore always yields a PaymentStatus, with null mapping to
     * Pending, and writing Pending stores a real null so the value on disk
     * stays exactly what legacy wrote.
     */
    protected function isPaid(): Attribute
    {
        return Attribute::make(
            get: static fn (mixed $value): PaymentStatus => PaymentStatus::fromDatabase($value),
            set: static function (mixed $value): ?string {
                $status = $value instanceof PaymentStatus
                    ? $value
                    : PaymentStatus::fromDatabase($value);

                return $status === PaymentStatus::Pending ? null : $status->value;
            },
        );
    }

    /**
     * Placeholder used before the Connectix client exists.
     */
    public const NEW_CLIENT = 'new';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chat_id', 'chat_id');
    }

    /**
     * A payment that will provision a brand new account.
     */
    public function isNewClient(): bool
    {
        return $this->client_id === self::NEW_CLIENT;
    }

    /**
     * Numeric price with thousands separators removed.
     */
    public function priceAmount(): int
    {
        return (int) str_replace(',', '', (string) $this->price);
    }

    public function isPending(): bool
    {
        return ! $this->is_paid->isDecided();
    }
}
