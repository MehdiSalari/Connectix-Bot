<?php

declare(strict_types=1);

namespace App\Services\Panels\Drivers;

use Illuminate\Support\Facades\Http;

/**
 * Marzban (https://github.com/Gozargah/Marzban).
 *
 * base_url = panel address without /dashboard, e.g. https://panel.example.com:8000
 * username / secret = an admin account; login posts the OAuth2 password
 * grant to /api/admin/token and every later call carries the Bearer token.
 * extra: proxies_json, inbounds_json (both optional JSON objects/arrays).
 */
class MarzbanDriver implements PanelDriverInterface
{
    public function __construct(
        private readonly array $panel,
    ) {}

    public function testConnection(): array
    {
        try {
            $response = Http::asForm()->acceptJson()->post(
                $this->url('/api/admin/token'),
                [
                    'username' => (string) ($this->panel['username'] ?? ''),
                    'password' => $this->secret(),
                    'grant_type' => 'password',
                ],
            );

            if ($response->status() !== 200) {
                return ['ok' => false, 'msg' => 'ورود به Marzban ناموفق بود: HTTP '.$response->status()];
            }

            $token = (string) ($response->json('access_token') ?? '');

            if ($token === '') {
                return ['ok' => false, 'msg' => 'ورود به Marzban ناموفق بود: توکن دریافت نشد.'];
            }

            $me = Http::withToken($token)->acceptJson()->get($this->url('/api/admin'));

            if ($me->status() !== 200) {
                return ['ok' => false, 'msg' => 'احراز هویت Marzban ناموفق بود: HTTP '.$me->status()];
            }

            return ['ok' => true, 'msg' => 'اتصال برقرار شد (ادمین: '.($me->json('username') ?? '?').')'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => 'اتصال به پنل برقرار نشد: '.$e->getMessage()];
        }
    }

    public function listInbounds(): array
    {
        $token = $this->token();

        $response = Http::withToken($token)->acceptJson()->get($this->url('/api/inbounds'));

        if ($response->status() !== 200) {
            return [];
        }

        $out = [];

        foreach ((array) $response->json() as $inbound) {
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

    private function token(): string
    {
        $response = Http::asForm()->acceptJson()->post(
            $this->url('/api/admin/token'),
            [
                'username' => (string) ($this->panel['username'] ?? ''),
                'password' => $this->secret(),
                'grant_type' => 'password',
            ],
        );

        return (string) ($response->json('access_token') ?? '');
    }

    private function secret(): string
    {
        return (string) ($this->panel['secret'] ?? '');
    }

    private function url(string $path): string
    {
        return rtrim((string) ($this->panel['base_url'] ?? ''), '/').$path;
    }
}
