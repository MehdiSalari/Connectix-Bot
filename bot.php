<?php
require 'functions.php';

// Check for request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('location: index.php');
    exit;
}

// Get the content of the request
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
define('CBID', $callback_id);
define('CBMID', $callback_message_id);

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
            if ($text == null && $photo == null) {
                break;
            }

            $redis = new Redis();
            $redis->connect('127.0.0.1', 6379);
            $RedisData = $redis->hgetall("user:steps:$uid");
            $redis->close();

            if ($RedisData['pay'] && $RedisData['action'] != 'discount') {
                // Check if send just image
                if ($photo == null) {
                    $message = "🖼️ لطفا سند واریزی را به صورت تصویر ارسال کنید!";
                    $result = tg('sendMessage',[
                        'chat_id' => $uid,
                        'text' => $message,
                        'reply_markup' => json_encode([
                                'inline_keyboard' => [
                                    [
                                        ['text' => '❌ | انصراف', 'callback_data' => 'main_menu'],
                                    ]
                                ]
                            ])
                        ]);
                    if (!($result = json_decode($result))->ok) {
                        errorLog("Failed to send receipt error message to chat_id: $uid | Message: {$result->description}");
                        exit;
                    }
                    break;
                }

                $payment = payment($photo, 'buy');

                if (!$payment) {
                    errorLog("Failed to send receipt error message to chat_id: $uid");
                }
                break;
            }

            if ($RedisData['action'] == 'add_account') {
                switch($RedisData['step']) {
                    case 'get_username':
                        $username = $text;
                        addAccount("get_password", $username);
                        
                        $result = tg('sendMessage',[
                            'chat_id' => $uid,
                            'text' => "نام کاربری واردشده: $username\nلطفا رمزعبور حساب را وارد نمایید",
                        ]);
                        if (!($result = json_decode($result))->ok) {
                            errorLog("Failed to send receipt error message to chat_id: $uid | Message: {$result->description}");
                            exit;
                        }
                        break;
                    case 'get_password':
                        $password = $text;
                        $message = addAccount("add_account", $password);
                        $result = tg('sendMessage',[
                            'chat_id' => $uid,
                            'text' => $message,
                            'reply_markup' => json_encode([
                                    'inline_keyboard' => [
                                        [
                                            ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
                                        ]
                                    ]
                            ])
                        ]);
                        if (!($result = json_decode($result))->ok) {
                            errorLog("Failed to send receipt error message to chat_id: $uid | Message: {$result->description}");
                            exit;
                        }
                        break;
                }
            } elseif ($RedisData['action'] == 'wallet_increase') {
                switch($RedisData['step']) {
                    case 'get_amount':
                        //check text for amount
                        if (!is_numeric($text)) {
                            $message = "🔢 لطفا مبلغ را به صورت عداد انگلیسی وارد کنید!";
                            $result = tg('sendMessage',[
                                'chat_id' => $uid,
                                'text' => $message,
                            ]);
                                
                            if (!($result = json_decode($result))->ok) {
                                errorLog("Failed to send receipt error message to chat_id: $uid | Message: {$result->description}");
                                exit;
                            }
                            break;
                        }
        
                        $amount = (int)$text;
                        if ($amount < 10000) {
                            $message = "💲 حداقل مبلغ واریزی 10.000 تومان است!";
                            $result = tg('sendMessage',[
                                'chat_id' => $uid,
                                'text' => $message,
                            ]);
                                
                            if (!($result = json_decode($result))->ok) {
                                errorLog("Failed to send receipt error message to chat_id: $uid | Message: {$result->description}");
                                exit;
                            }
                            break;
                        }
        
                        $redis = new Redis();
                        $redis->connect('127.0.0.1', 6379);
                        $key = "user:steps:" . UID;
                        $redis->hmset($key, ['action' => 'wallet_increase', 'step' => 'get_receipt', 'amount' => $amount]);
                        $redis->expire($key, 1800);
                        $redis->close();
        
                        $variables = [
                            'amount' => $amount
                        ];
        
                        $result = tg('sendMessage',[
                            'chat_id' => $uid,
                            'text' => message('card', $variables),
                            'reply_markup' => json_encode([
                                    'inline_keyboard' => [
                                        [
                                            ['text' => '❌ | لغو', 'callback_data' => "wallet"],
                                        ]
                                    ]
                            ])
                        ]);
                        if (!($result = json_decode($result))->ok) {
                            errorLog("Failed to send receipt error message to chat_id: $uid | Message: {$result->description}");
                            exit;
                        }
                        break;
                    
                    case 'get_receipt':
                        // Check if send just image
                        if ($photo == null) {
                            $message = "🖼️ لطفا سند واریزی را به صورت تصویر ارسال کنید!";
                            $result = tg('sendMessage',[
                                'chat_id' => $uid,
                                'text' => $message,
                                'reply_markup' => json_encode([
                                        'inline_keyboard' => [
                                            [
                                                ['text' => '❌ | انصراف', 'callback_data' => 'wallet'],
                                            ]
                                        ]
                                    ])
                                ]);
                            if (!($result = json_decode($result))->ok) {
                                errorLog("Failed to send receipt error message to chat_id: $uid | Message: {$result->description}");
                                exit;
                            }
                            break;
                        }

                        $redis = new Redis();
                        $redis->connect('127.0.0.1', 6379);
                        $key = "user:steps:" . UID;
                        $walletData = $redis->hgetall($key);
                        $amount = $walletData['amount'];

                        $walletID = wallet('get', $uid)['id'];
                        $txID = createWalletTransaction(null, 'PENDING', $walletID, $amount, 'INCREASE', UID, 'CARD_TO_CARD');
                        
                        $redis->hmset($key, ['action' => 'wallet_increase', 'step' => 'pending', 'txID' => $txID]);
                        $redis->expire($key, 1800);
                        $redis->close();
                        


                        $payment = payment($photo, 'wallet');

                        // if (!$payment) {
                        //     errorLog("Failed to send receipt error message to chat_id: $uid");
                        // }
                }
            } elseif ($RedisData['action'] == 'discount') {
                $couponCode = $text;
                $coupon = checkCoupon($couponCode);

                // errorLog("coupon: " . json_encode($coupon));

                //check is valid
                if ($coupon) {
                    // Check if active
                    if ($coupon['is_active'] == true) {
                        $dt = new DateTime('now', new DateTimeZone('Asia/Tehran'));
                        $now = $dt->format('Y-m-d\TH:i:s.u\Z');  // UTC-formatted string for comparison

                        $couponStart = $coupon['start_date_text'] ?? null;
                        $couponEnd = $coupon['end_date_text'] ?? null;

                        // New logic: 
                        // - If start_date_text exists, check $now >= $couponStart (coupon has started)
                        // - If end_date_text exists, check $now <= $couponEnd (not yet expired)
                        // - If either is null, skip that check (treat as always true for that part)
                        $hasStarted = is_null($couponStart) || ($now >= $couponStart);
                        $notExpired = is_null($couponEnd) || ($now <= $couponEnd);

                        if ($hasStarted && $notExpired) {
                            // Check for plans
                            if ($coupon['is_applied_to_all_plans'] == true) {
                                // Accept
                                $discountResult = discount("apply:null", $coupon);
                            } else {
                                $planID = $RedisData['plan'];
                                $couponPlans = $coupon['plans_ids'];
                                if (in_array($planID, $couponPlans)) {
                                    // Accept
                                    $discountResult = discount("apply:null", $coupon);
                                } else {
                                    $errorMessage = "🚫 کد تخفیف وارد شده برای پلن انتخابی شما نمیباشد!🚫";
                                }
                            }
                        } else {
                            $errorMessage = "🚫 کد تخفیف وارد شده منقضی شده یا هنوز فعال نشده است!🚫";
                        }
                    } else {
                        $errorMessage = "🚫 کد تخفیف وارد شده غیرفعال است!🚫";
                    }
                } else {
                    $errorMessage = "🚫 کد تخفیف وارد شده صحیح نمی باشد!🚫";
                }
                    
                if ($errorMessage) {
                    $result = tg('sendMessage',[
                        'chat_id' => $uid,
                        'text' => $errorMessage,
                        'reply_markup' => json_encode([
                                'inline_keyboard' => [
                                    [
                                        ['text' => '❌ | انصراف', 'callback_data' => 'pay_card:' . $RedisData['price']],
                                    ]
                                ]
                            ])
                        ]);
                    if (!($result = json_decode($result))->ok) {
                        errorLog("Failed to send receipt error message to chat_id: $uid | Message: {$result->description}");
                    }
                    exit;
                }

                if ($discountResult) {
                    $variables = [
                        'amount' => $discountResult
                    ];
                    $messsage = message('card', $variables);
                    $keyboard = json_encode([
                        'inline_keyboard' => [
                            [
                                ['text' => "🎟 | کد تخفیف « $couponCode » اعمال شد", 'callback_data' => 'not'],
                            ],
                            [
                                ['text' => '❌ | انصراف', 'callback_data' => 'main_menu'],
                            ]
                        ]
                    ]);
                    $tgResult = tg('sendMessage',[
                        'chat_id' => $uid,
                        'text' => $messsage,
                        'reply_markup' => $keyboard
                    ]);
                }
                
                break;
            }
    }

    $result = null;
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
        case 'new_menu':
            userInfo($uid, $callback_user_id, $callback_user_name);

            $result = tg('sendMessage',[
                'chat_id' => $callback_chat_id,
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
        case 'accounts':
            $result = tg('editMessageText',[
                'chat_id' => $callback_chat_id,
                'message_id' => $callback_message_id,
                'text' => message('accounts'),
                'reply_markup' => keyboard('accounts')
            ]);
            break;
        case 'add_account':
            $result = tg('editMessageText',[
                'chat_id' => $callback_chat_id,
                'message_id' => $callback_message_id,
                'text' => message('add_account'),
                'reply_markup' => keyboard('add_account')
            ]);
            addAccount('get_username');
            break;
        case 'group':
            $result = tg('editMessageText',[
                'chat_id' => $callback_chat_id,
                'message_id' => $callback_message_id,
                'text' => message('group'),
                'parse_mode' => 'html',
                'reply_markup' => keyboard('group')
            ]);
            break;
        case 'count':
            $result = tg('editMessageText',[
                'chat_id' => $callback_chat_id,
                'message_id' => $callback_message_id,
                'text' => message('count'),
                'parse_mode' => 'html',
                'reply_markup' => keyboard('count')
            ]);
            break;
        case 'action:buy_or_renew_service':
        case 'buy':
            $result = tg('editMessageText',[
                'chat_id' => $callback_chat_id,
                'message_id' => $callback_message_id,
                'text' => message('buy'),
                'parse_mode' => 'html',
                'reply_markup' => keyboard('buy')
            ]);
            break;
        case 'renew':
            $result = tg('editMessageText',[
                'chat_id' => $callback_chat_id,
                'message_id' => $callback_message_id,
                'text' => message('renew'),
                'parse_mode' => 'html',
                'reply_markup' => keyboard('renew')
            ]);
            break;
        case 'apps':
            $result = tg('editMessageText',[
                'chat_id' => $callback_chat_id,
                'message_id' => $callback_message_id,
                'text' => message('apps'),
                'parse_mode' => 'html',
                'reply_markup' => keyboard('apps')
            ]);
            break;
        case 'guide':
            $result = tg('sendMessage',[
                'chat_id' => $callback_chat_id,
                'text' => message('guide'),
                'parse_mode' => 'html',
                'reply_markup' => keyboard('guide')
            ]);
            tg('deleteMessage',[
                'chat_id' => $callback_chat_id,
                'message_id' => $callback_message_id
            ]);
            break;
        case 'faq':
            $result = tg('editMessageText',[
                'chat_id' => $callback_chat_id,
                'message_id' => $callback_message_id,
                'text' => message('faq'),
                'parse_mode' => 'html',
                'reply_markup' => keyboard('faq')
            ]);
            break;
        case 'support':
            $result = tg('editMessageText',[
                'chat_id' => $callback_chat_id,
                'message_id' => $callback_message_id,
                'text' => message('support'),
                'parse_mode' => 'html',
                'reply_markup' => keyboard('support')
            ]);
            break;
        case 'wallet':
            $redis = new Redis();
            $redis->connect('127.0.0.1', 6379);
            $redis->del("user:steps:" . UID);
            $redis->close();

            $walletBalance = wallet('get', $callback_chat_id)['balance'];

            // check if user has wallet in database create it
            if ($walletBalance == null) {
                $createWallet = wallet('create', $callback_chat_id, '0');
                if (!$createWallet) {
                    errorLog("Error in creating wallet for user {$callback_chat_id}");
                    die();
                }
                $walletBalance = '0';
            }

            $user = getUser($callback_chat_id);
            $userName = $user['name'] 
                ?? ($user['telegram_id'] ? '@' . $user['telegram_id'] : 'نامشخص');
            $variables = [
                'walletBalance' => number_format($walletBalance),
                'userName' => $userName
            ];
            $result = tg('editMessageText',[
                'chat_id' => $callback_chat_id,
                'message_id' => $callback_message_id,
                'text' => message('wallet', $variables),
                'parse_mode' => 'html',
                'reply_markup' => keyboard('wallet')
            ]);
            break;
        case 'not':
            //show notification that this btn is nothing
            $result = tg('answerCallbackQuery',[
                'callback_query_id' => $callback_id,
                'text' => "🤷🏻 این دکمه کاری انجام نمیده",
                'show_alert' => true
            ]);
            break;
        default:
            if ($callback_data == null) {
                break;
            }
            $params = callBackCheck($callback_data);

            if (!empty($params) && is_array($params)) {
                $method = (isset($params['caption'])) ? 'editMessageCaption' : 'editMessageText';
                $result = tg($method, array_merge([
                    'chat_id' => $callback_chat_id,
                    'message_id' => $callback_message_id,
                    'parse_mode' => 'html'
                ], $params));

                if (!($result = json_decode($result))->ok) {
                    errorLog("Error in sending message to chat_id: $uid | Message: {$result->description}");
                    exit;
                }
            }

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

