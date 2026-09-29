<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single admin-editable setting stored as a key/value local override.
 *
 * Not part of the legacy schema: phase 13 adds it as the database equivalent
 * of the legacy `setup/bot_config.json` file. PanelSettingsService reads and
 * writes it; nothing else touches this table directly.
 */
class PanelSetting extends Model
{
    protected $table = 'panel_settings';

    protected $primaryKey = 'setting_key';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
