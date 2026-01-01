<?php
require_once '../../functions.php';
header('Content-Type: application/json');

$id = $_GET['id'] ?? 0;
if (!$id) {
    echo json_encode(['error' => 'شناسه نامعتبر']);
    exit;
}

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
$stmt = $conn->prepare("SELECT message FROM sms_payments WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

echo json_encode($row ? ['message' => $row['message']] : ['error' => 'پیام یافت نشد']);
$stmt->close();
$conn->close(); 