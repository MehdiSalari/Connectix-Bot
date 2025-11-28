<?php
if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: index.php');
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/gregorian_jalali.php';
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

function userInfo($chat_id, $user_id, $user_name) {
    global $db_host, $db_user, $db_pass, $db_name;
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
            if ($conn->connect_error) {
                errorLog("Connection failed: " . $conn->connect_error);
            }
            $stmt = $conn->prepare("SELECT * FROM users WHERE chat_id = ?");
            $stmt->bind_param("i", $chat_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            if ($user) {
                $stmt = $conn->prepare("UPDATE users SET telegram_id = ?, name = ? WHERE chat_id = ?");
                $stmt->bind_param("ssi", $user_id, $user_name, $chat_id);
                $result = $stmt->execute();
            } else {
                $stmt = $conn->prepare("INSERT INTO users (chat_id, telegram_id, name, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->bind_param("ssi", $chat_id, $user_id, $user_name);
                $result = $stmt->execute();
            }
            $stmt->close();
            $conn->close();
}

function jdate($timestamp, $str) {
    $date = explode(' ', $timestamp);
    $time = explode(':', $date[1]);
    $year = (int)date('Y', strtotime($date[0] . ' ' . $time[0] . ':' . $time[1] . ':' . $time[2]));
    $month = (int)date('m', strtotime($date[0] . ' ' . $time[0] . ':' . $time[1] . ':' . $time[2]));
    $day = (int)date('d', strtotime($date[0] . ' ' . $time[0] . ':' . $time[1] . ':' . $time[2]));
    $date = gregorian_to_jalali($year, $month, $day, $str);
    return $date;
    // return $year ;
}   

function getAdminById($id) {
    global $db_host, $db_user, $db_pass, $db_name;
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name); 
    $stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $admin = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $admin;
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

function callBackCheck($callback_data) {
    //check first part of data
    $data = explode('_', $callback_data);
    $cmd = $data[0];
    $query = $data[1];
    
    switch ($cmd) {
        case "getClient":
            $result = getClientData($query);
            return $result;
        default:
            return false;
    }
}

function getClientData($cid) {
    $uid = UID;
    global $panelToken;
    $endpoint = "https://api.connectix.vip/v1/seller/clients/show?id=$cid";
    
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$panelToken}",
            "Accept: application/json",
            "Content-Type: application/json",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
        ],
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    if (!$data || !isset($data['client'])) {
        $message = "❌ اکانت یافت نشد یا خطا در ارتباط با سرور.";
    }

    $client = $data['client'];
    $plans  = $client['plans'] ?? [];
    $subscription_link = $client['subscription_link'] ?? null;

    // Find active and queued plans
    $activePlan = null;
    $queuedPlans = [];

    foreach ($plans as $plan) {
        if ($plan['is_in_queue']) {
            $queuedPlans[] = $plan;
        } elseif ($plan['is_active'] == 1) {
            $activePlan = $plan;
        }
    }

    // Create message
    $message = "📝 اطلاعات اکانت شما\n\n";

    $message .= "👤 نام: <b>{$client['name']}</b>\n";
    
    if (!empty($client['username'])) {
        $message .= "📧 یوزرنیم: <code>{$client['username']}</code>\n";
    }
    if (!empty($client['password'])) {
        $message .= "🔑 پسورد: <code>{$client['password']}</code>\n";
    }

    $message .= "📱 تعداد دستگاه مجاز: <b>{$client['count_of_devices']}</b>\n\n";

    if (!empty($subscription_link) && $subscription_link != null) {
        $message .= "🔗 لینک سابسکریشن: <code>{$subscription_link}</code>\n";
    }

    // Show active plan
    if ($activePlan) {
        $message .= "\n🎯 <b>اشتراک فعال فعلی</b>\n";
        $message .= "📦 پلن: {$activePlan['name']}\n";
        $message .= "⏳ انقضا: <b>{$activePlan['expire_date']}</b>\n";
        $message .= "📊 مصرف ترافیک: {$activePlan['total_used_traffic']}\n";
        $message .= "🗓 فعال شده در: {$activePlan['activated_at']}\n";
    } else {
        $message .= "\n⚠️ در حال حاضر هیچ اشتراک فعالی وجود ندارد.\n";
    }

    // Show queued plans
    if (!empty($queuedPlans)) {
        $message .= "\n⏳ <b>اشتراک‌های رزرو شده (در صف فعال‌سازی)</b>\n";
        foreach (array_reverse($queuedPlans) as $i => $plan) {
            $message .= "\n" . ($i + 1) . ". {$plan['name']}\n";
            $message .= "   انقضا: {$plan['expire_date']}\n";
            $message .= "   تاریخ خرید: {$plan['created_at']}\n";
            if ($plan['gift_days'] != 0) {
                $message .= "   +{$plan['gift_days']} روز هدیه\n";
            }
        }
    }
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🛒 | خرید اشتراک برای این اکانت', 'callback_data' => "updateClient_$cid"]
            ],
            [
                ['text' => '↪️ | بازگشت', 'callback_data' => 'my_accounts']
            ]
        ]
    ];

    $data = [
        'message' => $message,
        'keyboard' => $keyboard
    ];

    return $data;
}

