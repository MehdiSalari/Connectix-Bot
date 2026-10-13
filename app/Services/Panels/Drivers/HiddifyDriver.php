<?php

declare(strict_types=1);

namespace App\Services\Panels\Drivers;

use Illuminate\Support\Facades\Http;

/**
 * Hiddify Manager (admin API v2).
 *
 * base_url = admin panel address WITH the admin proxy path, e.g.
 * https://domain.com/ADMIN_PROXY_PATH (no trailing /admin/).
 * secret = the admin UUID, sent as the Hiddify-API-Key header. The username
 * is unused. extra: sub_base (client proxy path).
 */
class HiddifyDriver implements PanelDriverInterface
{
    public function __construct(
        private readonly array $panel,
    ) {}

    public function testConnection(): array
    {
        try {
            $response = Http::withHeaders($this->headers())->acceptJson()->get($this->url('/api/v2/admin/me/'));

            if ($response->status() === 404) {
                // Older builds only answer /api/v2/panel/info/.
                $response = Http::withHeaders($this->headers())->acceptJson()->get($this->url('/api/v2/panel/info/'));
            }

            if ($response->status() === 401 || $response->status() === 403) {
                return ['ok' => false, 'msg' => 'احراز هویت Hiddify ناموفق بود (UUID ادمین را بررسی کنید).'];
            }

            if ($response->status() !== 200) {
                return ['ok' => false, 'msg' => 'پاسخ نامعتبر از Hiddify (HTTP '.$response->status().')'];
            }

            $warn = $this->extra('sub_base') !== ''
                ? ''
                : ' — هشدار: «آدرس ساب» (مسیر کلاینت) تنظیم نشده و لینک اشتراک نمایش داده نمی‌شود.';

            return ['ok' => true, 'msg' => 'اتصال برقرار شد.'.$warn];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => 'اتصال به پنل برقرار نشد: '.$e->getMessage()];
        }
    }

    public function listInbounds(): array
    {
        // Hiddify configures hosts per profile, not inbounds; the bot-side
        // provisioning (wave 4) talks to /api/v2/admin/user/ directly.
        return [];
    }

    private function headers(): array
    {
        return ['Hiddify-API-Key' => (string) ($this->panel['secret'] ?? '')];
    }

    private function extra(string $key): string
    {
        $extra = is_array($this->panel['extra'] ?? null) ? $this->panel['extra'] : [];
        $value = $extra[$key] ?? null;

        return $value === null ? '' : trim((string) $value);
    }

    private function url(string $path): string
    {
        return rtrim((string) ($this->panel['base_url'] ?? ''), '/').$path;
    }
}
