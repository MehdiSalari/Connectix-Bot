<?php
require 'config.php';
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

// Extract media details
$photo = $update['message']['photo'] ?? null;  // Photo in the message
$video = $update['message']['video'] ?? null;  // Video in the message
$voice = $update['message']['voice'] ?? null;  // Voice message
$video_note = $update['message']['video_note'] ?? null;  // Video message (circular video)
$document = $update['message']['document'] ?? null;  // Document/file in the message
$caption = $update['message']['caption'] ?? null;  // Caption for media (if any)


try {
    //debug
    // file_put_contents('debug/user_info.txt', "Chat ID: $chat_id\nUser: $user_name\nMessage: $text\n");
    switch ($text) {
        case '/start':
            $result = tg('sendMessage',[
                'chat_id' => $chat_id,
                'text' => 'به ربات شما خوش آمدید!'
            ]);
            break;
        default:
            $message = "متن پیشفرض پاسخ به کاربر";

            $result = tg('sendMessage',[
                'chat_id' => $chat_id,
                'text' => $message
            ]);

            break;
    }
} catch (Exception $e) {
    // Log any exceptions for debugging
    file_put_contents('debug/error.log', $e->getMessage());
}

