<?php

declare(strict_types=1);

namespace App\Services\Panels;

use App\Models\LocalPlan;
use App\Models\Panel;
use App\Services\Panels\Drivers\HiddifyDriver;
use App\Services\Panels\Drivers\MarzbanDriver;
use App\Services\Panels\Drivers\PanelDriverInterface;
use App\Services\Panels\Drivers\RemnawaveDriver;
use App\Services\Panels\Drivers\XuiDriver;
use Illuminate\Support\Facades\Crypt;

/**
 * Multi-panel support: Marzban, 3x-ui, Remnawave and Hiddify next to
 * Connectix.
 *
 * Port of the admin half of the feature fork's `services/PanelService.php`
 * (panels/index.php). The provisioning half (creating accounts on these
 * panels) comes with wave 4; everything here is configuration and local
 * plan bookkeeping.
 *
 * The `secret` column is encrypted at the application layer with Laravel's
 * crypter (the fork used its own openssl envelope) and is never handed to a
 * view — pages only ever see a has_secret flag.
 */
class PanelNodeService
{
    /** Panel types, with their Persian labels (fork: PanelService::TYPES). */
    public const TYPES = [
        'marzban' => 'Marzban',
        'xui' => '3x-ui',
        'remnawave' => 'Remnawave',
        'hiddify' => 'Hiddify',
    ];

    /** Plan groups the purchase menu buckets plans into (fork: GROUPS). */
    public const GROUPS = ['default', 'Sublink', 'Economic', 'Static IP', 'Iran Access', 'Business Class', 'BCSublink'];

    /**
     * Every configured panel, secrets stripped.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listPanels(): array
    {
        return Panel::query()
            ->orderBy('id')
            ->get()
            ->map(fn (Panel $panel): array => $this->present($panel))
            ->all();
    }

    /**
     * One panel by id, secret stripped. Null when absent.
     *
     * @return array<string, mixed>|null
     */
    public function getPanel(int $id): ?array
    {
        $panel = Panel::query()->find($id);

        return $panel !== null ? $this->present($panel) : null;
    }

    /**
     * One panel with the secret decrypted, for driver construction only.
     * Never passes through a view.
     *
     * @return array<string, mixed>|null
     */
    public function getPanelWithSecret(int $id): ?array
    {
        $panel = Panel::query()->find($id);

        if ($panel === null) {
            return null;
        }

        $row = $this->present($panel);
        $row['secret'] = $this->decryptSecret((string) $panel->secret);

        return $row;
    }

    /**
     * Create or update a panel. Returns null on success, else a Persian
     * error message. An empty secret on update keeps the stored one — the
     * form's «خالی بگذارید تا تغییر نکند».
     *
     * @param  array<string, mixed>  $input  name, type, base_url, username,
     *                                       secret, is_active, plus optional
     *                                       extra keys (inbound_id, flow,
     *                                       sub_base, otp, squads,
     *                                       proxies_json, inbounds_json,
     *                                       verify_ssl).
     */
    public function savePanel(?int $id, array $input): ?string
    {
        $name = trim((string) ($input['name'] ?? ''));
        $type = (string) ($input['type'] ?? '');
        $url = rtrim(trim((string) ($input['base_url'] ?? '')), '/');
        $username = trim((string) ($input['username'] ?? ''));
        $secretIn = (string) ($input['secret'] ?? '');

        if ($name === '' || ! isset(self::TYPES[$type])) {
            return 'نام و نوع پنل الزامی است.';
        }

        if (! preg_match('#^https?://\S+$#i', $url)) {
            return 'آدرس پنل باید با http:// یا https:// شروع شود.';
        }

        $extra = [
            'verify_ssl' => (bool) ($input['verify_ssl'] ?? true),
        ];

        foreach (['inbound_id', 'flow', 'sub_base', 'otp', 'squads'] as $key) {
            $value = trim((string) ($input[$key] ?? ''));

            if ($value !== '') {
                $extra[$key] = $value;
            }
        }

        foreach (['proxies_json', 'inbounds_json'] as $key) {
            $value = trim((string) ($input[$key] ?? ''));

            if ($value !== '') {
                if (json_decode($value) === null && json_last_error() !== JSON_ERROR_NONE) {
                    return "فیلد {$key} باید یک JSON معتبر باشد.";
                }

                $extra[$key] = $value;
            }
        }

        if ($type === 'xui' && (int) ($extra['inbound_id'] ?? 0) <= 0) {
            return 'برای 3x-ui شناسهٔ inbound الزامی است.';
        }

        if (in_array($type, ['marzban', 'xui'], true) && $username === '') {
            return 'نام کاربری ادمین الزامی است.';
        }

        if ($id !== null) {
            $panel = Panel::query()->find($id);

            if ($panel === null) {
                return 'پنل پیدا نشد.';
            }

            $secret = $secretIn !== '' ? $secretIn : $this->decryptSecret((string) $panel->secret);

            $panel->fill([
                'name' => $name,
                'type' => $type,
                'base_url' => $url,
                'username' => $username,
                'secret' => $this->encryptSecret($secret),
                'extra' => $extra,
                'is_active' => (bool) ($input['is_active'] ?? false),
            ])->save();

            return null;
        }

        if ($secretIn === '') {
            return 'رمز / توکن / کلید API الزامی است.';
        }

        Panel::query()->create([
            'name' => $name,
            'type' => $type,
            'base_url' => $url,
            'username' => $username,
            'secret' => $this->encryptSecret($secretIn),
            'extra' => $extra,
            'is_active' => (bool) ($input['is_active'] ?? false),
        ]);

        return null;
    }

