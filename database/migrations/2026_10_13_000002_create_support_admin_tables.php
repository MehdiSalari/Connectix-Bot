<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature-fork support and admin schema (group B).
 *
 * Mirrors the ticketing system, the admin login brute-force log, and the
 * admin activity trail the feature fork adds on top of the legacy schema,
 * plus the admin columns for multi-level access, Telegram pairing and
 * two-factor authentication. Each `admins` column is guarded individually
 * so an existing production install is only widened, never rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createTickets();
        $this->createTicketMessages();
        $this->createLoginAttempts();
        $this->createAdminActivity();
        $this->extendAdmins();
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_activity');
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
    }

    private function createTickets(): void
    {
        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('ticket_number', 32)->unique();
            $table->integer('user_id')->nullable();
            $table->string('chat_id');
            $table->string('subject');
            $table->string('department', 100)->nullable()->default('پشتیبانی عمومی');
            $table->string('status')->default('OPEN');
            $table->string('priority')->default('NORMAL');
            $table->string('assigned_admin')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });
    }

    private function createTicketMessages(): void
    {
        Schema::create('ticket_messages', function (Blueprint $table): void {
            $table->id();
            $table->integer('ticket_id');
            // 'user' | 'admin'
            $table->string('sender_type', 32);
            $table->string('sender_id')->nullable();
            $table->string('sender_name')->nullable();
            $table->text('message');
            $table->text('attachment')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }

    private function createLoginAttempts(): void
    {
        Schema::create('login_attempts', function (Blueprint $table): void {
            $table->id();
            // "ip:<addr>" or "email:<address>" — throttles both single-source
            // hammering and distributed attempts against one account.
            $table->string('identifier');
            $table->dateTime('attempted_at')->nullable()->useCurrent();
        });
    }

    private function createAdminActivity(): void
    {
        Schema::create('admin_activity', function (Blueprint $table): void {
            $table->id();
            $table->string('admin_email')->nullable();
            $table->integer('admin_id')->nullable();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->text('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }

    private function extendAdmins(): void
    {
        if (! Schema::hasTable('admins')) {
            return;
        }

        if (! Schema::hasColumn('admins', 'is_primary')) {
            Schema::table('admins', function (Blueprint $table): void {
                $table->boolean('is_primary')->default(false);
            });
        }

        if (! Schema::hasColumn('admins', 'is_active')) {
            Schema::table('admins', function (Blueprint $table): void {
                $table->boolean('is_active')->default(true);
            });
        }

        if (! Schema::hasColumn('admins', 'permissions')) {
            Schema::table('admins', function (Blueprint $table): void {
                // JSON list of permission keys for the multi-level access system.
                $table->text('permissions')->nullable();
            });
        }

        if (! Schema::hasColumn('admins', 'telegram_chat_id')) {
            Schema::table('admins', function (Blueprint $table): void {
                $table->string('telegram_chat_id')->nullable();
            });
        }

        if (! Schema::hasColumn('admins', 'two_factor_secret')) {
            Schema::table('admins', function (Blueprint $table): void {
                $table->string('two_factor_secret', 64)->nullable();
            });
        }
    }
};
