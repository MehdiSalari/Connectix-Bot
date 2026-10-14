<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor authentication columns on `admins`, port of the feature fork's
 * security.php storage contract.
 *
 * Each column is guarded individually so an existing production install is
 * only widened, never rewritten — the same rule the group-B support migration
 * follows. `totp_secret` holds the base32 shared secret, `totp_enabled` is
 * the flag the login flow gates on, and `backup_codes` stores the JSON list
 * of bcrypt hashes for the one-time recovery codes (the plain codes are
 * shown exactly once and cannot be retrieved again, only regenerated).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admins')) {
            return;
        }

        if (! Schema::hasColumn('admins', 'totp_secret')) {
            Schema::table('admins', function (Blueprint $table): void {
                $table->string('totp_secret')->nullable();
            });
        }

        if (! Schema::hasColumn('admins', 'totp_enabled')) {
            Schema::table('admins', function (Blueprint $table): void {
                $table->boolean('totp_enabled')->default(false);
            });
        }

        if (! Schema::hasColumn('admins', 'backup_codes')) {
            Schema::table('admins', function (Blueprint $table): void {
                // JSON array of password_hash() digests, one per backup code.
                $table->text('backup_codes')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('admins')) {
            return;
        }

        foreach (['backup_codes', 'totp_enabled', 'totp_secret'] as $column) {
            if (Schema::hasColumn('admins', $column)) {
                Schema::table('admins', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
