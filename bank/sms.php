<?php
// sms.php
header('Content-Type: application/json');

// allowed methods: POST
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode([
        'status' => 'error',
        'message' => 'Method not allowed'
    ]);
    exit;
}

// Allow only JSON content type
$contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';
if (!in_array($contentType, ["application/json", "application/json; charset=utf-8"])) {
    http_response_code(406); // Not Acceptable
    echo json_encode([
        'status' => 'error',
        'message' => 'Content type must be application/json or application/json; charset=utf-8'
    ]);
    exit;
}

// Read the input data
$data = json_decode(file_get_contents('php://input'), true);

// Save data to a file for debugging
file_put_contents('../debug/sms_debug.json', json_encode($data, JSON_PRETTY_PRINT));

// Validate the input data
if ( !isset($data['msg'])) {
    http_response_code(400); // Bad Request
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing required field [msg]'
    ]);
    exit;
}

$botConfig = json_decode(file_get_contents('../setup/bot_config.json'), true);
$adminBank = $botConfig['bank']['name'] ?? null;
$botNotice = $botConfig['bank']['bot_notice'] ?? false;
$adminID = $botConfig['admin_id'] ?? '';

$message = trim($data['msg']);
$banks = json_decode(file_get_contents('banks.json'), true)['banks'] ?? [];

// Find the bank method
$bankMethod = '';
foreach ($banks as $bank) {
    if ($bank['name'] === $adminBank) {
        $bankMethod = $bank['method'];
        break;
    }
}

if (empty($bankMethod)) {
    http_response_code(400); // Bad Request
    echo json_encode([
        'status' => 'error',
        'message' => 'Bank method not configured properly, please check admin panel settings.'
    ]);
    exit;
}

// extract the amount from data
$amount = -1; // default value if not found
if (preg_match($bankMethod, $message, $matches)) {
    // Remove commas from the amount
    $amount = str_replace(',', '', $matches[1]);
    // Convert Rial to Toman
    $amount = (int)($amount / 10);
}

require '../functions.php';

if ($amount === -1) {
    http_response_code(500); // Internal Server Error
    echo json_encode([
        'status' => 'error',
        'message' => 'Amount not found in message',
        'bank' => $adminBank
    ]);
    errorLog("Failed to extract amount from message: $message | For bank: $adminBank");
    exit;
}

// Check if amount is greater than 0
if ($amount <= 0) {
    http_response_code(400); // Bad Request
    echo json_encode([
        'status' => 'error',
        'message' => 'Amount must be greater than 0',
        'bank' => $adminBank
    ]);
    exit;
}

$smsResult = smsPayment('save', [
    'message' => $message,
    'amount' => $amount,
    'bank' => $adminBank
]);

if (!$smsResult) {
    exit;
}

if ($botNotice) {
    $tgResult = tg('sendMessage', [
        'chat_id' => $adminID,
        'text' => "💰 <b>New SMS Payment Received</b>\n\n" .
                  "🏦 <b>Bank:</b> " . htmlspecialchars($adminBank) . "\n" .
                  "💵 <b>Amount:</b> " . number_format($amount) . " Toman\n\n" .
                  "<i>Message:</i>\n" . htmlspecialchars($message),
        'parse_mode' => 'HTML'
    ]);

    if ($tgResult && !($result = json_decode($tgResult))->ok) {
        errorLog("Error in sending message to chat_id: $adminID | Message: {$result->description}");
        exit;
    }
}


// Return success response
http_response_code(202); // Accepted
echo json_encode([
    'status' => 'success',
    'data' => ['amount' => number_format($amount), 'bank' => $adminBank]
]);