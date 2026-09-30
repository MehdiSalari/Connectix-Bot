<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The panel keeps the account expiry on the client record, and the admin UI
 * needs it to show فعال / غیرفعال next to every account in the profile list.
 * Without a local copy the page would have to ask the panel once per row on
 * every load; with it, `connectix:sync-clients` keeps the value current.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('clients', 'expire_date')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table): void {
            // Jalali, exactly as the panel returns it (1403-12-21 17:59); the
            // Client model converts it through JalaliCalendar when comparing.
            $table->string('expire_date', 40)->nullable()->after('password');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('clients', 'expire_date')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('expire_date');
        });
    }
};
