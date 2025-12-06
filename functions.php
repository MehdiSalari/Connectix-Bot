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
    // Delete Redis key if exists
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);
    $redis->del("user:steps:$chat_id");
    $redis->close();

    // Update user info in the database
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
        $tgResponse = tg('sendMessage',[
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
        case "showClient":
            $result = showClient($query);
            return $result;
        case "getTest":
            $result = getTest($query);
            return $result;
        case "buy":
            $result = buy($query);
            return $result;
        default:
            return false;
    }
}

function buy($info) {
    $infoParts = explode(':', $info);
    $step = $infoParts[0];
    $data = $infoParts[1];
    $uid = UID;

    switch ($step) {
        case 'group':
            // Initialize Redis if not initialized
            $redis = new Redis();
            $redis->connect('127.0.0.1', 6379);

            // Set Redis key with user id
            $key = "user:steps:$uid";

            //Set Redis data
            $planData = [
                'acc'   => 'new',
                'group' => $data,
                'plan'  => null
            ];
            // Save in Hash foramt
            $redis->hmset($key, $planData);
            
            // Set expire time for 30mins 
            $redis->expire($key, 1800);

            // Close Redis connection
            $redis->close();

            return [
                "message" => message('count'),
                "keyboard" => keyboard('count')
            ];
        
        case 'count':
            // Initialize Redis if not initialized
            $redis = new Redis();
            $redis->connect('127.0.0.1', 6379);

            // Set Redis key with user id
            $key = "user:steps:$uid";

            // Get Data from redis
            $planData = $redis->hgetall($key);

            // Close Redis connection
            $redis->close();


            $planGroup = $planData['group'];

            $plans = getSellerPlans($planGroup);

            $keyboard = [];
            foreach ($plans as $plan) {
                if ($plan['count_of_devices'] == $data) {
                    $traffic = parsePlanTitle($plan['title'])['traffic_gb'];
                    $traffic = ($traffic == '∞') ? 'نامحدود' : "$traffic گیگ";
                    $period_text = parsePlanTitle($plan['title'])['period_text'];
                    $planText = "$traffic • $period_text";
                    $keyboard[] = [
                        ['text' => $planText . ' | ' . $plan['sell_price'] . ' تومان', 'callback_data' => 'buy_plan:' . $plan['id']]
                    ];
                }
            }

            $keyboard[] = [
                ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
                ['text' => '↪️ | بازگشت', 'callback_data' => 'buy_group:' . $planGroup]
            ];

            $keyboard = json_encode(['inline_keyboard' => $keyboard]);

            $planGroupName = parseType($planGroup);
            // $message = "لطفا از لیست زیر پلن $data کاربر مد نظر خود را انتخاب کنید:";
            $message = "فهرست و قیمت سرویس‌های $data کاربره $planGroupName به شرح لیست زیر است.\n\nلطفاً سرویس مدنظر خود را انتخاب کنید: 👇";
            return [
                "message" => $message,
                "keyboard" => $keyboard
            ];
        case 'plan':
            $planId = $data;
            $redis = new Redis();
            $redis->connect('127.0.0.1', 6379);

            // Set Redis key with user id
            $key = "user:steps:$uid";

            // Get Data from redis
            $planData = $redis->hgetall($key);

            $planGroup = $planData['group'];

            $planData = [
                'acc'   => 'new',
                'group' => $planGroup,
                'plan'  => $planId
            ];
            // Save in Hash foramt
            $redis->hmset($key, $planData);
            
            // Set expire time for 30mins 
            $redis->expire($key, 1800);

            // Close Redis connection
            $redis->close();

            $plans = getSellerPlans($planGroup);
            foreach ($plans as $plan) {
                if ($plan['id'] == $planId) {
                    $selectedPlan = $plan;
                }
            }

            $planDetails = parsePlanTitle($selectedPlan['title']);
            $planTitle = $planDetails['text'];
            $planPrice = $selectedPlan['sell_price'];
            $acc = ($planData['acc'] == 'new') ? "خرید اکانت جدید" : "تمدید اکانت " . $planData['acc'];

            $message = "📝 اطلاعات اکانت شما\n\n📧 $acc\n📦 پلن: $planTitle\n💰 مبلغ: $planPrice تومان\n\nلطفا روش پرداخت را انتخاب کنید 👇🏻";
            $keyboard = json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => '💳 | کارت به کارت', 'callback_data' => 'buy_pay:card']
                    ],
                    [
                        // ['text' => '👝 | کسر از کیف پول', 'callback_data' => 'buy_pay:wallet'],
                        ['text' => '🔜 | روش های دیگر به زودی...', 'callback_data' => 'not'],
                    ],
                    [
                        ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
                        ['text' => '↪️ | بازگشت', 'callback_data' => 'buy_count:' . $selectedPlan['count_of_devices']]
                    ]
                ]
            ]);

            return [
                "message" => $message,
                "keyboard" => $keyboard
            ];
    }
}

