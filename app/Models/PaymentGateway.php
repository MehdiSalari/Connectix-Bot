<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One configured online payment gateway (ZarinPal, NextPay, NOWPayments, ...).
 *
 * Feature-fork table: `payment_gateways`. Secrets live in `api_key`/
 * `secret_key` and must never be logged or exposed; `config_json` holds
 * gateway-specific settings the panel edits.
 */
class PaymentGateway extends Model
{
    protected $table = 'payment_gateways';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = [
        'api_key',
        'secret_key',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sandbox' => 'boolean',
            'min_amount' => 'decimal:2',
            'sort_order' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
