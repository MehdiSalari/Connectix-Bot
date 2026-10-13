<?php

declare(strict_types=1);

namespace App\Services\Panels\Drivers;

/**
 * One VPN-panel driver's contract.
 *
 * Wave 3 only needs the two calls the admin panel makes: the connection
 * test the «تست اتصال» button fires and the inbound list the provisioning
 * wave will build on. Every implementation speaks HTTP through the Laravel
 * Http facade so tests can Http::fake the panel and nothing here ever
 * reaches a real host from the test suite.
 */
interface PanelDriverInterface
{
    /**
     * Probe the panel with the driver's own auth flow.
     *
     * @return array{ok: bool, msg: string} msg is a Persian, admin-facing
     *                                      sentence either way.
     */
    public function testConnection(): array;

    /**
     * The panel's inbound list, normalised to the fields the bot provisions
     * against: id, remark, protocol, port. A driver whose panel has no
     * inbound concept returns [].
     *
     * @return array<int, array<string, mixed>>
     */
    public function listInbounds(): array;
}
