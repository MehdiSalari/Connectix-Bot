<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

require_once '../config.php';
require_once '../functions.php';

$data = json_decode(file_get_contents('broadcast_data.json'), true);
if (!$data) exit();

$message = $data['message'];
$mediaPath = $data['media'];
$isTest = $data['is_test'];
$adminChatId = $data['admin_chat_id'];

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
$userIds = [$adminChatId];
if (!$isTest) {
    $result = $conn->query("SELECT chat_id FROM users");
    $userIds = [];
    while ($row = $result->fetch_assoc()) $userIds[] = $row['chat_id'];
}

//for test use a chat id in userIds
$userIds = ['85023428',
            '123456789'
];

foreach ($userIds as $index => $chatId) {
    if (!$isTest && file_get_contents('broadcast_done.txt') > $index) continue;

    $params = ['chat_id' => $chatId];
    $method = 'sendMessage';
    $params['text'] = $message ?: ' ';

    if ($mediaPath) {
        $ext = strtolower(pathinfo($mediaPath, PATHINFO_EXTENSION));
        $mime = mime_content_type($mediaPath);

        if (strpos($mime, 'image') === 0 && $ext !== 'gif') $method = 'sendPhoto';
        elseif ($ext === 'gif') $method = 'sendAnimation';
        elseif (strpos($mime, 'video') === 0) $method = 'sendVideo';
        elseif (strpos($mime, 'audio') === 0 || in_array($ext, ['ogg'])) $method = 'sendVoice';
        else $method = 'sendDocument';

        $params[$method === 'sendPhoto' ? 'photo' : ($method === 'sendVideo' ? 'video' : ($method === 'sendAnimation' ? 'animation' : ($method === 'sendVoice' ? 'voice' : 'document')))] = new CURLFile($mediaPath);
        if ($message) $params['caption'] = $message;
    }

    $res = tg($method, $params);

    $res = json_decode($res);

    $status = $res && $res->ok ? 'success' : 'error';
    $msg = $res && $res->ok ? 'ارسال شد' : ($res->description ?? 'خطا');

    echo "data: " . json_encode(['type' => 'log', 'userId' => $chatId, 'status' => $status, 'message' => $msg]) . "\n\n";
    echo "data: " . json_encode(['type' => 'progress']) . "\n\n";

    ob_flush(); flush();
    file_put_contents('broadcast_done.txt', $index + 1);
    usleep(333000); // ~3 پیام در ثانیه
}

echo "data: " . json_encode(['type' => 'done']) . "\n\n";
flush();

@unlink('broadcast_data.json');
@unlink('broadcast_done.txt');
if ($mediaPath) @unlink($mediaPath);