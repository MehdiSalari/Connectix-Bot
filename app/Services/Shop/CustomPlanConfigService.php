<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Models\CustomPlanSetting;
use Illuminate\Support\Facades\DB;

/**
 * The custom volume/duration plan ("حجم و زمان دلخواه") pricing settings.
 *
 * Port of the configuration side of the fork's custom-plan/index.php and
 * CustomPlanService (the bot's ask/price/summary flow is a later wave). The
 * numeric and toggle settings live in the single `custom_plan_settings` row
 * (id=1); the three admin-editable bot texts are stored in `panel_settings`
 * under `customplan_text_*`, the same key/value store the other admin pages
 * use — the fork stored them as columns of the same row, and panel_settings
 * needs no schema change.
 */
class CustomPlanConfigService
{
    /** The panel_settings keys the three bot texts are stored under. */
    public const TEXT_KEYS = [
        'text_ask_gb' => 'customplan_text_ask_gb',
        'text_ask_days' => 'customplan_text_ask_days',
        'text_summary_title' => 'customplan_text_summary_title',
    ];

    /** The fork's factory defaults, used until the row exists. */
    private const DEFAULTS = [
        'is_active' => true,
        'price_per_gb' => 5000,
        'price_per_day' => 1000,
        'min_gb' => 1.0,
        'max_gb' => 1000.0,
        'min_days' => 1,
        'max_days' => 365,
        'default_devices' => 1,
    ];

    /**
     * The current settings, row values merged with the stored texts.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $row = CustomPlanSetting::query()->find(1);

        $settings = self::DEFAULTS;

        if ($row !== null) {
            foreach (self::DEFAULTS as $key => $default) {
                $settings[$key] = $row->{$key} ?? $default;
            }
        }

        $stored = DB::table('panel_settings')
            ->whereIn('setting_key', self::TEXT_KEYS)
            ->pluck('setting_value', 'setting_key');

        foreach (self::TEXT_KEYS as $field => $key) {
            $settings[$field] = trim((string) ($stored[$key] ?? ''));
        }

        return $settings;
    }

    /**
     * Persist a settings form.
     *
     * Both axes are validated as min <= max (the fork checked the GB axis
     * and left days implied by its duration chips; the schema carries real
     * min_days/max_days columns, so both are enforced here).
     *
     * @param  array<string, mixed>  $values
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function save(array $values): void
    {
        $minGb = max(0.1, (float) str_replace(',', '.', (string) ($values['min_gb'] ?? 1)));
        $maxGb = (float) str_replace(',', '.', (string) ($values['max_gb'] ?? 1000));
        $minDays = max(1, (int) ($values['min_days'] ?? 1));
        $maxDays = (int) ($values['max_days'] ?? 365);

        $errors = [];

        if ($maxGb < $minGb) {
            $errors['max_gb'] = 'حداکثر حجم نمی‌تواند از حداقل حجم کمتر باشد.';
        }

        if ($maxDays < $minDays) {
            $errors['max_days'] = 'حداکثر مدت نمی‌تواند از حداقل مدت کمتر باشد.';
        }

        if ((float) str_replace(',', '', (string) ($values['price_per_gb'] ?? 0)) < 0
            || (float) str_replace(',', '', (string) ($values['price_per_day'] ?? 0)) < 0) {
            $errors['price_per_gb'] = 'قیمت نمی‌تواند منفی باشد.';
        }

        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        CustomPlanSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'is_active' => (bool) ($values['is_active'] ?? false),
                'price_per_gb' => (int) round((float) str_replace(',', '', (string) ($values['price_per_gb'] ?? 0))),
                'price_per_day' => (int) round((float) str_replace(',', '', (string) ($values['price_per_day'] ?? 0))),
                'min_gb' => $minGb,
                'max_gb' => $maxGb,
                'min_days' => $minDays,
                'max_days' => $maxDays,
                'default_devices' => max(1, (int) ($values['default_devices'] ?? 1)),
                'updated_at' => now(),
            ],
        );

        $texts = [];

        foreach (self::TEXT_KEYS as $field => $key) {
            if (array_key_exists($field, $values)) {
                $texts[$key] = trim((string) $values[$field]);
            }
        }

        $this->writeTexts($texts);
    }

    /**
     * The price for a custom request: gb × per-GB + days × per-day.
     */
    public function priceFor(float $gb, int $days): int
    {
        $settings = $this->all();

        return (int) round(
            $gb * (float) $settings['price_per_gb'] + $days * (float) $settings['price_per_day']
        );
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * @param  array<string, string>  $texts
     */
    private function writeTexts(array $texts): void
    {
        foreach ($texts as $key => $value) {
            if ($value === '') {
                // Blank means «back to the bot's default text», the same
                // clearing contract the settings page uses for its overrides.
                DB::table('panel_settings')->where('setting_key', $key)->delete();

                continue;
            }

            DB::table('panel_settings')->updateOrInsert(
                ['setting_key' => $key],
                ['setting_value' => $value, 'updated_at' => now()],
            );
        }
    }
}
