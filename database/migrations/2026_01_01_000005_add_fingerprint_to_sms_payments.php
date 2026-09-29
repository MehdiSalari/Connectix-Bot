<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the bank SMS replay fingerprint.
 *
 * A gateway that retries one POST used to store the same deposit twice, and
 * `SmsPaymentService::claim()` deliberately refuses to choose between two
 * deposits of one amount - so the duplicate silently turned a working
 * auto-payment off. The fingerprint identifies one delivery.
 *
 * The index is not unique on purpose: two genuinely separate transfers can
 * carry the same text after whitespace normalisation, and only the match window
 * decides whether a row is a repeat. Legacy `sms_payments` keeps working, the
 * column is nullable for rows written before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sms_payments') || Schema::hasColumn('sms_payments', 'fingerprint')) {
            return;
        }

        Schema::table('sms_payments', function (Blueprint $table): void {
            $table->string('fingerprint', 64)->nullable()->after('bank');
            $table->index('fingerprint');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sms_payments') || ! Schema::hasColumn('sms_payments', 'fingerprint')) {
            return;
        }

        Schema::table('sms_payments', function (Blueprint $table): void {
            $table->dropIndex(['fingerprint']);
            $table->dropColumn('fingerprint');
        });
    }
};
