<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local override store for the admin settings page.
 *
 * The legacy project persisted every admin-editable value in
 * setup/bot_config.json beside the code. This table is the same file stored
 * in the database: a key/value overlay that PanelSettingsService consults
 * between the .env configuration and the seller panel payload, so the admin
 * panel can edit branding and toggles at runtime without touching the code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_settings', function (Blueprint $table): void {
            $table->string('setting_key', 190)->primary();
            $table->longText('setting_value')->nullable();
            $table->timestamp('updated_at')->nullable()->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_settings');
    }
};
