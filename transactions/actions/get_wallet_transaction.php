<?php
require_once '../../functions.php';
$id = $_GET['id'] ?? 0;
$data = getWalletTransactions(1, 1, null)['transactions'][0] ?? null; // فقط یکی با این id
if ($data && $data['id'] == $id) {
    echo json_encode($data);
} else {
    echo json_encode(['error' => 'تراکنش یافت نشد']);
}