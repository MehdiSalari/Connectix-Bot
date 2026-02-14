<?php
session_start();
if (!isset($_SESSION['admin_id'])) die('Unauthorized');

require_once '../config.php';
require_once '../functions.php';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) die("DB Error");

$stmt = $conn->prepare("SELECT chat_id FROM admins WHERE id = ?");
$stmt->bind_param("i", $_SESSION['admin_id']);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();
$adminChatId = $admin['chat_id'] ?? null;

if (!$adminChatId || $adminChatId == 0) {
    die("chat_id ادمین تنظیم نشده!");
}

$isTest = isset($_POST['test']) || isset($_GET['test']); // پشتیبانی از هر دو
$message = $_POST['message'] ?? '';

$mediaPath = null;
if (isset($_FILES['media']) && $_FILES['media']['error'] === 0) {
    $dir = __DIR__.'/uploads/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $mediaPath = $dir . time() . '_' . basename($_FILES['media']['name']);
    move_uploaded_file($_FILES['media']['tmp_name'], $mediaPath);
}

// اگر حالت تست باشه → مستقیم بفرست به ادمین و تموم
if ($isTest) {
    $result = null;

    if ($mediaPath) {
        $ext = strtolower(pathinfo($mediaPath, PATHINFO_EXTENSION));
        $mime = mime_content_type($mediaPath);
        
        // Convert local path to HTTPS URL (for images/videos that support URL)
        $fileName = basename($mediaPath);
        $mediaUrl = 'https://' . $_SERVER['HTTP_HOST'] . str_replace("broadcast_start.php", "/uploads/$fileName", $_SERVER['REQUEST_URI']);

        if (strpos($mime, 'image/') === 0 && $ext !== 'gif') {
            $result = tg('sendPhoto', [
                'chat_id' => $adminChatId,
                'photo' => $mediaUrl,
                'caption' => $message,
                'parse_mode' => 'HTML'
            ]);
        } elseif ($ext === 'gif' || strpos($mime, 'image/gif') === 0) {
            $result = tg('sendAnimation', [
                'chat_id' => $adminChatId,
                'animation' => new CURLFile($mediaPath),
                'caption' => $message,
                'parse_mode' => 'HTML'
            ]);
        } elseif (strpos($mime, 'video/') === 0) {
            $result = tg('sendVideo', [
                'chat_id' => $adminChatId,
                'video' => new CURLFile($mediaPath),
                'caption' => $message,
                'parse_mode' => 'HTML'
            ]);
        } elseif (strpos($mime, 'audio/') === 0 || $ext === 'ogg') {
            $result = tg('sendVoice', [
                'chat_id' => $adminChatId,
                'voice' => new CURLFile($mediaPath),
                'caption' => $message,
                'parse_mode' => 'HTML'
            ]);
        } else {
            // For documents like .docx, .pdf, etc., use CURLFile with local path
            $result = tg('sendDocument', [
                'chat_id' => $adminChatId,
                'document' => new CURLFile($mediaPath),
                'caption' => $message,
                'parse_mode' => 'HTML'
            ]);
        }
    } else {
        $result = tg('sendMessage', [
            'chat_id' => $adminChatId,
            'text' => $message ?: 'تست موفق از پنل ادمین',
                'parse_mode' => 'HTML'
        ]);
    }

    $result = json_decode($result, true);

    header('Content-Type: application/json');
    
    // دیباگ بهتر
    if ($result && isset($result['ok']) && $result['ok']) {
        echo json_encode([
            'success' => true,
            'message' => 'تست با موفقیت ارسال شد!',
            'parse_mode' => 'HTML'
        ]);
    } else {
        $errorMsg = 'خطا در ارسال تست';
        $errorDescription = '';
        
        if ($result) {
            if (isset($result['description'])) {
                $errorDescription = $result['description'];
            }
            if (isset($result['error_code'])) {
                $errorMsg .= ' (Error Code: ' . $result['error_code'] . ')';
            }
        }
        
        echo json_encode([
            'success' => false,
            'message' => $errorMsg,
            'description' => $errorDescription,
            'response' => $result
        ]);
    }

    // پاک کردن فایل بعد از ارسال تست
    if ($mediaPath && file_exists($mediaPath)) {
        @unlink($mediaPath);
    }
    exit();
}

// اگر تست نبود → برو برای ارسال همگانی
file_put_contents('broadcast_data.json', json_encode([
    'message' => $message,
    'media' => $mediaPath,
    'is_test' => false,
    'admin_chat_id' => $adminChatId
]));
file_put_contents('broadcast_done.txt', '0');

echo "شروع ارسال همگانی...";