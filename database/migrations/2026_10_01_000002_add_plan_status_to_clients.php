<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The status shown next to every account on the profile page.
 *
 * The legacy bot decided فعال / در صف / غیرفعال from the client's current
 * plan (`is_active`, then `is_in_queue`), and the expiry window only exists
 * here as a fallback for a row whose plans have never been read. Storing the
 * reduced answer keeps the list from asking the panel once per row on every
 * page load.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('clients', 'plan_status')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table): void {
            // active | queued | inactive, null while the plans are unknown.
            $table->string('plan_status', 20)->nullable()->after('expire_date');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('clients', 'plan_status')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('plan_status');
        });
    }
};
