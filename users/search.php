<?php
// Prevent any output before JSON
ob_start();

session_start();

// Clear any output before headers
ob_clean();

// Set JSON header FIRST before any output
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_id']) || empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    ob_end_flush();
    exit;
}

// Load config from parent directory
$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'Config not found']);
    ob_end_flush();
    exit;
}
require_once $configFile;

// Get search query
$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$limit = max(1, min($limit, 500));

if (strlen($query) < 1) {
    echo json_encode(['results' => [], 'total' => 0, 'query' => '', 'displayed' => 0]);
    ob_end_flush();
    exit;
}

// Connect to database
$conn = new mysqli($db_host, $db_user, $db_pass ?? '', $db_name);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Connection failed']);
    ob_end_flush();
    exit;
}

$conn->set_charset("utf8mb4");

// Sanitize query for SQL LIKE
$searchQuery = '%' . $conn->real_escape_string($query) . '%';

// Search query - Simple with 4 LIKE conditions only
$sql = "
    SELECT 
        id, 
        name, 
        telegram_id, 
        chat_id, 
        email, 
        phone, 
        test, 
        created_at
    FROM users 
    WHERE 
        name LIKE ? 
        OR telegram_id LIKE ? 
        OR chat_id LIKE ?
        OR email LIKE ?
    ORDER BY created_at DESC
    LIMIT ?
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Query failed']);
    ob_end_flush();
    exit;
}

// Bind: 4 strings for LIKE + 1 int for LIMIT = 'ssssi'
$stmt->bind_param(
    'ssssi',
    $searchQuery,
    $searchQuery,
    $searchQuery,
    $searchQuery,
    $limit
);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Execution failed']);
    ob_end_flush();
    exit;
}

$result = $stmt->get_result();
$users = [];

while ($row = $result->fetch_assoc()) {
    $users[] = [
        'id' => (int)$row['id'],
        'name' => htmlspecialchars($row['name'] ?? '', ENT_QUOTES, 'UTF-8'),
        'telegram_id' => htmlspecialchars($row['telegram_id'] ?? '', ENT_QUOTES, 'UTF-8'),
        'chat_id' => htmlspecialchars($row['chat_id'] ?? '', ENT_QUOTES, 'UTF-8'),
        'email' => htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8'),
        'phone' => htmlspecialchars($row['phone'] ?? '', ENT_QUOTES, 'UTF-8'),
        'test' => (int)($row['test'] ?? 0),
        'created_at' => $row['created_at']
    ];
}

$stmt->close();

// Get total count
$countSql = "
    SELECT COUNT(*) as total FROM users 
    WHERE 
        name LIKE ? 
        OR telegram_id LIKE ? 
        OR chat_id LIKE ?
        OR email LIKE ?
";

$countStmt = $conn->prepare($countSql);
if (!$countStmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Count failed']);
    ob_end_flush();
    exit;
}

$countStmt->bind_param('ssss', $searchQuery, $searchQuery, $searchQuery, $searchQuery);

if (!$countStmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Count exec failed']);
    ob_end_flush();
    exit;
}

$countResult = $countStmt->get_result();
$countRow = $countResult->fetch_assoc();
$total = (int)($countRow['total'] ?? 0);

$countStmt->close();
$conn->close();

// Send response
echo json_encode([
    'results' => $users,
    'total' => $total,
    'query' => htmlspecialchars($query, ENT_QUOTES, 'UTF-8'),
    'displayed' => count($users)
], JSON_UNESCAPED_UNICODE);

ob_end_flush();
?>
