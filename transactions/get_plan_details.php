<?php
require_once '../functions.php';
header('Content-Type: application/json');

$id = $_GET['id'] ?? '';
if (!$id) {
    echo json_encode(['error' => 'ID required']);
    exit;
}

$plan = getSellerPlans($id);
echo json_encode($plan ?: ['error' => 'Plan not found']);
?>