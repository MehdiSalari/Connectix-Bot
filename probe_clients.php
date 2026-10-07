<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Connectix\ConnectixService;

$svc = app(ConnectixService::class);

for ($p = 1; $p <= 3; $p++) {
    try {
        $payload = $svc->listClients($p);
    } catch (Throwable $e) {
        echo "page=$p ERROR: {$e->getMessage()}\n";
        continue
        ;
    }

    $clients = is_array($payload['clients'] ?? null) ? $payload['clients'] : [];
    $rows = is_array($clients['data'] ?? null) ? $clients['data'] : [];

    $ids = array_map(static fn ($r): string => (string) ($r['id'] ?? '?'), $rows);
    $first = $ids[0] ?? '-';
    $last = $ids === [] ? '-' : (string) end($ids);

    printf(
        "page=%d rows=%d first=%s last=%s total=%s last_page=%s per_page=%s next=%s topKeys=[%s] clientKeys=[%s]\n",
        $p,
        count($rows),
        $first,
        $last,
        json_encode($clients['total'] ?? $payload['total'] ?? null),
        json_encode($clients['last_page'] ?? null),
        json_encode($clients['per_page'] ?? null),
        json_encode($clients['next_page_url'] ?? $payload['next_page_url'] ?? null),
        implode(',', array_keys($payload)),
        implode(',', array_keys($clients)),
    );
}
