<?php
require_once '../../functions.php';
header('Content-Type: application/json');

$id = $_GET['id'] ?? '';
if (!$id) {
    echo json_encode(['error' => 'ID required']);
    exit;
}

$client = getClientData($id);
echo json_encode($client ? ['client' => $client] : ['error' => 'Client not found']);
?>