function getClientData($cid) {
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
        errorLog("❌ اکانت یافت نشد یا خطا در ارتباط با سرور.");
    }

    $client = $data['client'];

    return $client;
    
}

function showClient($cid) {
    $client = getClientData($cid);
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
        $planName = parsePlanTitle($activePlan['name'])['text'];
        $message .= "\n🎯 <b>اشتراک فعال فعلی</b>\n";
        $message .= "📦 پلن: $planName\n";
        $message .= "⏳ انقضا: <b>{$activePlan['expire_date']}</b>\n";
        $message .= "📊 مصرف ترافیک: {$activePlan['total_used_traffic']}\n";
        $message .= "🗓 فعال شده در: {$activePlan['activated_at']}\n";
    } else {
        $message .= "\n⚠️ در حال حاضر هیچ اشتراک فعالی وجود ندارد.\n";
    }

    // Show queued plans
    if (!empty($queuedPlans)) {
        $message .= "\n\n⏳ <b>اشتراک‌های رزرو شده (در صف فعال‌سازی)</b>\n";
        foreach (array_reverse($queuedPlans) as $i => $plan) {
            $planName = parsePlanTitle($plan['name'])['text'];
            $message .= "\n" . ($i + 1) . ". پلن: $planName\n";
            $message .= "   انقضا: {$plan['expire_date']}\n";
            $message .= "   تاریخ خرید: {$plan['created_at']}\n";
            if ($plan['gift_days'] != 0) {
                $message .= "   +{$plan['gift_days']} روز هدیه\n";
            }
        }
    }
    
    
    // choose action label depending on whether client has an active plan
    $actionButton = $activePlan
        ? ['text' => '📆 | رزرو اشتراک جدید برای این اکانت', 'callback_data' => "updateClient_$cid"]
        : ['text' => '🛒 | خرید اشتراک برای این اکانت', 'callback_data' => "updateClient_$cid"];

    $keyboard = [
        'inline_keyboard' => [
            [ $actionButton ],
            [
                ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
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

function getSellerPlans($type) {
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

    switch ($type) {
        case "default":
            $validPlans = [];
            foreach ($data['seller_plan_group'] as $group) {
                foreach ($group['seller_plans'] as $plan) {
                    if ($plan['is_displayed_in_robot'] == true && $plan['type'] == "Premium" && stripos($plan['title'], 'Sublink') === false && stripos($plan['title'], 'Static IP') === false) {
                        $validPlans[] = $plan;
                    }
                }
            }
            return $validPlans;
        case "Sublink":
            $validPlans = [];
            foreach ($data['seller_plan_group'] as $group) {
                foreach ($group['seller_plans'] as $plan) {
                    if ($plan['is_displayed_in_robot'] == true && $plan['type'] == "Premium" && (stripos($plan['title'], '+ Sublink') !== false || stripos($plan['title'], '+Sublink') !== false)) {
                        $validPlans[] = $plan;
                    }
                }
            }
            return $validPlans;
        case "Static IP":
            $validPlans = [];
            foreach ($data['seller_plan_group'] as $group) {
                foreach ($group['seller_plans'] as $plan) {
                    if ($plan['is_displayed_in_robot'] == true && $plan['type'] == "Premium" && stripos($plan['title'], 'Static IP') !== false) {
                        $validPlans[] = $plan;
                    }
                }
            }
            return $validPlans;
        case "free":
            $validPlans = [];
            foreach ($data['seller_plan_group'] as $group) {
                foreach ($group['seller_plans'] as $plan) {
                    if ($plan['is_displayed_in_robot'] == true && $plan['type'] == "Free") {
                        $validPlans[] = $plan;
                    }
                }
            }
            return $validPlans;
        case "all-bot":
            foreach ($data['seller_plan_group'] as $group) {
                foreach ($group['seller_plans'] as $plan) {
                    if ($plan['is_displayed_in_robot'] == true && $plan['type'] == "Premium") {
                        $validPlans[] = $plan;
                    }
                }
            }
            return $validPlans;
        case "all":
            $validPlans = [];
            foreach ($data['seller_plan_group'] as $group) {
                foreach ($group['seller_plans'] as $plan) {
                    $validPlans[] = $plan;
                }
            }
            return $validPlans;
        case "group":
            $groups = $data['groups'] ?? null;
            if ($groups === null) {
                return false;
            }
            return $groups;
        case "periods":
            $periods = $data['periods'] ?? null;
            if ($periods === null) {
                return false;
            }
            return $periods;
        default:
            return false;
    }

    // Get bot available plans
    // $validPlans = [];
    // foreach ($data['seller_plan_group'] as $group) {
    //     foreach ($group['seller_plans'] as $plan) {
    //         if ($plan['is_displayed_in_robot'] == true) {
    //             $validPlans[] = $plan;
    //         }
    //     }
    // }

    // return $validPlans;
}

function getTest($type) {
    try {
        static $plans = null;
    
        if ($plans === null) {
            $plans = getSellerPlans("free");
            if ($plans === false) {
                errorLog("Error: Failed to retrieve seller plans");
                $message = "خطا در دریافت لیست پلن‌ها از سرور";
                return ['message' => $message, 'keyboard' => []];
            }
        }
    
        $selectedPlan = null;
    
        if ($type === "sublink") {
            foreach ($plans as $plan) {
                if (stripos($plan['title'], '+ Sublink') !== false || stripos($plan['title'], '+Sublink') !== false) {
                    if ($plan['type'] !== null && $plan['type'] == "Free") {
                        $selectedPlan = $plan;
                        break;
                    }
                }
            }
        } elseif ($type === "normal") {
            foreach ($plans as $plan) {
                if (stripos($plan['title'], 'Sublink') === false) {
                    if ($plan['type'] !== null && $plan['type'] == "Free") {
                        $selectedPlan = $plan;
                        break;
                    }
                }
            }
        }
    
        if (!$selectedPlan) {
            errorLog("Error: No suitable plan found for type: $type");
            $message = "پلن مناسب برای نوع درخواستی ($type) یافت نشد.";
            return ['message' => $message, 'keyboard' => []];
        }

        // Get user data
        global $db_host, $db_user, $db_pass, $db_name, $panelToken;
        $uid = UID;
        $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
        if ($conn->connect_error) {
            errorLog("Error: Database connection failed: " . $conn->connect_error);
            return ['message' => 'خطا در اتصال به دیتابیس', 'keyboard' => []];
        }
        
        $stmt = $conn->prepare("SELECT * FROM users WHERE chat_id = ?");
        if (!$stmt) {
            errorLog("Error: Prepare failed: " . $conn->error);
            $conn->close();
            return ['message' => 'خطا در دریافت اطلاعات کاربر', 'keyboard' => []];
        }
        
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $userResult = $stmt->get_result();
        $user = $userResult->fetch_assoc();
        $stmt->close();
        
        if (!$user) {
            errorLog("Error: User not found for chat_id: $uid");
            $conn->close();
            return ['message' => 'کاربر یافت نشد', 'keyboard' => []];
        }

        $userTest = $user['test'] ?? null;
        if ($userTest == 1) {
            $conn->close();
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']
                    ]
                ]
            ];
            return ['message' => '⚠️ شما قبلا درخواست تست داده اید!', 'keyboard' => $keyboard];
        }

        $name = $user['name'] ?? null;
        $telegram_id = $user['telegram_id'] ?? null;
        $user_id = $user['id'] ?? null;
        $planId = $selectedPlan['id'];

        // Generate password
        $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
        $password = '';
        for ($i = 0; $i < 5; $i++) {
            $password .= $characters[rand(0, strlen($characters) - 1)];
        }
    
        // Prepare data for API request
        $data = json_encode([
            "id" => null,
            "name" => $name,
            "email" => null,
            "created_at" => null,
            "remains_days" => null,
            "expire_date" => null,
            "count_of_plans" => null,
            "plans" => [],
            "count_of_devices" => 0,
            "added_by" => null,
            "password" => $password,
            "phone" => null,
            "chat_id" => $uid,
            "telegram_id" => $telegram_id,
            "group_id" => null,
            "plan_id" => $planId,
            "enable_plan_after_first_login" => true,
            "username" => "",
            "group_name" => "",
            "plan_name" => "",
            "used_traffic" => "",
            "is_active" => false,
            "is_expired" => false,
            "connection_status" => "",
            "last_active_date" => "",
            "subscription_link" => "",
            "used_devices" => [
                "os" => "",
                "model" => ""
            ],
            "outline_link" => "",
            "is_child_protection_enabled" => false,
            "notes" => ""
        ]);
        
        // Store client on panel
        $endpoint = 'https://api.connectix.vip/v1/seller/clients/store';
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $panelToken,
                'Accept: application/json',
                'Content-Type: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ],
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            errorLog("Error: cURL failed to create client: " . curl_error($ch));
            curl_close($ch);
            $conn->close();
            return ['message' => 'خطا در ایجاد اکانت روی سرور', 'keyboard' => []];
        }
        curl_close($ch);

        $result = json_decode($response, true);
        if (!isset($result['client_id'])) {
            errorLog("Error: Failed to create client on panel. Response: " . print_r($result, true));
            $conn->close();
            return ['message' => 'خطا در ایجاد اکانت', 'keyboard' => []];
        }
        
        $client_id = $result['client_id'];

        $client = getClientData($client_id);

        $clientUsername = $client['username'] ?? '';
        $clientPassword = $client['password'] ?? '';
        $clientSublink = $client['subscription_link'] ?? null;
        $clientCOD = $client['count_of_devices'] ?? 0;
        $clientPlan = $client['plans'][0] ?? null;

        // Get message from bot_config.json
        $configData = file_get_contents('setup/bot_config.json');
        $config = json_decode($configData, true);
        $messages = $config['messages'] ?? [];

        // Update database
        try {
            // Update users - mark test = 1
            $stmt = $conn->prepare("UPDATE users SET test = ? WHERE chat_id = ?");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $testValue = 1;
            $stmt->bind_param("ii", $testValue, $uid);
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            $stmt->close();
            // errorLog("Success: Updated user test status for chat_id: $uid");
            
            // Insert client
            $stmt = $conn->prepare("INSERT INTO clients (id, count_of_devices, username, password, chat_id, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            // types: id (s), count_of_devices (i), username (s), password (s), chat_id (i), user_id (i)
            $stmt->bind_param("sissii", $client_id, $clientCOD, $clientUsername, $clientPassword, $uid, $user_id);
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            $stmt->close();
    
            
            $conn->close();
        } catch (Exception $e) {
            errorLog("Error: Database operation failed: " . $e->getMessage());
            $conn->close();
            return ['message' => 'خطا در ذخیره اطلاعات اکانت', 'keyboard' => []];
        }

        // Send message to user
        $msg = "\n\n👤 نام کاربری: <code>$clientUsername</code>\n🔑 رمز عبور: <code>$clientPassword</code>\n";
        if ($clientSublink) {
            $msg .= "\n🔗 لینک سابسکریبشن: <code>$clientSublink</code>";
        }

        // Uncomment the following line if you want to send the message to the user separately
        // tg('sendMessage', [
        //     'chat_id' => $uid,
        //     'text' => $msg,
        //     'parse_mode' => 'html'
        // ]);

        // Create final message
        $message = $messages['free_test_account_created'] ?? 'اکانت تست شما با موفقیت ایجاد شد.';
        $message .= $msg;
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📦 | اکانت های من', 'callback_data' => 'my_accounts']
                ],
                [
                    ['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']
                ]
            ]
        ];
        
        // errorLog("Success: Test account created successfully for chat_id: $uid, client_id: $client_id");
        return ['message' => $message, 'keyboard' => $keyboard];
            
    } catch (Exception $e) {
        errorLog("Error: Create test account exception: " . $e->getMessage());
        return ['message' => 'خطا: ' . $e->getMessage(), 'keyboard' => []];
    }
}

