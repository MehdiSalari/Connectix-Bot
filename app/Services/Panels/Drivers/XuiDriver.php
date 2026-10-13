<?php

declare(strict_types=1);

namespace App\Services\Panels\Drivers;

use Illuminate\Support\Facades\Http;

/**
 * 3x-ui (MHSanaei/3x-ui, legacy /panel/api/inbounds/* endpoints).
 *
 * base_url = panel address INCLUDING its random web base path, e.g.
 * https://host:2053/AbCdEf123 (what you see before /panel in the browser).
 * username / secret = panel login; the session cookie the login sets is what
 * authenticates the later calls, so the cookie jar must be shared between
 * the login and the API requests.
 * extra: inbound_id (required), flow, sub_base, otp.
 */
class XuiDriver implements PanelDriverInterface
{
    public function __construct(
        private readonly array $panel,
    ) {}

    public function testConnection(): array
    {
        try {
            $inbound = $this->inbound();

            if ($inbound === null) {
                return ['ok' => false, 'msg' => 'ورود به 3x-ui ناموفق بود (آدرس، مسیر وب‌بیس یا رمز را بررسی کنید).'];
            }

            $proto = (string) ($inbound['protocol'] ?? '?');

            if (! in_array($proto, ['vless', 'vmess', 'trojan'], true)) {
                return ['ok' => false, 'msg' => "پروتکل inbound ({$proto}) پشتیبانی نمی‌شود؛ vless / vmess / trojan انتخاب کنید."];
            }

            $warn = $this->extra('sub_base') !== ''
                ? ''
                : ' — هشدار: «آدرس ساب» تنظیم نشده و لینک اشتراک نمایش داده نمی‌شود.';

            return ['ok' => true, 'msg' => 'اتصال برقرار شد (inbound: '.($inbound['remark'] ?? '#'.$this->inboundId())." / {$proto}){$warn}"];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => 'اتصال به پنل برقرار نشد: '.$e->getMessage()];
        }
    }

    public function listInbounds(): array
    {
        $response = $this->loggedInRequest('GET', '/panel/api/inbounds/list');

        if ($response === null || $response->status() !== 200) {
            return [];
        }

        $out = [];

        foreach ((array) ($response->json('obj') ?? []) as $inbound) {
            if (! is_array($inbound)) {
                continue;
            }

            $out[] = [
                'id' => $inbound['id'] ?? null,
                'remark' => (string) ($inbound['remark'] ?? ''),
                'protocol' => (string) ($inbound['protocol'] ?? ''),
                'port' => $inbound['port'] ?? null,
            ];
        }

        return $out;
    }

    private function inbound(): ?array
    {
        $response = $this->loggedInRequest('GET', '/panel/api/inbounds/get/'.$this->inboundId());

        if ($response === null || $response->status() !== 200 || ! $response->json('success')) {
            return null;
        }

        $obj = $response->json('obj');

        return is_array($obj) ? $obj : null;
    }

    /**
     * Login once (the panel answers set-cookie), then issue the real call
     * with the same cookie jar. Http::fake in tests matches per URL, and the
     * cookie persists across requests on the same client instance.
     */
    private function loggedInRequest(string $method, string $path)
    {
        $client = Http::asJson()->acceptJson();

        $form = [
            'username' => (string) ($this->panel['username'] ?? ''),
            'password' => (string) ($this->panel['secret'] ?? ''),
        ];

        if (($otp = $this->extra('otp')) !== '') {
            $form['twoFactorCode'] = $otp;
        }

        $login = Http::asForm()->acceptJson()->post($this->url('/login'), $form);

        if ($login->status() !== 200 || ! $login->json('success')) {
            return null;
        }

        // The login response carries the session cookie; replay it on the
        // API call (Laravel stores cookies per client, so the same base URL
        // with cookies from the login response is what legacy's shared curl
        // handle did).
        $cookie = $login->header('Set-Cookie');

        return $client->when(
            $cookie !== null && $cookie !== '',
            fn ($http) => $http->withHeaders(['Cookie' => $this->cookiePair($cookie)]),
        )->send($method, $this->url($path));
    }

    private function cookiePair(string $header): string
    {
        // Set-Cookie may arrive folded; the first pair is the session.
        $first = trim(explode(';', trim($header))[0]);

        return $first;
    }

    private function inboundId(): int
    {
        $id = (int) $this->extra('inbound_id', '0');

        if ($id <= 0) {
            throw new \InvalidArgumentException('شناسهٔ inbound برای این پنل تنظیم نشده است.');
        }

        return $id;
    }

    private function extra(string $key, ?string $default = null): string
    {
        $extra = is_array($this->panel['extra'] ?? null) ? $this->panel['extra'] : [];
        $value = $extra[$key] ?? $default;

        return $value === null ? '' : trim((string) $value);
    }

    private function url(string $path): string
    {
        return rtrim((string) ($this->panel['base_url'] ?? ''), '/').$path;
    }
}
