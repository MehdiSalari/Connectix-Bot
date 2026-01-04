<?php
require_once '../../functions.php';
$id = $_GET['id'] ?? 0;
$data = getTransactions(1, 1, null, $id)['transactions'][0] ?? null; // فقط یکی با این id
switch ($data['id']) {
    case $_GET['id']:
        echo json_encode($data);
        break;
    default:
        echo json_encode(['error' => 'تراکنش یافت نشد']);
        break;
}