function getSellerPlans() {
    global $panelToken;

    $endpoint = "https://api.connectix.vip/v1/seller/seller-plans";

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$panelToken}",
            "Accept: application/json",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
        ],
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (!$data || !isset($data['seller_plan_group'])) {
        return false;
    }

    // Get bot available plans
    $validPlans = [];
    foreach ($data['seller_plan_group'] as $group) {
        foreach ($group['seller_plans'] as $plan) {
            if ($plan['is_displayed_in_robot'] == true) {
                $validPlans[] = $plan;
            }
        }
    }

    return $validPlans;
}



function keyboard($keyboard) {
    try {
        $uid = UID;
        global $db_host, $db_user, $db_pass, $db_name;
        switch ($keyboard) {
            case "main_menu":
                //get bot data from bot_config.json
                $data = file_get_contents('setup/bot_config.json');
                $config = json_decode($data, true);
                $supportTelegram = $config['support_telegram'] ?? '';
                $channelTelegram = $config['channel_telegram'] ?? '';

                //check if user get test account
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
                        ['text' => '📦 | اکانت های من', 'callback_data' => 'my_accounts'],
                        ['text' => '🛍️ | خرید اکانت جدید', 'callback_data' => 'buy']
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

            case "my_accounts":
                $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
                $stmt = $conn->prepare("SELECT * FROM clients WHERE chat_id = ?");
                $stmt->bind_param("s", $uid);
                $stmt->execute();
                if ($conn->connect_error || $stmt->error) {
                    errorLog("Error in connecting to DB or preparing statement: " . ($conn->connect_error ?? $stmt->error));
                }
                $result = $stmt->get_result();
                $clients = $result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                $conn->close();
                $keyboard = [];
                foreach (array_reverse($clients) as $client) {
                    $keyboard[] = [
                        ['text' => 'مشاهده اکانت «' . $client['username'] . '»', 'callback_data' => 'getClient_' . $client['id']]
                    ];
                }
                $keyboard[] = [
                    ['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']
                ];
                break;
            case 'get_test':
                $keyboard = [
                    [
                        ['text' => '📱 | ویژه', 'callback_data' => 'getTest_normal'],
                        ['text' => '🔗 | سابسکریبشن', 'callback_data' => 'getTest_sublink']

                    ],
                    [
                        ['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']
                    ]
                ];
                break;
            default:
                return json_encode(['ok' => true]);
        }
        return json_encode(['inline_keyboard' => $keyboard]);
    } catch (Exception $e) {
        errorLog("Error in keyboard function: " . $e->getMessage());
    }
}

function message($message) {

    switch ($message) {
        case "welcome_message":
            //get name ffrom bot_config.json
            $data = file_get_contents('setup/bot_config.json');
            $config = json_decode($data, true);
            $welcomeMessage = $config['messages']['welcome_text'] ?? '';
            
            return $welcomeMessage;

        case "my_accounts":
            $msg = "📦 اکانت های متصل یه حساب تلگرام شما:\n\n* در صورت عدم مشاهده اکانت خود، آن را اضافه کنید.";
            return $msg;

        case "get_test":
            $msg = "لطفا نوع اکانت را انتخاب کنید:";
            return $msg;
        default:
            return "پیام پیشفرض";
    }
}