    /**
     * Delete a panel — refused while local plans still reference it, exactly
     * like the fork («ابتدا پلن‌های متصل به این پنل را حذف کنید.»).
     *
     * @return array{ok: bool, msg: string}
     */
    public function deletePanel(int $id): array
    {
        $panel = Panel::query()->find($id);

        if ($panel === null) {
            return ['ok' => false, 'msg' => 'پنل پیدا نشد.'];
        }

        if (LocalPlan::query()->where('panel_id', $id)->exists()) {
            return ['ok' => false, 'msg' => 'ابتدا پلن‌های متصل به این پنل را حذف کنید.'];
        }

        $panel->delete();

        return ['ok' => true, 'msg' => 'پنل حذف شد.'];
    }

    /**
     * Fire the panel's own driver against its API.
     *
     * @return array{ok: bool, msg: string}
     */
    public function testPanel(int $id): array
    {
        $panel = $this->getPanelWithSecret($id);

        if ($panel === null) {
            return ['ok' => false, 'msg' => 'پنل پیدا نشد.'];
        }

        return $this->driver($panel)->testConnection();
    }

    /**
     * The driver for a panel row (must carry a decrypted `secret`).
     */
    public function driver(array $panel): PanelDriverInterface
    {
        return match ($panel['type'] ?? '') {
            'marzban' => new MarzbanDriver($panel),
            'xui' => new XuiDriver($panel),
            'remnawave' => new RemnawaveDriver($panel),
            'hiddify' => new HiddifyDriver($panel),
            default => throw new \InvalidArgumentException('نوع پنل نامعتبر است.'),
        };
    }

    // -----------------------------------------------------------------
    // Local plans
    // -----------------------------------------------------------------

