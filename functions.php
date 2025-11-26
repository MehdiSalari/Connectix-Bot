<?php
if (!file_exists('config.php')) {
    header('Location: index.php');
}
require 'config.php';
define('BOT_TOKEN', $botToken);  // Bot token for authentication with Telegram API
define('TELEGRAM_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');  // Base URL for Telegram Bot API

function tg($method, $params = []) {
    if (!$params) {
        $params = array();
    }

    // Use method-specific endpoint (recommended for file uploads)
    $url = TELEGRAM_URL . $method;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    // Detect whether any param is a CURLFile (file upload)
    $hasFile = false;
    foreach ($params as $v) {
        if ($v instanceof CURLFile) {
            $hasFile = true;
            break;
        }
    }

    if ($hasFile) {
        // When uploading files, let cURL build multipart/form-data automatically
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        // Do NOT set Content-Type header here; cURL will set the proper multipart boundary
    } else {
        // Send JSON payload for simple requests (text, URLs, etc.)
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    }

    $result = curl_exec($ch);
    if ($result === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return json_encode(['ok' => false, 'error' => 'curl_error', 'description' => $err]);
    }
    curl_close($ch);
    return $result;
}

function errorLog($message) {
    // Add timestamp to the log entry
    file_put_contents('debug/error_log.log', date('Y-m-d H:i:s') . " - " . $message . "\n", FILE_APPEND);

    //send to telegram for admin
        //get admin chat id
    global $db_host, $db_user, $db_pass, $db_name;
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name); 
    $stmt = $conn->prepare("SELECT chat_id FROM admins WHERE role = 'admin'");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        //send message to admin
        $chat_id = $row['chat_id'];
        $result = tg('sendMessage',[
            'chat_id' => $chat_id,
            'text' => $message
        ]);
    }
    $stmt->close();
    $conn->close();
}

function keyboard($keyboard) {
    switch ($keyboard) {
        case "main_menu":
            //get name ffrom bot_config.json
            $data = file_get_contents('setup/bot_config.json');
            $config = json_decode($data, true);
            $supportTelegram = $config['support_telegram'] ?? '';
            $channelTelegram = $config['channel_telegram'] ?? '';
            $uid = UID;
            //check if user get test account
            global $db_host, $db_user, $db_pass, $db_name;
            $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
            $stmt = $conn->prepare("SELECT test FROM users WHERE chat_id = ?");
            $stmt->bind_param("s", $uid);
            $stmt->execute();
            //handle error
            if ($conn->connect_error || $stmt->error) {
                errorLog("Error in connecting to DB or preparing statement: " . ($conn->connect_error ?? $stmt->error));
            }
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            $conn->close();

            // include test button row only when user didn't get test account
            $test = ($user['test'] == 0) ? [
                ['text' => '🎁 | دریافت اکانت تست', 'callback_data' => 'get_test']
            ] : [];

            $keyboard = [
                // test row (may be empty)
                $test,
                [
                    ['text' => '📦 | اکانت های من', 'callback_data' => 'my_configs'],
                    ['text' => '🛒 | خرید اکانت جدید', 'callback_data' => 'buy']
                ],
                [
                    ['text' => '📱 | دانلود نرم افزار', 'callback_data' => 'apps'],
                    ['text' => '💡 | آموزش ها', 'callback_data' => 'guide']
                ],
                [
                    ['text' => '💁🏻‍♂️ | پشتیبانی', 'url' => "t.me/$supportTelegram"],
                ],
                [
                    ['text' => '📣 | اخبار و اطلاعیه ها', 'url' => "t.me/$channelTelegram"]
                ]
            ];
            break;
        default:
            return json_encode(['ok' => true]);
    }
    return json_encode(['inline_keyboard' => $keyboard]);
}

function message($message) {

    switch ($message) {
        case "welcome_message":
            //get name ffrom bot_config.json
            $data = file_get_contents('setup/bot_config.json');
            $config = json_decode($data, true);
            $welcomeMessage = $config['messages']['welcome_text'] ?? '';
            
            return $welcomeMessage;
        default:
            return "پیام پیشفرض";
    }
}