function parsePlanTitle($title, $short = false) {
    $title = trim($title);

    // پترن دقیق برای تمام پلن‌های Connectix
    preg_match('/^\((\d+)x\)\s*(Free-)?(?:([\d.]+)GB-)?(?:Unlimited-)?(\d+)([WMYD])?(?:\s*\+\s*(\d+)D)?\s*(.*)$/', $title, $matches);

    if (!$matches) {
        return [
            'raw'   => $title,
            'text'  => "پلن نامشخص",
            'is_free' => false,
            'devices' => 1,
            'traffic_gb' => null,
            'period_text' => null,
            'extras' => []
        ];
    }

    $devices     = (int)$matches[1];
    $isFree      = !empty($matches[2]);
    $traffic     = $matches[3] ?? null;
    $isUnlimited = str_contains($title, 'Unlimited');
    $periodNum   = $matches[4];
    $periodUnit  = $matches[5] ?? 'M';
    $giftDays    = $matches[6] ?? null; // مثلاً + 3D
    $extraText   = trim($matches[7] ?? '');

    // تبدیل زمان
    $periodText = match($periodUnit) {
        'D' => "$periodNum روز",
        'W' => "$periodNum هفته",
        'M' => "$periodNum ماه",
        'Y' => "$periodNum سال",
        default => "$periodNum ماه"
    };

    // تشخیص اکسترا
    $extras = [];
    if ($giftDays) $extras[] = "+$giftDays روز هدیه";
    if (str_contains($extraText, 'Sublink')) $extras[] = 'ساب‌لینک';
    if (str_contains($extraText, 'Static IP')) $extras[] = 'آی‌پی ثابت';

    // حالت کوتاه (فقط دستگاه + مدت اصلی + نوع — بدون هدیه و حجم)
    if ($short) {
        if ($isFree) {
            $text = "تست رایگان • $periodText";
        } elseif ($isUnlimited) {
            $text = "$devices دستگاه • نامحدود • $periodText";
        } else {
            $text = "$devices دستگاه • $periodText";

            if (in_array('ساب‌لینک', $extras)) {
                $text .= " • ساب‌لینک";
            } elseif (in_array('آی‌پی ثابت', $extras)) {
                $text .= " • آی‌پی ثابت";
            } elseif (empty($extras) || count($extras) === 1 && $extras[0] === "+$giftDays روز هدیه") {
                $text .= " • ویژه";
            }
            // اگر فقط هدیه روز داره → هیچ نوع خاصی نشون نده (مثل قبل)
        }

        return [
            'raw'          => $title,
            'text'         => $text,
            'is_free'      => $isFree,
            'devices'      => $devices,
            'is_unlimited' => $isUnlimited,
            'period_text'  => $periodText,
            'short'        => true
        ];
    }

    // حالت کامل (پیش‌فرض)
    $finalText = $isFree ? "تست رایگان" : "$devices دستگاه";

    if ($isUnlimited) {
        $finalText .= " • نامحدود";
    } elseif ($traffic) {
        $finalText .= " • {$traffic} گیگ";
    }

    $finalText .= " • $periodText";

    if (!empty($extras)) {
        $finalText .= " • " . implode(" • ", $extras);
    }

    // اگر هیچ اکسترایی نبود → ویژه
    if (empty($extras) && !$isFree && !$isUnlimited) {
        $finalText .= " • ویژه";
    }

    return [
        'raw'           => $title,
        'text'          => $finalText,
        'is_free'       => $isFree,
        'devices'       => $devices,
        'traffic_gb'    => $isUnlimited ? '∞' : ($traffic ? (float)$traffic : null),
        'period_text'   => $periodText,
        'period_days'   => approximateDays($periodNum, $periodUnit),
        'gift_days'     => $giftDays ? (int)$giftDays : 0,
        'extras'        => $extras,
        'has_sublink'   => in_array('ساب‌لینک', $extras),
        'has_static_ip' => in_array('آی‌پی ثابت', $extras),
        'is_unlimited'  => $isUnlimited,
        'short'         => false
    ];
}

