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

$botConfig = [];
if (file_exists(__DIR__ . '/setup/bot_config.json')) {
    $botConfig = json_decode(file_get_contents(__DIR__ . '/setup/bot_config.json'), true) ?: [];
}

$adminIds = array_map('strval', array_filter([
    $botConfig['admin_id'] ?? null,
    $botConfig['admin_id_2'] ?? null,
    $botConfig['admin_id_3'] ?? null,
], fn($value) => $value !== null && $value !== ''));

$isBotActive = $botConfig['bot_active'] ?? true;
$isAdminRequest = $uid !== null && in_array((string) $uid, $adminIds, true);

if (!$isBotActive && !$isAdminRequest) {
    if ($callback_id) {
        tg('answerCallbackQuery', [
            'callback_query_id' => $callback_id,
            'text' => 'ربات موقتاً غیرفعال است 💤',
            'show_alert' => true
        ]);
    } elseif ($uid !== null) {
        tg('sendMessage', [
            'chat_id' => $uid,
            'text' => 'ربات موقتاً غیرفعال است 💤'
        ]);
    }
    exit;
}

try {
    
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
                errorLog("Failed to send /start message to chat_id: $uid | Message: {$result->description}", "bot.php", 67);
                exit;
            }
            break;
        default:
            if ($text == null && $photo == null) {
                break;
            }

            $actionData = actionStep('get', $uid);

            if ($actionData['pay'] && $actionData['action'] != 'discount') {
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
                        errorLog("Failed to send receipt error message to chat_id: $uid | Message: {$result->description}", "bot.php", 97);
                        exit;
                    }
                    break;
                }

                $payment = payment($photo, 'buy');

                if (!$payment) {
                    errorLog("Failed to send receipt error message to chat_id: $uid", "bot.php", 106);
                }
                break;
            }

            if ($actionData['action'] == 'add_account') {
                switch($actionData['step']) {
                    case 'get_username':
                        $username = $text;
                        addAccount("get_password", $username);
                        
                        $result = tg('sendMessage',[
                            'chat_id' => $uid,
                            'text' => "نام کاربری واردشده: $username\nلطفا رمزعبور حساب را وارد نمایید",
                            'reply_markup' => json_encode([
                                    'inline_keyboard' => [
                                        [
                                            ['text' => '❌ | انصراف', 'callback_data' => 'acounts'],
                                        ]
                                    ]
                            ])
                        ]);
                        if (!($result = json_decode($result))->ok) {
                            errorLog("Failed to send receipt error message to chat_id: $uid | Message: {$result->description}", "bot.php", 122);
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
                                            ['text' => '↪️ | بازگشت', 'callback_data' => 'accounts'],
                                        ]
                                    ]
                            ])
                        ]);
                        if (!($result = json_decode($result))->ok) {
                            errorLog("Failed to send receipt error message to chat_id: $uid | Message: {$result->description}", "bot.php", 141);
                            exit;
                        }
                        break;
                }
            } elseif ($actionData['action'] == 'wallet_increase') {
                switch($actionData['step']) {
                    case 'get_amount':
                        //check text for amount
                        if (!is_numeric($text)) {
                            $message = "🔢 لطفا مبلغ را به صورت اعداد انگلیسی وارد کنید!";
                            $result = tg('sendMessage',[
                                'chat_id' => $uid,
                                'text' => $message,
                            ]);
                                
                            if (!($result = json_decode($result))->ok) {
                                errorLog("Failed to send receipt error message to chat_id: $uid | Message: {$result->description}", "bot.php", 158);
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
                                errorLog("Failed to send receipt error message to chat_id: $uid | Message: {$result->description}", "bot.php", 173);
                                exit;
                            }
                            break;
                        }
        
                        $stepData = [
                            'action' => 'wallet_increase',
                            'step' => 'get_receipt',
                            'amount' => $amount
                        ];
                        actionStep('set', $uid, $stepData);
        
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
                            errorLog("Failed to send receipt error message to chat_id: $uid | Message: {$result->description}", "bot.php", 202);
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
                                errorLog("Failed to send receipt error message to chat_id: $uid | Message: {$result->description}", "bot.php", 223);
                                exit;
                            }
                            break;
                        }

                        $walletData = actionStep('get', $uid);
                        $amount = $walletData['amount'];

                        $walletID = wallet('get', $uid)['id'];
                        $txID = createWalletTransaction(null, 'PENDING', $walletID, $amount, 'INCREASE', UID, 'CARD_TO_CARD');
                        
                        $stepData = [
                            'action' => 'wallet_increase',
                            'step' => 'pending',
                            'amount' => $amount,
                            'txID' => $txID
                        ];
                        actionStep('set', $uid, $stepData);

                        $payment = payment($photo, 'wallet');
                }
            } elseif ($actionData['action'] == 'discount') {
                $couponCode = $text;
                $coupon = checkCoupon($couponCode);

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
                                $planID = $actionData['plan'];
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
                                        ['text' => '❌ | انصراف', 'callback_data' => 'pay_card:' . $actionData['price']],
                                    ]
                                ]
                            ])
                        ]);
                    if (!($result = json_decode($result))->ok) {
                        errorLog("Failed to send receipt error message to chat_id: $uid | Message: {$result->description}", "bot.php", 309);
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
                'parse_mode' => 'Markdown',
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
                'parse_mode' => 'Markdown',
                'reply_markup' => keyboard('group')
            ]);
            break;
        case 'count':
            $result = tg('editMessageText',[
                'chat_id' => $callback_chat_id,
                'message_id' => $callback_message_id,
                'text' => message('count'),
                'parse_mode' => 'Markdown',
                'reply_markup' => keyboard('count')
            ]);
            break;
        case 'action:buy_or_renew_service':
        case 'buy':
            $result = tg('editMessageText',[
                'chat_id' => $callback_chat_id,
                'message_id' => $callback_message_id,
                'text' => message('buy'),
                'parse_mode' => 'Markdown',
                'reply_markup' => keyboard('buy')
            ]);
            break;
        case 'renew':
            $result = tg('editMessageText',[
                'chat_id' => $callback_chat_id,
                'message_id' => $callback_message_id,
                'text' => message('renew'),
                'parse_mode' => 'Markdown',
                'reply_markup' => keyboard('renew')
            ]);
            break;
        case 'apps':
            $result = tg('editMessageText',[
                'chat_id' => $callback_chat_id,
                'message_id' => $callback_message_id,
                'text' => message('apps'),
                'parse_mode' => 'Markdown',
                'reply_markup' => keyboard('apps')
            ]);
            break;
        case 'guide':
            $result = tg('sendMessage',[
                'chat_id' => $callback_chat_id,
                'text' => message('guide'),
                'parse_mode' => 'Markdown',
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
                'parse_mode' => 'Markdown',
                'reply_markup' => keyboard('faq')
            ]);
            break;
        case 'support':
            $result = tg('editMessageText',[
                'chat_id' => $callback_chat_id,
                'message_id' => $callback_message_id,
                'text' => message('support'),
                'parse_mode' => 'Markdown',
                'reply_markup' => keyboard('support')
            ]);
            break;
        case 'wallet':
            actionStep('clear', $callback_chat_id);

            $walletBalance = wallet('get', $callback_chat_id)['balance'];

            // check if user has wallet in database create it
            if ($walletBalance == null) {
                $createWallet = wallet('create', $callback_chat_id, '0');
                if (!$createWallet) {
                    errorLog("Error in creating wallet for user {$callback_chat_id}", "bot.php", 476);
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
                'parse_mode' => 'Markdown',
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
                $result = tg($method, params: array_merge([
                    'chat_id' => $callback_chat_id,
                    'message_id' => $callback_message_id,
                    'parse_mode' => 'Markdown'
                ], $params));

                $result = json_decode($result);

                if (!$result->ok) {
                    if (strpos($result->description, 'message is not modified') !== false) {
                        break;
                    }

                    errorLog(
                        "Error in sending message to chat_id: $uid | Message: {$result->description}",
                        "bot.php",
                        526
                    );
                    exit;
                }
            }

            break;
    }
    if ($result && !($result = json_decode($result))->ok) {
        errorLog("Error in sending message to chat_id: $uid | Message: {$result->description}", "bot.php", 528);
        exit;
    }
} catch (Exception $e) {
    // Log any exceptions for debugging
    errorLog($e->getMessage() . " | " . $e->getTraceAsString(), "bot.php", 533);
}

