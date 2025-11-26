<?php
header('Content-Type: application/json');

$progressFile = __DIR__ . '/setup_progress.json';

if (file_exists($progressFile)) {
    $data = json_decode(file_get_contents($progressFile), true);
    echo json_encode($data);
} else {
    echo json_encode(['page' => 0, 'processedClients' => 0, 'insertedUsers' => 0, 'insertedClients' => 0, 'insertedPlans' => 0, 'skipped' => 0, 'total_clients' => 0]);
}
?>