    /**
     * Local plans, optionally scoped to one panel, newest last. Each row
     * carries the owning panel's name/type/active flag for the table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listPlans(?int $panelId = null): array
    {
        $query = LocalPlan::query()
            ->leftJoin('panels', 'panels.id', '=', 'local_plans.panel_id')
            ->select('local_plans.*', 'panels.name as panel_name', 'panels.type as panel_type', 'panels.is_active as panel_active')
            ->orderBy('local_plans.sort_order')
            ->orderBy('local_plans.id');

        if ($panelId !== null && $panelId > 0) {
            $query->where('local_plans.panel_id', $panelId);
        }

        return $query->get()
            ->map(fn (LocalPlan $plan): array => [
                'id' => $plan->id,
                'name' => $plan->name,
                'price' => (int) $plan->price,
                'days' => $plan->days,
                'traffic_gb' => (float) $plan->traffic_gb,
                'devices' => $plan->devices,
                'panel_id' => $plan->panel_id,
                'group_name' => $plan->group_name,
                'is_free' => (bool) $plan->is_free,
                'is_active' => (bool) $plan->is_active,
                'sort_order' => $plan->sort_order,
                'panel_name' => $plan->getAttribute('panel_name'),
                'panel_type' => $plan->getAttribute('panel_type'),
                'panel_active' => (bool) $plan->getAttribute('panel_active'),
            ])
            ->all();
    }

    /**
     * Create or update a local plan. Returns null on success, else a Persian
     * error message — the fork's own validation, ranges included.
     *
     * @param  array<string, mixed>  $input
     */
    public function savePlan(?int $id, array $input): ?string
    {
        $name = trim((string) ($input['name'] ?? ''));
        $price = (int) preg_replace('/\D+/', '', (string) ($input['price'] ?? '0'));
        $days = (int) ($input['days'] ?? 0);
        $gb = (float) str_replace(',', '.', (string) ($input['traffic_gb'] ?? '0'));
        $devices = (int) ($input['devices'] ?? 1);
        $panelId = (int) ($input['panel_id'] ?? 0);
        $group = (string) ($input['group_name'] ?? 'default');
        $sort = (int) ($input['sort_order'] ?? 0);
        $active = (bool) ($input['is_active'] ?? false);
        $free = (bool) ($input['is_free'] ?? false);

        // Free test plans are never charged.
        if ($free) {
            $price = 0;
        }

        if ($days < 1 || $days > 3650) {
            return 'مدت باید بین ۱ تا ۳۶۵۰ روز باشد.';
        }

        if ($gb < 0 || $gb > 100000) {
            return 'حجم نامعتبر است (۰ = نامحدود).';
        }

        if ($devices < 1 || $devices > 20) {
            return 'تعداد کاربر باید بین ۱ تا ۲۰ باشد.';
        }

        if (! in_array($group, self::GROUPS, true)) {
            return 'گروه نامعتبر است.';
        }

        if (mb_strlen($name) > 60) {
            return 'نام پلن حداکثر ۶۰ کاراکتر باشد.';
        }

        if (Panel::query()->where('id', $panelId)->doesntExist()) {
            return 'پنل انتخاب‌شده وجود ندارد.';
        }

        $attributes = [
            'name' => $name,
            'price' => $price,
            'days' => $days,
            'traffic_gb' => $gb,
            'devices' => $devices,
            'panel_id' => $panelId,
            'group_name' => $group,
            'is_free' => $free,
            'is_active' => $active,
            'sort_order' => $sort,
        ];

        if ($id !== null) {
            $plan = LocalPlan::query()->find($id);

            if ($plan === null) {
                return 'پلن پیدا نشد.';
            }

            $plan->fill($attributes)->save();

            return null;
        }

        LocalPlan::query()->create($attributes);

        return null;
    }

    /**
     * Remove a local plan. Sold accounts keep working: the account lives on
     * the VPN panel, the row here is only the price tag.
     */
    public function deletePlan(int $id): void
    {
        LocalPlan::query()->where('id', $id)->delete();
    }

    /**
     * One local plan.
     *
     * @return array<string, mixed>|null
     */
    public function getPlan(int $id): ?array
    {
        $plan = LocalPlan::query()->find($id);

        if ($plan === null) {
            return null;
        }

        return $plan->toArray();
    }

    /**
     * The Connectix-style plan title the bot shows: «(2x) 50GB-30D Gold».
     *
     * @param  array<string, mixed>|LocalPlan  $plan
     */
    public function planTitle(LocalPlan|array $plan): string
    {
        $trafficGb = (float) (is_array($plan) ? ($plan['traffic_gb'] ?? 0) : $plan->traffic_gb);
        $traffic = $trafficGb > 0
            ? rtrim(rtrim(number_format($trafficGb, 2, '.', ''), '0'), '.').'GB-'
            : 'Unlimited-';

        $name = trim((string) (is_array($plan) ? ($plan['name'] ?? '') : $plan->name));
        $free = ! empty(is_array($plan) ? ($plan['is_free'] ?? false) : $plan->is_free) ? 'Free-' : '';
        $devices = (int) (is_array($plan) ? ($plan['devices'] ?? 1) : $plan->devices);
        $days = (int) (is_array($plan) ? ($plan['days'] ?? 0) : $plan->days);

        return "({$devices}x) {$free}{$traffic}{$days}D".($name !== '' ? ' '.$name : '');
    }

    // -----------------------------------------------------------------
    // Secret handling
    // -----------------------------------------------------------------

    private function encryptSecret(string $plain): string
    {
        return $plain === '' ? '' : Crypt::encryptString($plain);
    }

    private function decryptSecret(string $stored): string
    {
        if ($stored === '') {
            return '';
        }

        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            // A row written by the fork's own openssl envelope (or a key
            // rotation) must not crash the panel; the driver reports the
            // auth failure instead.
            return '';
        }
    }

    /**
     * The view-facing shape: everything except the secret, plus has_secret.
     *
     * @return array<string, mixed>
     */
    private function present(Panel $panel): array
    {
        return [
            'id' => $panel->id,
            'name' => $panel->name,
            'type' => $panel->type,
            'base_url' => $panel->base_url,
            'username' => $panel->username,
            'extra' => is_array($panel->extra) ? $panel->extra : [],
            'is_active' => (bool) $panel->is_active,
            'created_at' => $panel->created_at,
            'has_secret' => (string) $panel->secret !== '',
        ];
    }
}
