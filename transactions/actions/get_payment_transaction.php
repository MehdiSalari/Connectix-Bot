<?php
require_once '../../functions.php';
$id = $_GET['id'] ?? 0;
$data = getTransactions(1, 1, null)['transactions'][0] ?? null; // فقط یکی با این id
// var_dump($data);
// die;
if ($data) {
    echo json_encode($data);
} else {
    echo json_encode(['error' => 'تراکنش یافت نشد']);
}