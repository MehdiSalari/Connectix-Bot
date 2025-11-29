<?php
require 'functions.php';

$content = file_get_contents('php://input');

// Save the content to a file for debugging
file_put_contents( 'debug/content.json', json_encode( json_decode( $content ), JSON_PRETTY_PRINT ));

// Decode the JSON content into a PHP associative array
$update = json_decode($content, true);

// Extract user message details from the update
$chat_id = $update['message']['chat']['id'] ?? null;  // User chat ID
$text = $update['message']['text'] ?? null;  // Text of the user’s message
$message_id = $update['message']['message_id'] ?? null;  // ID of the message
$user_id = $update['message']['chat']['username'] ?? null;  // Username of the user
$user_name = $update['message']['chat']['first_name'] ?? null;  // First name of the user
$forward_from_id = $update['message']['forward_from_chat']['id'] ?? null;  // Forwarded from chat

// Extract callback query details if available
$callback_chat_id = $update['callback_query']['message']['chat']['id'] ?? null;  // Chat ID from callback
$callback_data = $update['callback_query']['data'] ?? null;  // Data from the callback
$callback_id = $update['callback_query']['id'] ?? null;  // ID of the callback
$callback_message = $update['callback_query']['message']['text'] ?? null;  // Message text from callback
$callback_message_id = $update['callback_query']['message']['message_id'] ?? null;  // Message ID from callback
$callback_user_id = $update['callback_query']['message']['chat']['username'] ?? null;  // Username of the user
$callback_user_name = $update['callback_query']['message']['chat']['first_name'] ?? null;  // First name of the user
$bot_id = $update['callback_query']['from']['id'] ?? null;  // Bot ID from callback

// Extract media details
$photo = $update['message']['photo'] ?? null;  // Photo in the message
$video = $update['message']['video'] ?? null;  // Video in the message
$voice = $update['message']['voice'] ?? null;  // Voice message
$video_note = $update['message']['video_note'] ?? null;  // Video message (circular video)
$document = $update['message']['document'] ?? null;  // Document/file in the message
$caption = $update['message']['caption'] ?? null;  // Caption for media (if any)

$uid = $chat_id ?? $callback_chat_id;
define('UID', $uid);


try {
    //debug
    // file_put_contents('debug/user_info.txt', "Chat ID: $chat_id\nUser: $user_name\nMessage: $text\n");

    // Handle the user's message
    switch ($text) {
        case '/start':
            userInfo($uid, $user_id, $user_name);
            
            //send welcome message
            $result = tg('sendMessage',[
                'chat_id' => $chat_id,
                'text' => message('welcome_message'),
                'reply_markup' => keyboard('main_menu')
                // 'reply_markup' => json_encode(['remove_keyboard' => true])
            ]);
            if (!($result = json_decode($result))->ok) {
                errorLog("Failed to send /start message to chat_id: $uid | Message: {$result->description}");
                exit;
            }
            break;
        default:
            if ($text == null) {
                break;
            }
            $message = "متن پیشفرض پاسخ به کاربر";

            $result = tg('sendMessage',[
                'chat_id' => $chat_id,
                'text' => $message
            ]);

            break;
    }

    //Handle Callbacks
    switch ($callback_data) {
        case 'main_menu':
            userInfo($uid, $callback_user_id, $callback_user_name);

            $result = tg('editMessageText',[
                'chat_id' => $callback_chat_id,
                'message_id' => $callback_message_id,
                'text' => message('welcome_message'),
                'reply_markup' => keyboard('main_menu')
            ]);
            break;
        case 'get_test':
            $result = tg('editMessageText',[
                'chat_id' => $callback_chat_id,
                'message_id' => $callback_message_id,
                'text' => message('get_test'),
                'parse_mode' => 'html',
                'reply_markup' => keyboard('get_test')
            ]);
            break;
        case 'my_accounts':
            $result = tg('editMessageText',[
                'chat_id' => $callback_chat_id,
                'message_id' => $callback_message_id,
                'text' => message('my_accounts'),
                'reply_markup' => keyboard('my_accounts')
            ]);
            break;
        case 'not':
            //show notification that this btn is nothing
            $result = tg('answerCallbackQuery',[
                'callback_query_id' => $callback_id,
                'text' => "🤷🏻 این دکمه کاری انجام نمیده",
                // 'text' => $callback_message,
                'show_alert' => true
            ]);
            break;
        default:
            if ($callback_data == null) {
                break;
            }
            $result = callBackCheck($callback_data);
            $message = $result['message'];
            $keyboard = $result['keyboard'];

            $result = tg('editMessageText',[
                'chat_id' => $callback_chat_id,
                'message_id' => $callback_message_id,
                'text' => $message,
                'parse_mode' => 'html',
                'reply_markup' => $keyboard
            ]);
            break;
    }
    if ($result && !($result = json_decode($result))->ok) {
        errorLog("Error in sending message to chat_id: $uid | Message: {$result->description}");
        exit;
    }
} catch (Exception $e) {
    // Log any exceptions for debugging
    errorLog($e->getMessage() . " | " . $e->getTraceAsString());
}