function approximateDays($num, $unit) {
    return match($unit) {
        'D' => $num,
        'W' => $num * 7,
        'M' => $num * 30,
        'Y' => $num * 365,
        default => 30
    };
}

function parseType($type) {
    $name = match($type) {
        "default" => "ویژه",
        "Sublink" => "ساب‌لینک",
        "Static IP" => "آی‌پی ثابت",
        default => $type
    };
    return $name;
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
                        ['text' => '🛍️ | خرید / تمدید اکانت ', 'callback_data' => 'action:buy_or_renew_service']
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

                if (empty($clients)) {
                    $keyboard[] = [['text' => '🤷🏻 | اکانتی به تلگرام شما متصل نیست', 'callback_data' => 'not']];
                } else {
                    foreach (array_reverse($clients) as $client) {
                        $clientData = getClientData($client['id']);
                        $plans = $clientData['plans'] ?? [];

                        // پیدا کردن پلن فعال یا در صف
                        $activePlan = null;
                        $queuedPlan = null;

                        foreach ($plans as $plan) {
                            if ($plan['is_active'] == 1) {
                                $activePlan = $plan;
                                break; // اولین فعال رو پیدا کرد → تموم
                            }
                            if ($plan['is_in_queue'] && !$queuedPlan) {
                                $queuedPlan = $plan; // اولین در صف
                            }
                        }

                        // اگر فعال نبود، از در صف استفاده کن
                        $currentPlan = $activePlan ?? $queuedPlan;

                        if (!$currentPlan) {
                            $status = "🔴 غیرفعال";
                            $name = "بدون اشتراک";
                        } else {
                            $isActive = $currentPlan['is_active'] == 1;
                            $status = $isActive ? "🟢 فعال" : "در صف";

                            // تبدیل اسم پلن به متن خوانا و کوتاه
                            $parsed = parsePlanTitle($currentPlan['name'], true);
                            $name = $parsed['text'];
                        }

                        $keyboard[] = [
                            ['text' => $name, 'callback_data' => 'showClient_' . $client['id']],
                            ['text' => $status . ' | ' . $client['username'], 'callback_data' => 'showClient_' . $client['id']]
                        ];
                    }
                }
                $keyboard[] = [
                    ['text' => '➕ | اضافه کردن اکانت', 'callback_data' => 'add_account'],
                    ['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']
                ];
                break;

            case "count":
                $keyboard = [
                    [
                        ['text'=> '2️⃣ | 2 کاربر', 'callback_data' => 'buy_count:2'],
                        ['text'=> '1️⃣ | 1 کاربر', 'callback_data' => 'buy_count:1']
                    ],
                    [
                        ['text'=> '4️⃣ | 4 کاربر', 'callback_data' => 'buy_count:4'],
                        ['text'=> '3️⃣ | 3 کاربر', 'callback_data' => 'buy_count:3']
                    ],
                    [
                        ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
                        ['text' => '↪️ | بازگشت', 'callback_data' => 'group']
                    ]
                ];
                break;

            case "get_test":
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

            case "buy":
                $keyboard = [
                    [
                        ['text' => '🔄️ | تمدید اکانت فعلی', 'callback_data' => 'renew']
                    ],
                    [
                        ['text' => '➕ | خرید اکانت جدبد', 'callback_data' => 'group']
                    ],
                    [
                        ['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']
                    ]
                ];
                break;

            case "group":
                $groups = getSellerPlans("group");
                $keyboard = [];
                foreach ($groups as $group) {
                    $name = parseType($group['name']);
                    $name =  match($group['name']) {
                        "default" => "📱 | $name (پیشنهاد میشود)",
                        "Sublink" => "🔗 | $name",
                        "Static IP" => "📍 | $name",
                        default => $group['name']
                    };
                    $keyboard[] = [
                        ['text' => $name, 'callback_data' => 'buy_group:' . $group['name']]
                    ];
                }
                $keyboard[] = [
                    ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
                    ['text' => '↪️ | بازگشت', 'callback_data' => 'buy']
                ];
                break;

            case "renew":
                $keyboard = [
                    [
                        ['text' => '↪️ | بازگشت', 'callback_data' => 'buy']
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
    //get name ffrom bot_config.json
    $data = file_get_contents('setup/bot_config.json');
    $config = json_decode($data, true);
    $appName = $config['app_name'] ?? '';
    $welcomeMessage = $config['messages']['welcome_text'] ?? '';

    switch ($message) {
        case "welcome_message":
            
            return $welcomeMessage;

        case "my_accounts":
            $msg = "📦 اکانت های متصل یه حساب تلگرام شما:\n\n* در صورت عدم مشاهده اکانت خود، آن را اضافه کنید.";
            return $msg;

        case "get_test":
            $msg = "🎁 لطفا نوع اکانت تست را انتخاب کنید:\n\n<b>📱 ویژه(پیشنهاد میشود):</b>\nدریافت نام کاربری و رمز عبور جهت ورود به نرم افزار Connectix و استفاده از 4 پروتکل و بیش از 10 کشور برای اتصال.\n\n<b>🔗 سابسکریبشن:</b>\nدریافت لینک سابسکریپشن جهت استفاده در نرم افزار هایی که از سرویس V2Ray پشتیبانی میکنند (مثل V2RayNG و V2Box)";
            return $msg;

        case "count":
            $msg = "🔢 این اکانت را برای چند کاربر (دستگاه) قابل استفاده باشد؟";
            return $msg;

        case "buy":
            $msg = "با تشکر از اعتماد و حسن انتخاب شما در خرید سرویس فیلترشکن {$appName} .\nلطفا نوع خرید خود را انتخاب کنید:\n\n<b>🔄️ تمدید اکانت فعلی:</b>\nاین دکمه برای خرید اشتراک برای اکانت قبلی استفاده میشود.\n\n<b>➕ خرید اکانت جدید:</b>\nاین دکمه برای خرید اکانت جدید استفاده میشود.";
            return $msg;

        case "group":
            $msg = "لطفاً ابتدا نوع سرویس مدنظر را انتخاب کنید: 👇\n\n<b>📱 ویژه (پیشنهاد میشود):</b>\nدریافت نام کاربری و رمز عبور جهت ورود به نرم افزار Connectix و استفاده از 4 پروتکل و بیش از 10 کشور برای اتصال.\n\n<b>🔗 سابسکریبشن:</b>\nدریافت لینک سابسکریپشن جهت استفاده در نرم افزار هایی که از سرویس V2Ray پشتیبانی میکنند (مثل V2RayNG و V2Box)\n\n<b>📍 آی‌پی ثابت:</b>\nدریافت نام کاربری و رمز عبور جهت ورود به نرم افزار Connectix و استفاده از آیپی ثابت.";
            return $msg;
            
        case "renew":
            $msg = "";
            return $msg;

        default:
            return "پیام پیشفرض";
    }
}