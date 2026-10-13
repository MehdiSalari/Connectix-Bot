<?php

declare(strict_types=1);

namespace App\Services\Panels\Drivers;

use Illuminate\Support\Facades\Http;

/**
 * Remnawave (https://remnawave.com).
 *
 * base_url = panel address, e.g. https://panel.example.com
 * Auth: leave «username» empty and put an API token (Bearer) in «secret»
 * (Panel → Settings → API tokens), or give username + password and the
 * driver logs in first. extra: squads (comma separated internal-squad UUIDs).
 */
class RemnawaveDriver implements PanelDriverInterface
{
    public function __construct(
        private readonly array $panel,
    ) {}

    public function testConnection(): array
    {
        try {
            $response = Http::withToken($this->token())->acceptJson()->get(
                $this->url('/api/users'),
                ['size' => 1, 'start' => 0],
            );

            if ($response->status() === 401) {
                return ['ok' => false, 'msg' => 'احراز هویت Remnawave ناموفق بود (توکن یا رمز را بررسی کنید).'];
            }

            if ($response->status() !== 200) {
                return ['ok' => false, 'msg' => 'پاسخ نامعتبر از Remnawave (HTTP '.$response->status().')'];
            }

            return ['ok' => true, 'msg' => 'اتصال برقرار شد.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => 'اتصال به پنل برقرار نشد: '.$e->getMessage()];
        }
    }

    public function listInbounds(): array
    {
        // Remnawave has no inbound concept; hosts/actives live in internal
        // squads, which the extra.squads field already carries.
        return [];
    }

    private function token(): string
    {
        $user = trim((string) ($this->panel['username'] ?? ''));

        if ($user === '') {
            return (string) ($this->panel['secret'] ?? '');
        }

        $response = Http::acceptJson()->post(
            $this->url('/api/auth/login'),
            ['username' => $user, 'password' => (string) ($this->panel['secret'] ?? '')],
        );

        return (string) ($response->json('response.accessToken') ?? $response->json('accessToken') ?? '');
    }

    private function url(string $path): string
    {
        return rtrim((string) ($this->panel['base_url'] ?? ''), '/').$path;
    }
}
