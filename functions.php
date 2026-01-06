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

function getUser($chat_id) {
    global $db_host, $db_user, $db_pass, $db_name;
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        errorLog("Connection failed: " . $conn->connect_error, "functions.php", 59);
    }
    $stmt = $conn->prepare("SELECT * FROM users WHERE chat_id = ?");
    $stmt->bind_param("i", $chat_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $conn->close();
    return $user;
}

function userInfo($chat_id, $user_id, $user_name) {
    try {
        // Delete Redis key if exists
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379);
        $redis->del("user:steps:$chat_id");
        $redis->close();

        $avatar = null;
        //get user avatar
        if ($user_id) {
            $html = file_get_contents("https://t.me/$user_id");

            libxml_use_internal_errors(true);
            
            $dom = new DOMDocument();
            $dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            $imgTags = $xpath->query('//img[contains(@class, "tgme_page_photo_image")]');
            foreach ($imgTags as $imgTag) {
                $avatar = $imgTag->getAttribute('src');
                if ($avatar) break;
            }
        }

        // Update user info in the database
        global $db_host, $db_user, $db_pass, $db_name;
        $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
        if ($conn->connect_error) {
            errorLog("Connection failed: " . $conn->connect_error, "functions.php", 81);
        }
        $stmt = $conn->prepare("SELECT * FROM users WHERE chat_id = ?");
        $stmt->bind_param("i", $chat_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        if ($user) {
            $stmt = $conn->prepare("UPDATE users SET telegram_id = ?, name = ?, avatar = ? WHERE chat_id = ?");
            $stmt->bind_param("sssi", $user_id, $user_name, $avatar, $chat_id);
            $result = $stmt->execute();
        } else {
            $stmt = $conn->prepare("INSERT INTO users (chat_id, telegram_id, name, avatar, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->bind_param("isss", $chat_id, $user_id, $user_name, $avatar);
            $result = $stmt->execute();
        }
        $stmt->close();
        $conn->close();

        //if user not have a record in wallets table, add it
        $walletData = wallet('get', $chat_id);
        if (!$walletData) {
            $isWalletCreated = wallet('create', $chat_id, '0');
            if (!$isWalletCreated) {
                errorLog("Error creating wallet for user: $chat_id", "functions.php", 106);
            }
        }
        
    } catch (Exception $e) {
        errorLog("Exception: " . $e->getMessage(), "functions.php", 110);
    }
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

function errorLog($message, $file, $line) {
    // Add timestamp to the log entry
    file_put_contents( __DIR__ .'/debug/error_log.log', date('Y-m-d H:i:s') . " - " . $message . " | in file: " . $file . " | at line: " . $line . "\n", FILE_APPEND);

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
        tg('sendMessage',[
            'chat_id' => $chat_id,
            'text' => $message . " in file: " . $file . " at line: " . $line
        ]);
    }
    $stmt->close();
    $conn->close();
}

function getDownloadLinks($platform = null) {
    $html = file_get_contents('https://connectix.space/#download');

    libxml_use_internal_errors(true);

    $dom = new DOMDocument();
    $dom->loadHTML($html);

    $xpath = new DOMXPath($dom);


    $cards = $xpath->query('//section[@id="download"]//div[contains(@class,"service-box")]');

    $data = [];

    foreach ($cards as $card) {
        $title = trim($xpath->query('.//h5', $card)[0]->textContent);
        $links = $xpath->query('.//a[@href]', $card);

        foreach ($links as $a) {
            $data[] = [
                'platform' => preg_replace('/\s+.*/', '', $title),
                'label'    => trim($a->textContent),
                'url'      => $a->getAttribute('href')
            ];
        }
    }

    $response = match ($platform) {
        "android" => json_encode(array_filter($data, fn($item) => $item['platform'] === 'Android')),
        "ios" => json_encode(array_filter($data, fn($item) => $item['platform'] === 'iOS')),
        "windows" => json_encode(array_filter($data, fn($item) => $item['platform'] === 'Windows')),
        "mac" => json_encode(array_filter($data, fn($item) => $item['platform'] === 'Mac')),
        "linux" => json_encode(array_filter($data, fn($item) => $item['platform'] === 'Linux')),
        default => json_encode($data),
    };

    return $response;
}

function walletReqs($query) {
    global $db_host, $db_user, $db_pass, $db_name;
    $parts = explode(':', $query);
    $action = $parts[0];
    $txID = $parts[1];
    $uid = UID;
    switch ($action) {
        case 'increase':
            $redis = new Redis();
            $redis->connect('127.0.0.1', 6379);
            $key = "user:steps:" . $uid;
            $redis->hmset($key, ['action' => 'wallet_increase', 'step' => 'get_amount', 'amount' => null]);
            $redis->expire($key, 1800);
            $redis->close();
            
            $tgResult = tg('editMessageText',[
                'chat_id' => $uid,
                'message_id' => CBMID,
                'text' => message('wallet_increase'),
                'parse_mode' => 'html',
                'reply_markup' => keyboard('wallet_increase')
            ]);
            break;
        case 'cancel':
            $txID = createWalletTransaction($txID, 'CANCLED_BY_USER');
            $redis = new Redis();
            $redis->connect('127.0.0.1', 6379);
            $redis->del("user:steps:" . $uid);
            $redis->close();
            $tgResult = tg('editMessageText',[
                'chat_id' => $uid,
                'message_id' => CBMID,
                'text' => message('wallet'),
                'parse_mode' => 'html',
                'reply_markup' => keyboard('wallet')
            ]);
            break;
        case 'accept':
            $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
            $stmt = $conn->prepare("SELECT * FROM wallet_transactions WHERE id = ?");
            $stmt->bind_param("i", $txID);
            $stmt->execute();
            $result = $stmt->get_result();
            $tx = $result->fetch_assoc();
            $stmt->close();
            $conn->close();

            if ($tx['status'] == 'PENDING') {

                $txID = createWalletTransaction($txID, 'SUCCESS');
                
                $txUser = $tx['chat_id'];
                $txAmount = $tx['amount'];

                $textAmount = number_format($txAmount);
                $walletID = wallet('INCREASE', $txUser, $txAmount);
                
                $redis = new Redis();
                $redis->connect('127.0.0.1', 6379);
                $redis->del("user:steps:" . $txUser);
                $redis->close();

                $walletBalance = wallet('get', $txUser)['balance'];

                //to user
                $message = "✅ تراکنش شما جهت افزایش موجودی کیف پول تایید شد.\n\n";
                $message .= "💵 مبلغ تراکنش: $textAmount\n";
                $message .= "💰 موجودی کیف پول: $walletBalance";

                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '👝 |  کیف پول', 'callback_data' => 'wallet']
                        ],
                        [
                            ['text' => '🏡 | خانه', 'callback_data' => 'main_menu']
                        ]
                    ]
                ];

                $tgResult = tg('sendMessage',[
                    'chat_id' => $txUser,
                    'text' => $message,
                    'reply_markup' => $keyboard
                ]);

                // to admin
                $userName = getUser($txUser)['telegram_id'] ?? null; 
                if ($userName) {
                    $userName = "@$userName";
                } else {
                    $userName = "نامشخص";
                }

                $message = "✅ شماره تراکنش $txID با موفقیت تایید شد.\n\n";
                $message .= "👝 شماره کیف پول: $walletID\n";
                $message .= "🔢 آیدی: <code>$txUser</code>\n";
                $message .= "👤 نام کاربری: $userName\n";
                $message .= "💵 مبلغ: $textAmount";
                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '✅ | تایید شده', 'callback_data' => 'not']
                        ]
                    ]
                ];

                return ['caption' => $message, 'reply_markup' => $keyboard];
            } else {
                $statusText = match ($tx['status']) {
                    "SUCCESS" => "تایید شده",
                    "PENDING" => "در انتظار",
                    "CANCLED_BY_USER" => "لغو شده",
                    "REJECTED_BY_ADMIN" => "رد شده",
                    default => "نامشخص",
                };
                $statusIcon = match ($tx['status']) {
                    "SUCCESS" => "✅",
                    "PENDING" => "⏳",
                    "CANCLED_BY_USER" => "🚫",
                    "REJECTED_BY_ADMIN" => "❌",
                    default => "⚠️",
                };
                $message = "⚠️ تراکنش شماره $txID در وضعیت $statusText است.";
                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => "$statusIcon | $statusText", 'callback_data' => 'not']
                        ]
                    ]
                ];
                return ['caption' => $message, 'reply_markup' => $keyboard];
            }

        case 'reject':
            $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
            $stmt = $conn->prepare("SELECT * FROM wallet_transactions WHERE id = ?");
            $stmt->bind_param("i", $txID);
            $stmt->execute();
            $result = $stmt->get_result();
            $tx = $result->fetch_assoc();
            $stmt->close();
            $conn->close();
            
            if ($tx['status'] == 'PENDING') {

                createWalletTransaction($txID, 'REJECTED_BY_ADMIN');
                
                $txUser = $tx['chat_id'];
                $txAmount = $tx['amount'];

                $textAmount = number_format($txAmount);

                $redis = new Redis();
                $redis->connect('127.0.0.1', 6379);
                $redis->del("user:steps:" . $txUser);
                $redis->close();
                
                // to user
                $message = "❌ تراکنش شما جهت افزایش موجودی کیف پول رد شد.\n\n";
                $message .= "💵 مبلغ تراکنش: $textAmount"
                ;

                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '👝 |  کیف پول', 'callback_data' => 'wallet']
                        ],
                        [
                            ['text' => '🏡 | خانه', 'callback_data' => 'main_menu']
                        ]
                    ]
                ];

                $tgResult = tg('sendMessage',[
                    'chat_id' => $txUser,
                    'text' => $message,
                    'reply_markup' => $keyboard
                ]);

                // to admin
                $userName = getUser($txUser)['telegram_id'] ?? null; 
                if ($userName) {
                    $userName = "@$userName";
                } else {
                    $userName = "نامشخص";
                }

                $walletID = wallet('get', $txUser)['id'];

                $message = "❌ شماره تراکنش $txID  رد شد.\n\n";
                $message .= "👝 شماره کیف پول: $walletID\n";
                $message .= "🔢 آیدی: <code>$txUser</code>\n";
                $message .= "👤 نام کاربری: $userName\n";
                $message .= "💵 مبلغ: $textAmount";
                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '❌ | رد شده', 'callback_data' => 'not']
                        ]
                    ]
                ];

                return ['caption' => $message, 'reply_markup' => $keyboard];
            } else {
                $statusText = match ($tx['status']) {
                    "SUCCESS" => "تایید شده",
                    "PENDING" => "در انتظار",
                    "CANCLED_BY_USER" => "لغو شده",
                    "REJECTED_BY_ADMIN" => "رد شده",
                    default => "نامشخص",
                };
                $statusIcon = match ($tx['status']) {
                    "SUCCESS" => "✅",
                    "PENDING" => "⏳",
                    "CANCLED_BY_USER" => "🚫",
                    "REJECTED_BY_ADMIN" => "❌",
                    default => "⚠️",
                };
                $message = "⚠️ تراکنش شماره $txID در وضعیت $statusText است.";
                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => "$statusIcon | $statusText", 'callback_data' => 'not']
                        ]
                    ]
                ];
                return ['caption' => $message, 'reply_markup' => $keyboard];
            }

    }
}

function wallet($action, $user = null, $amount = null) {
    global $db_host, $db_user, $db_pass, $db_name;
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    switch ($action) {
        case 'get':
            if ($user) {
                $stmt = $conn->prepare("SELECT * FROM wallets WHERE chat_id = ?");
                $stmt->bind_param("i", $user);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $walletData = $result->fetch_assoc();
                } else {
                    return null;
                }
            } else {
                $stmt = $conn->prepare("SELECT * FROM wallets");
                $stmt->execute();
                $result = $stmt->get_result();
                $walletData = $result->fetch_all(MYSQLI_ASSOC);
            }
            return $walletData;

        case 'transactions':
            if ($user) {
                $stmt = $conn->prepare("SELECT * FROM wallet_transactions WHERE chat_id = ? ORDER BY created_at DESC");
                $stmt->bind_param("i", $user);
                $stmt->execute();
                $result = $stmt->get_result();
                $walletData = $result->fetch_all(MYSQLI_ASSOC);
            } else {
                $stmt = $conn->prepare("SELECT * FROM wallet_transactions ORDER BY created_at DESC");
                $stmt->execute();
                $result = $stmt->get_result();
                $walletData = $result->fetch_all(MYSQLI_ASSOC);
            }
            return $walletData;

        case 'create':
            $stmt = $conn->prepare("INSERT INTO wallets (chat_id, balance, created_at) VALUES (?, ?, NOW())");
            $stmt->bind_param("ii", $user, $amount);
            $stmt->execute();
            $stmt->close();
            return true;

        case 'INCREASE':
        case 'DECREASE':
            // Get current wallet balance
            $stmt = $conn->prepare("SELECT * FROM wallets WHERE chat_id = ?");
            $stmt->bind_param("i", $user);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $walletData = $result->fetch_assoc();
                $walletID = $walletData['id'];
                $balance = (int)$walletData['balance'];
            } else {
                return null;
            }
            //check amount type
            if (!is_int($amount)) {
                $amount = (int)$amount;
            }

            $newBalance = match ($action) {
                "INCREASE" => $balance + $amount,
                "DECREASE" => $balance - $amount,
            };
            
            // Update wallet balance
            $stmt = $conn->prepare("UPDATE wallets SET balance = ? WHERE chat_id = ?");
            $stmt->bind_param("ii", $newBalance, $user);
            $stmt->execute();
            $stmt->close();
            
            return $walletID;

    }
    
}

function createWalletTransaction($transactionID = null, $status = null, $walletID = null, $amount = null, $operation = null, $chat_id = null, $type = null) {
    global $db_host, $db_user, $db_pass, $db_name;
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($transactionID != null) {
        $stmt = $conn->prepare("UPDATE wallet_transactions SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $transactionID);
        $result = $stmt->execute();
    } elseif ($transactionID == null) {
        $stmt = $conn->prepare("INSERT INTO wallet_transactions (wallet_id, amount, operation, chat_id, status, type, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("iisiss", $walletID, $amount, $operation, $chat_id, $status, $type);
        $result = $stmt->execute();
        $transactionID = $stmt->insert_id;
    }

    $stmt->close();
    $conn->close();

    if (!$result) {
        return false;
    }

    return $transactionID;
}

function getWalletTransactions($page = 1, $itemsPerPage = 20, $search = null) {
    global $db_host, $db_user, $db_pass, $db_name;

    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");

    $offset = max(0, ($page - 1) * $itemsPerPage);
    $transactions = [];
    $total = 0;

    $searchLike = $search ? "%" . $conn->real_escape_string($search) . "%" : null;

    // Join with users table for name and telegram id
    $baseQuery = "FROM wallet_transactions wt LEFT JOIN users u ON wt.chat_id = u.chat_id";

    if ($search) {
        $countStmt = $conn->prepare("SELECT COUNT(*) AS total $baseQuery 
            WHERE wt.amount LIKE ? OR wt.operation LIKE ? OR wt.status LIKE ? OR wt.type LIKE ? OR wt.chat_id LIKE ? OR u.name LIKE ?");
        $countStmt->bind_param("ssssss", $searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $totalRow = $countResult->fetch_assoc();
        $total = $totalRow['total'];
        $countStmt->close();

        $stmt = $conn->prepare("SELECT wt.*, u.name AS user_name, u.telegram_id AS user_telegram, u.id AS user_id
                                $baseQuery 
                                WHERE wt.amount LIKE ? OR wt.operation LIKE ? OR wt.status LIKE ? OR wt.type LIKE ? OR wt.chat_id LIKE ? OR u.name LIKE ? 
                                ORDER BY wt.created_at DESC LIMIT ?, ?");
        $stmt->bind_param("ssssssii", $searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $offset, $itemsPerPage);
    } else {
        $countResult = $conn->query("SELECT COUNT(*) AS total $baseQuery");
        $totalRow = $countResult->fetch_assoc();
        $total = $totalRow['total'];

        $stmt = $conn->prepare("SELECT wt.*, u.name AS user_name, u.telegram_id AS user_telegram, u.id AS user_id
                                $baseQuery 
                                ORDER BY wt.created_at DESC LIMIT ?, ?");
        $stmt->bind_param("ii", $offset, $itemsPerPage);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    $stmt->close();
    $conn->close();

    return [
        'transactions' => $transactions,
        'total' => (int)$total
    ];
}

function parseWalletTransactionsType($type) {
    return match ($type) {
        "BUY" => "خرید",
        "CARD_TO_CARD" => "کارت به کارت",
        "DONE_BY_ADMIN" => "توسط ادمین",
        default => "نامشخص",
    };
}

function parseWalletTransactionsStatus($status) {
    return match ($status) {
        "SUCCESS" => "موفق",
        "PENDING" => "در انتظار",
        "CANCLED_BY_USER" => "لغو شده توسط کاربر",
        "REJECTED_BY_ADMIN" => "رد شده توسط ادمین",
        default => "نامشخص",
    };
}

function checkCoupon($couponCode) {
    global $panelToken;
    $endpoint = "https://api.connectix.vip/v1/seller/seller-plans/coupons";

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $panelToken]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    $couponsData = array_filter($data['coupons'], function ($item) use ($couponCode) {
        return $item['coupon_code'] === $couponCode;
    });
    foreach ($couponsData as $couponData) {
        return $couponData;
    }
}

function discount($query, $coupon = null) {
    $uid = UID;
    $action = explode(":", $query)[0];
    $data = explode(":", $query)[1];
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);
    $key = "user:steps:$uid";
    $RedisData = $redis->hgetall($key);
    switch ($action) {
        case "set":
            $price = $RedisData['price'];
            $redis->hmset($key, ['action' => 'discount']);
            $redis->expire($key, 1800);
            $redis->close();

            $message = "🎟 کد تخفیف خود را وارد کنید:";
            $keyboard = json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => '❌ | انصراف', 'callback_data' => "pay_card:$price"],
                    ]
                ]
            ]);
            return ['text' => $message, 'reply_markup' => $keyboard];
        case 'apply':
            // Check for dicount type
            $isPercent = !empty($coupon['per_cent']) && is_numeric($coupon['per_cent']);
            $isAmount  = !empty($coupon['amount']) && is_numeric($coupon['amount']);

            $originalPrice = str_replace(',', '', $RedisData['price']);
            $finalPrice    = $originalPrice;

            if ($isPercent) {
                $percentValue = (int)$coupon['per_cent'];

                $discountAmount = ($originalPrice * $percentValue) / 100;
                $finalPrice = $originalPrice - $discountAmount;

                // errorLog("coupon: {$coupon['code']} applied - {$percentValue}% discount ({$discountAmount} off) - original: {$originalPrice} → final: {$finalPrice}", "functions.php", 664);
            } elseif ($isAmount) {
                $amountValue = (int)$coupon['amount'];
                $discountAmount = $amountValue;

                $finalPrice = $originalPrice - $amountValue;

                if ($finalPrice < 0) {
                    $finalPrice = 0;
                }

                // errorLog("coupon: {$coupon['code']} applied - {$amountValue} amount discount - original: {$originalPrice} → final: {$finalPrice}", "functions.php", 675);
            } else {
                errorLog("coupon: {$coupon['code']} has no valid discount value!", "functions.php", 677);
                return false;
            }

            // $key = "user:steps:" . UID;
            $redis->hmset($key, [
                'coupon_code' => $coupon['coupon_code'],
                'original_price' => $originalPrice,
                'final_price' => $finalPrice,
            ]);
            $redis->hdel($key, 'action');
            $redis->expire($key, 1800);
            $redis->close();

            $discountAmountText = number_format($discountAmount);
            $tgResult = tg('sendMessage', [
                'chat_id' => $uid,
                'text' => "🎟 کد تخفیف با موفقیت اعمال شد 🎉\n💰 مبلغ $discountAmountText تومان از فاکتور کسر گردید.",
                ]);

            if (!($tgResult = json_decode($tgResult))->ok) {
                errorLog("Failed to send discount message to chat_id: $uid | Message: {$tgResult->description}", "functions.php", 698);
                exit;
            }
            return $finalPrice;

    }
}

function getTransactions($page = 1, $itemsPerPage = 20, $search = null, $id = null) {
    global $db_host, $db_user, $db_pass, $db_name;

    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");

    $offset = max(0, ($page - 1) * $itemsPerPage);
    $transactions = [];
    $total = 0;

    $searchLike = $search ? "%" . $conn->real_escape_string($search) . "%" : null;
    $idLike = $id ? $conn->real_escape_string($id) : null;


    // Only JOIN with users to get user name and telegram id (local database)
    $baseQuery = "FROM payments p LEFT JOIN users u ON p.chat_id = u.chat_id";

    if ($id) {
        $countStmt = $conn->prepare("SELECT COUNT(*) AS total $baseQuery WHERE p.id = ?");
        $countStmt->bind_param("i", $id);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $totalRow = $countResult->fetch_assoc();
        $total = $totalRow['total'];
        $countStmt->close();

        $stmt = $conn->prepare("SELECT p.*, u.name AS user_name, u.telegram_id AS user_telegram, u.id AS user_id $baseQuery WHERE p.id = ? ORDER BY p.created_at DESC LIMIT ?, ?");
        $stmt->bind_param("iii", $id, $offset, $itemsPerPage);
    } elseif ($search) {
        $countStmt = $conn->prepare("SELECT COUNT(*) AS total $baseQuery 
            WHERE p.order_number LIKE ? OR p.chat_id LIKE ? OR p.price LIKE ? OR p.coupon LIKE ? OR p.client_id LIKE ? OR p.plan_id LIKE ? OR u.name LIKE ?");
        $countStmt->bind_param("sssssss", $searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $totalRow = $countResult->fetch_assoc();
        $total = $totalRow['total'];
        $countStmt->close();

        $stmt = $conn->prepare("SELECT p.*, u.name AS user_name, u.telegram_id AS user_telegram, u.id AS user_id 
                                $baseQuery 
                                WHERE p.order_number LIKE ? OR p.chat_id LIKE ? OR p.price LIKE ? OR p.coupon LIKE ? OR p.client_id LIKE ? OR p.plan_id LIKE ? OR u.name LIKE ? 
                                ORDER BY p.created_at DESC LIMIT ?, ?");
        $stmt->bind_param("sssssssii", $searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $offset, $itemsPerPage);
    } else {
        $countResult = $conn->query("SELECT COUNT(*) AS total $baseQuery");
        $totalRow = $countResult->fetch_assoc();
        $total = $totalRow['total'];

        $stmt = $conn->prepare("SELECT p.*, u.name AS user_name, u.telegram_id AS user_telegram, u.id AS user_id 
                                $baseQuery 
                                ORDER BY p.created_at DESC LIMIT ?, ?");
        $stmt->bind_param("ii", $offset, $itemsPerPage);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    $stmt->close();
    $conn->close();

    return [
        'transactions' => $transactions,
        'total' => (int)$total
    ];
}

function always($info) {
    global $db_host, $db_user, $db_pass, $db_name;
    $uid = UID;
    $infoParts = explode(':', $info);
    $step = $infoParts[0];
    $data = $infoParts[1];
    switch ($step) {
        case 'select':
            $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
            $stmt = $conn->prepare("SELECT * FROM clients WHERE chat_id = ?");
            $stmt->bind_param("s", $uid);
            $stmt->execute();

            if ($conn->connect_error || $stmt->error) {
                errorLog("Error in connecting to DB or preparing statement: " . ($conn->connect_error ?? $stmt->error), "functions.php", 778);
            }

            $result = $stmt->get_result();
            $clients = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $conn->close();

            $keyboard = [];

            if (empty($clients)) {
                $keyboard[] = [['text' => '🤷🏻 | اکانتی به تلگرام شما متصل نیست', 'callback_data' => 'not']];
            } elseif (count($clients) == 1) {
                $clientData = getClientData($clients[0]['id']);
                $clientPlans = $clientData['plans'] ?? [];
                break;
            } else {
                foreach (array_reverse($clients) as $client) {
                    $clientData = getClientData($client['id']);
                    $plans = $clientData['plans'] ?? [];

                    // Find active and queued plans
                    $activePlan = null;
                    $queuedPlan = null;

                    foreach ($plans as $plan) {
                        if ($plan['is_active'] == 1) {
                            $activePlan = $plan;
                            break; // Found first active plan
                        }
                        if ($plan['is_in_queue'] && !$queuedPlan) {
                            $queuedPlan = $plan; // Found first queued plan
                        }
                    }

                    // If no active plan, use queued plan
                    $currentPlan = $activePlan ?? $queuedPlan;

                    if (!$currentPlan) {
                        $status = "🔴 غیرفعال";
                        $name = "بدون اشتراک";
                    } else {
                        $isActive = $currentPlan['is_active'] == 1;
                        $status = $isActive ? "🟢 فعال" : "🔵 در صف";

                        // Parse plan title
                        $parsed = parsePlanTitle($currentPlan['name'], true);
                        $name = $parsed['text'];
                    }

                    $keyboard[] = [
                        ['text' => $name, 'callback_data' => 'always_acc:' . $client['username']],
                        ['text' => $status . ' | ' . $client['username'], 'callback_data' => 'always_acc:' . $client['username']]
                    ];
                }
            }
            $keyboard[] = [
                ['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']
            ];

            $keyboard = json_encode(['inline_keyboard' => $keyboard]);

            $message = '📦 کدوم اکانت رو تمدید کنم؟';

            return ['text' => $message, 'reply_markup' => $keyboard];

        case 'acc':
            $acc = $data;
            renew("acc:$acc");
            $clientData = getClientByUsername($acc);

            $clientPlan = $clientData['plan_name'] ?? [];

            return renew("plan:$clientPlan");
    }

    renew('acc:' . $clientData['username']);

    // select last plan
    $lastPlan = $clientPlans[0];

    return renew('plan:' . $lastPlan['name']);
}

function smsPayment($action, $data) {
    global $db_host, $db_user, $db_pass, $db_name;
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    if ($conn->connect_error) {
        header("Content-Type: application/json");
        http_response_code(500); // Internal Server Error
        echo json_encode([
            'status' => 'error',
            'message' => 'Database connection failed'
        ]);
        errorLog("Error: DB Connection Error: {$conn->connect_error}", "functions.php", 873);
        return false;
    }

    //check for db table existence
    $tableCheckResult = $conn->query("SHOW TABLES LIKE 'sms_payments'");
    if ($tableCheckResult->num_rows === 0) {
        header("Content-Type: application/json");
        http_response_code(500); // Internal Server Error
        echo json_encode([
            'status' => 'error',
            'message' => 'Error: Database table [sms_payments] not found, please configure bank settings in admin panel.'
        ]);
        errorLog("Database table 'sms_payments' not found", "functions.php", 886);
        return false;
    }

    //delete rows that are expired and payment_id is null
    $conn->query("DELETE FROM sms_payments WHERE expired_at < NOW() AND payment_id IS NULL");

    // Perform actions based on the provided action
    switch ($action) {
        case 'save':
            $message = $data['message'];
            $amount = (int)$data['amount'];
            $adminBank = $data['bank'];

            $stmt = $conn->prepare("INSERT INTO sms_payments (message, amount, bank, expired_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))");
            $stmt->bind_param("sis", $message, $amount, $adminBank);
            $dbResult = $stmt->execute();
            $stmt->close();
            $conn->close();

            if (!$dbResult) {
                header("Content-Type: application/json");
                http_response_code(500); // Internal Server Error
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Failed to save SMS payment data'
                ]);
                errorLog("Failed to insert SMS payment data: {$conn->error}", "functions.php", 913);
                return false;
            }

            return true;
            
        case 'check':
            $amount = $data;
            $stmt = $conn->prepare("SELECT * FROM sms_payments WHERE amount = ? AND payment_id IS NULL AND expired_at > NOW() AND created_at <= NOW()");
            $stmt->bind_param("s", $amount);
            $stmt->execute();
            $result = $stmt->get_result();
            $payments = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $conn->close();

            if (count($payments) > 1) {
                return false;
            }

            $smsID = $payments[0]['id'] ?? null;

            return $smsID ?? false;

        case 'pay':
            $smsID = $data['sms_id'];
            $paymentID = $data['payment_id'];
            $paymentType = $data['payment_type'];

            $stmt = $conn->prepare("UPDATE sms_payments SET payment_id = ?, payment_type = ? WHERE id = ?");
            $stmt->bind_param("ssi", $paymentID, $paymentType, $smsID);
            $dbResult = $stmt->execute();
            $stmt->close();
            $conn->close();

            if (!$dbResult) {
                header("Content-Type: application/json");
                http_response_code(500); // Internal Server Error
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Failed to update SMS payment data'
                ]);
                errorLog("Failed to update SMS payment data: {$conn->error}", "functions.php", 955);
                return false;
            }

            return true;
        }
}

function callBackCheck($callback_data) {
    //check first part of data
    $data = explode('_', $callback_data);
    $cmd = $data[0];
    $query = $data[1];

    $result = match ($cmd) {
        "showClient" => showClient($query),
        "getTest" => getTest($query),
        "buy" => buy($query),
        "renew" => renew($query),
        "pay" => checkout($query),
        "payment" => paycheck($query),
        "app" => app($query),
        "guide" => guide($query),
        "wallet" => walletReqs($query),
        "discount" => discount($query),
        "always" => always($query),
        default => null,
    };

    return $result;
}

function guide($action) {
    $cbmid = CBMID;
    $cbid = CBID;
    $uid = UID;
    switch ($action) {
        case 'use':
            $videoPath = realpath('assets/videos/guide/use.mp4');
            if (!$videoPath) {
                tg('answerCallbackQuery', [
                    'callback_query_id' => $cbid,
                    'text' => '🙅🏻 فعلا ویدیو آموزشی در دسترس نمی باشد!',
                    'show_alert' => true
                ]);
                exit();
            }
            
            $result = tg('sendVideo',[
                'chat_id' => $uid,
                'video'   => new CURLFile($videoPath, 'video/mp4', 'guide.mp4'),
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => '↪️ | بازگشت', 'callback_data' => 'guide']
                        ],
                    ]
                ])
            ]);

            tg('deleteMessage',[
                'chat_id' => $uid,
                'message_id' => $cbmid
            ]);

            if (!($result = json_decode($result))->ok) {
                errorLog("Error in sending message to chat_id: $uid | Message: {$result->description}", "functions.php", 1021);
                exit;
            }
            exit();
        case 'install':
            $message = "⚙ سیستم عامل مورد نظر را انتخاب نمایید:";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '📱 | آیفون (iOS)', 'callback_data' => 'guide_ios'],
                        ['text' => '🤖 | اندروید', 'callback_data' => 'guide_android']
                    ],
                    [
                        ['text' => '🖥 | مک', 'callback_data' => 'guide_mac'],
                        ['text' => '💻 | ویندوز', 'callback_data' => 'guide_windows']
                    ],
                    [
                        ['text' => '🐧 | لینوکس (Debian)', 'callback_data' => 'guide_linux']
                    ],
                    [
                        ['text' => '↪️ | بازگشت', 'callback_data' => 'guide']
                    ]
                ]
            ];

            return ['text' => $message, 'reply_markup' => $keyboard];
        default:
            $videoPath = realpath("assets/videos/guide/$action.mp4");
            if (!$videoPath) {
                tg('answerCallbackQuery', [
                    'callback_query_id' => $cbid,
                    'text' => '🙅🏻 فعلا ویدیو آموزشی در دسترس نمی باشد!',
                    'show_alert' => true
                ]);
                exit();
            }
            
            $result = tg('sendVideo',[
                'chat_id' => $uid,
                'video'   => new CURLFile($videoPath, 'video/mp4', 'guide.mp4'),
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => '↪️ | بازگشت', 'callback_data' => 'guide']
                        ],
                    ]
                ])
            ]);

            tg('deleteMessage',[
                'chat_id' => $uid,
                'message_id' => $cbmid
            ]);

            if (!($result = json_decode($result))->ok) {
                errorLog("Error in sending message to chat_id: $uid | Message: {$result->description}", "functions.php", 1076);
                exit;
            }
            exit();
    }
}

function app($platform) {
    $data = getDownloadLinks($platform);
    $links = json_decode($data);
    // Parse platform name
    match ($platform) {
        'android' => $platformLabel = 'اندروید 🤖',
        'ios' => $platformLabel = 'آیفون (iOS) 📱',
        'windows' => $platformLabel = 'ویندوز 💻',
        'mac' => $platformLabel = 'مک 🖥',
        'linux' => $platformLabel = 'لینوکس 🐧',
        default => $platformLabel = 'نامشخص'
    };

    $message = "دانلود اپلیکیشن Connectix برای <b>{$platformLabel}</b>\n\nبرای دانلود از دکمه های زیر استفاده کنید.";

    $keyboard = [];

    foreach ($links as $link) {
        if (empty($link->label) || empty($link->url)) {
            continue;
        }

        // Replace "Download" with "دانلود"
        $link->label = str_replace('Download', 'دانلود مستقیم', $link->label);

        $keyboard[] = [
            [
                'text' => "📥 | {$link->label}",
                'url'  => $link->url
            ]
        ];
    }

    if ($platform !== 'ios' && $platform !== 'linux') {
        $directLink = match ($platform) {
            'android' => '4',
            'windows' => '5',
            'mac' => '11',
            default => ''
        };
        $keyboard[] = [
            ['text' => '📲 | دانلود از تلگرام', 'url' => "https://t.me/connectixapp/$directLink"]
        ];
    }
    $keyboard[] = [
        ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
        ['text' => '↪️ | بازگشت', 'callback_data' => 'apps']
    ];

    $replyMarkup = [
        'inline_keyboard' => $keyboard
    ];

    return [
        'text' => $message,
        'reply_markup' => json_encode($replyMarkup, JSON_UNESCAPED_UNICODE)
    ];
}

function addAccount($step, $data = null) {
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);
    $key = "user:steps:" . UID;
    switch ($step) {
        case "get_username":
            $redis->hmset($key, ['action' => 'add_account', 'step' => $step, 'username' => null]);
            $redis->expire($key, 1800);
            break;
        case "get_password":
            $redis->hmset($key, ['action' => 'add_account', 'step' => $step, 'username' => $data]);
            $redis->expire($key, 1800);
            break;
        case "add_account":
            $redisData = $redis->hgetall($key);
            $username = $redisData['username'];
            $password = $data;
            $uid = UID;

            $client = getClientByUsername($username);
            if (!$client || $client['password'] != $password || !$client['username']) {
                return "نام کاربری و یا رمز عبور اشتباه است.";
            }

            global $db_host, $db_user, $db_pass, $db_name;
            $conn = new mysqli($db_host, $db_user, $db_pass, $db_name); 
            $stmt = $conn->prepare("SELECT id FROM users WHERE chat_id = ?");
            $stmt->bind_param("s", $uid);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            
            // Update chat_id and user_id in clients table
            $stmt = $conn->prepare("UPDATE clients SET chat_id = ?, user_id = ? WHERE id = ?");
            $stmt->bind_param("sss", $uid, $user['id'], $client['id']);
            $stmt->execute();
            $stmt->close();
            $conn->close();
            $redis->del("user:steps:$uid");
            $redis->close();
            return "✅ اکانت با نام کاربری $username با موفقیت حساب تلگرام شما متصل شد.";


    }
}

function renew($info) {
    $infoParts = explode(':', $info);
    $step = $infoParts[0];
    $data = $infoParts[1];
    $back = $infoParts[2] ?? 'renew';
    $uid = UID;

    switch ($step) {
        case 'acc':
            $clientUsername = $data;
            $redis = new Redis();
            $redis->connect('127.0.0.1', 6379);

            // Set Redis key with user id
            $key = "user:steps:$uid";

            //Set Redis data
            $planData = [
                'acc'   => $clientUsername,
                'group' => null,
                'plan'  => null,
                'pay'   => null
            ];
            // Save in Hash foramt
            $redis->hmset($key, $planData);
            $redis->expire($key, 1800);
            $redis->close();

            //get client id from db
            global $db_host, $db_user, $db_pass, $db_name;
            $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
            $stmt = $conn->prepare("SELECT id FROM clients WHERE username = ?");
            $stmt->bind_param("s", $clientUsername);
            $stmt->execute();
            $result = $stmt->get_result();
            $clientId = $result->fetch_assoc()['id'];
            $stmt->close();
            $conn->close();

            $clientData = getClientData($clientId);
            $clientPlans = $clientData['plans'];
            //get last plan from client
            $lastPlan = $clientPlans[0];

            $lastPlanTitle = parsePlanTitle($lastPlan['name'])['text'];

            $message = "آخرین اشتراک خریداری شده برای اکانت $clientUsername به شرح زیر می باشد:\n\n📦 پلن: $lastPlanTitle\n\nتمدید با همین پلن انجام شود یا درخواست پلن دیگری دارید؟";

            $keyboard = [
                [
                    ['text' => '🔃 | تمدید با همین پلن', 'callback_data' => 'renew_plan:' . $lastPlan['name']]
                ],
                [
                    ['text' => '➕ | انتخاب پلن دیگر', 'callback_data' => 'group']
                ],
                [
                    ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
                    ['text' => '↪️ | بازگشت', 'callback_data' => $back]
                ]
            ];

            $keyboard = json_encode([
                'inline_keyboard' => $keyboard
            ]);

            return [
                "text" => $message,
                "reply_markup" => $keyboard
            ];

        case 'plan':
            $planTitle = $data;
            
            $plans = getSellerPlans("all-bot");

            if (!$plans) {
                return false;
            }

            foreach ($plans as $plan) {
                if ($plan['title'] === $planTitle) {
                    $planId = $plan['id'];
                    $planPrice = $plan['sell_price'];
                    break;
                }
            }

            if (!$planId) {
                $cbid = CBID;
                $text = "پلن مورد نظر یافت نشد\nاین پلن دیگر موجود نمی باشد\n\nلطفا پلن دیگری را انتخاب کنید";
                tg('answerCallbackQuery', [
                    'callback_query_id' => $cbid,
                    'text' => $text,
                    'show_alert' => true
                ]);
                exit;
            }

            $redis = new Redis();
            $redis->connect('127.0.0.1', 6379);

            // Set Redis key with user id
            $key = "user:steps:$uid";

            //get data from redis
            $planData = $redis->hgetall($key);

            //Set Redis data
            $planData = [
                'acc'   => $planData['acc'],
                'group' => $planData['group'],
                'plan'  => $planId,
                'price' => $planPrice,
                'pay'   => null
            ];
            // Save in Hash foramt
            $redis->hmset($key, $planData);
            $redis->expire($key, 1800);
            $redis->close();

            $planDetails = parsePlanTitle($planTitle);
            $planTitle = $planDetails['text'];
            $acc = ($planData['acc'] == 'new') ? "خرید اکانت جدید" : "تمدید اکانت: " . $planData['acc'];

            $message = "📝 اطلاعات اکانت شما\n\n📧 $acc\n📦 پلن: $planTitle\n💰 مبلغ: $planPrice تومان\n\nلطفا روش پرداخت را انتخاب کنید 👇🏻";
            $keyboard = json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => '💳 | کارت به کارت', 'callback_data' => "pay_card:$planPrice"]
                    ],
                    [
                        ['text' => '👝 | کیف پول ( موجودی ' . number_format(wallet('get', $uid)['balance']) . ' تومان)', 'callback_data' => "pay_wallet:$planPrice"],
                    ],
                    [
                        ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
                        ['text' => '↪️ | بازگشت', 'callback_data' => 'renew_acc:' . $planData['acc']]
                    ]
                ]
            ]);

            return [
                "text" => $message,
                "reply_markup" => $keyboard
            ];
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

            // Check for acc
            $acc = $redis->hget($key, 'acc');
            if (!$acc) {
                $acc = 'new';
            }

            //Set Redis data
            $planData = [
                'acc'   => $acc,
                'group' => $data,
                'plan'  => null,
                'pay'   => null
            ];
            // Save in Hash foramt
            $redis->hmset($key, $planData);
            
            // Set expire time for 30mins 
            $redis->expire($key, 1800);

            // Close Redis connection
            $redis->close();

            // Get plans based on group
            $plans = getSellerPlans($data);

            if (empty($plans)) {
                tg('answerCallbackQuery', [
                    'callback_query_id' => CBID,
                    'text' => 'هیچ پلنی در این گروه یافت نشد!',
                    'show_alert' => true
                ]);
                exit;
            }

            // Find the maximum number of devices in this group
            $maxDevices = 0;
            foreach ($plans as $plan) {
                $devices = (int)$plan['count_of_devices'];
                if ($devices > $maxDevices) {
                    $maxDevices = $devices;
                }
            }

            // Limit to a maximum of 4 devices
            $maxDevices = min($maxDevices, 4);

            if ($maxDevices === 0) {
                tg('answerCallbackQuery', [
                    'callback_query_id' => CBID,
                    'text' => 'هیچ پلن معتبری در این گروه وجود ندارد.',
                    'show_alert' => true
                ]);
                exit;
            }

            // Construct keyboard buttons for device count (two in each row)
            $keyboard = [];

            // First, sort all the buttons from small to large
            $buttons = [];
            for ($i = 1; $i <= $maxDevices; $i++) {
                $emoji = match ($i) {
                    1 => '1️⃣',
                    2 => '2️⃣',
                    3 => '3️⃣',
                    4 => '4️⃣',
                    default => "$i"
                };
                $text = "$emoji | $i کاربر";

                $buttons[] = ['text' => $text, 'callback_data' => "buy_count:$i"];
            }

            // Now, I'll pair the buttons and add them to the keyboard.
            for ($i = 0; $i < count($buttons); $i += 2) {
                $row = [];

                // If we have a pair of buttons, put the larger one first, then the smaller one.
                if (isset($buttons[$i + 1])) {
                    $row[] = $buttons[$i + 1];  // First add the larger one
                    $row[] = $buttons[$i];      // Then add the smaller one
                } else {
                    // If only one is left (e.g. 3 of them)
                    $row[] = $buttons[$i];
                }

                $keyboard[] = $row;
            }

            // Home and back buttons
            $keyboard[] = [
                ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
                ['text' => '↪️ | بازگشت', 'callback_data' => 'group']
            ];

            $keyboard = json_encode(['inline_keyboard' => $keyboard]);

            $groupName = parseType($data);

            $variables = ['groupName' => $groupName];

            return [
                "text" => message('count', $variables),
                "reply_markup" => $keyboard
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

            $message = "فهرست و قیمت سرویس‌های $data کاربره $planGroupName به شرح لیست زیر است.\n\nلطفاً سرویس مدنظر خود را انتخاب کنید: 👇";
            return [
                "text" => $message,
                "reply_markup" => $keyboard
            ];
        case 'plan':
            $planId = $data;
            $redis = new Redis();
            $redis->connect('127.0.0.1', 6379);

            // Set Redis key with user id
            $key = "user:steps:$uid";

            // Get Data from redis
            $planData = $redis->hgetall($key);

            $planAcc = $planData['acc'];

            $planGroup = $planData['group'];

            //get plan price 
            $plans = getSellerPlans($planGroup);
            foreach ($plans as $plan) {
                if ($plan['id'] == $planId) {
                    $planPrice = $plan['sell_price'];
                }
            }

            $planData = [
                'acc'   => $planAcc,
                'group' => $planGroup,
                'plan'  => $planId,
                'price' => $planPrice,
                'pay'   => null
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
                        ['text' => '💳 | کارت به کارت', 'callback_data' => "pay_card:$planPrice"]
                    ],
                    [
                        ['text' => '👝 | کیف پول ( موجودی ' . number_format(wallet('get', $uid)['balance']) . ' تومان)', 'callback_data' => "pay_wallet:$planPrice"],
                        // ['text' => '🔜 | روش های دیگر به زودی...', 'callback_data' => 'not'],
                    ],
                    [
                        ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
                        ['text' => '↪️ | بازگشت', 'callback_data' => 'buy_count:' . $selectedPlan['count_of_devices']]
                    ]
                ]
            ]);

            return [
                "text" => $message,
                "reply_markup" => $keyboard
            ];
    }
}

function checkout($data) {
    global $uid;
    $parts = explode(':', $data);
    $method = $parts[0];
    $amount = $parts[1];

    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);
    $planData = $redis->hgetall("user:steps:$uid");
    $planData['pay'] = $method;
    $key = "user:steps:$uid";
    $redis->hmset($key, $planData);
    $redis->expire($key, 1800);
    $planData = $redis->hgetall("user:steps:$uid");
    if ($planData['action'] == 'discount') {
        $redis->hdel($key, 'action');
    }
    $redis->close();

    switch ($method) {
        case 'card':
            $variables = [
                'amount' => $amount
            ];
            $message = message('card', $variables);
            $keyboard = json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => '🎟 | وارد کردن کد تخفیف', 'callback_data' => "discount_set:$amount"],
                    ],
                    [
                        ['text' => '❌ | انصراف', 'callback_data' => 'main_menu'],
                    ]
                ]
            ]);
        
            return [
                "text" => $message,
                "reply_markup" => $keyboard
            ];

        case 'wallet':
            //check wallet balance
            $walletBalance = wallet('get', $uid);
            $amountInt = str_replace(',', '', $amount);
            if ($walletBalance['balance'] < $amountInt) {

                $message = "❌ موجودی کیف پول شما کافی نیست!";
                $message .= "\n\n💰 موجودی کیف پول شما: " . number_format($walletBalance['balance']) . " تومان";
                $message .= "\n📦 قیمت پلن: " . number_format($amountInt) . " تومان";

                $result = tg('answerCallbackQuery', [
                    'callback_query_id' => CBID,
                    'text' => $message,
                    'show_alert' => true
                ]);
                
                if (!($result = json_decode($result))->ok) {
                    errorLog("Error in sending message to chat_id: $uid | Message: {$result->description}", "functions.php", 1637);
                }
                exit;
            }

            $plans = getSellerPlans("all-bot");
            foreach ($plans as $plan) {
                if ($plan['id'] == $planData['plan']) {
                    $selectedPlan = $plan;
                    break;
                }
            }

            $isPaid = null;
            $client_id = ($planData['acc'] == 'new') ? 'new' : getClientByUsername($planData['acc'])['id'];

            // Save payment to database
            $paymentId = savePayment( $client_id, $selectedPlan['id'], $selectedPlan['sell_price'], $isPaid, $planData['pay']);

            // Create wallet transaction
            $txID = createWalletTransaction(null, 'SUCCESS', $walletBalance['id'], $amountInt, 'DECREASE', $uid, 'BUY');

            // Decrement wallet balance
            $walletBalance = wallet('DECREASE', $uid, $amountInt);
            if (!$walletBalance) {
                errorLog("Error in decrementing wallet balance for user_id: $uid", "functions.php", 1659);
                exit();
            }

            // Delete last message
            $result = tg('deleteMessage', [
                'chat_id' => $uid,
                'message_id' => CBMID
            ]);

            // Accept payment
            paycheck("accept:$paymentId");

    }
}

function getClientByUsername($username) {
    global $panelToken;
    $endpoint = "https://api.connectix.vip/v1/seller/clients?username=$username";

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $panelToken]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['clients']['data'][0];
}

function payment($receipt, $action) {
    try {
        $bot_config = json_decode(file_get_contents('setup/bot_config.json'));
        $autoPayment = $bot_config->bank->name ? true : false;
        $admin_chat_id = $bot_config->admin_id ?? null;
        $admin_chat_id2 = $bot_config->admin_id_2 ?? null;
        $admin_chat_id3 = $bot_config->admin_id_3 ?? null;
        $admins =array_filter([$admin_chat_id, $admin_chat_id2, $admin_chat_id3], fn($value) => $value !== null && $value !== '');
        $uid = UID;
        switch ($action) {
            case 'buy':
                $redis = new Redis();
                $redis->connect('127.0.0.1', 6379);
                $RedisData = $redis->hgetall("user:steps:$uid");

                $plans = getSellerPlans("all-bot");
                foreach ($plans as $plan) {
                    if ($plan['id'] == $RedisData['plan']) {
                        $selectedPlan = $plan;
                        break;
                    }
                }

                $isPaid = null;
                $client_id = ($RedisData['acc'] == 'new') ? 'new' : getClientByUsername($RedisData['acc'])['id'];
                
                $planPice = isset($RedisData['final_price']) ? number_format($RedisData['final_price']) : $selectedPlan['sell_price'];

                $coupon = $RedisData['coupon_code'] ?? null;
                
                // Save payment to database
                $paymentId = savePayment( $client_id, $selectedPlan['id'], $planPice, $isPaid, $RedisData['pay'], $coupon);

                // Check autopayment setting
                if ($autoPayment) {
                    $amount = str_replace(',', '', $planPice);
                    $smsCheck = smsPayment('check', $amount);
                    if ($smsCheck != false) {
                        $smsID = $smsCheck;

                        // Increase wallet balance
                        paycheck("accept:$paymentId");

                        $data = [
                            'sms_id' => $smsID,
                            'payment_id' => $paymentId,
                            'payment_type' => 'buy',
                        ];
                        smsPayment('pay', $data);
                        exit;
                    }
                }
                
                $photo_id = end($receipt)['file_id'];
                $planName = parsePlanTitle($selectedPlan['title'])['text'];

                // Receipt received message
                $result = tg('sendMessage',[
                    'chat_id' => $uid,
                    'text' => "✅ سند پرداخت شما با موفقیت دریافت شد.\n\n📦 پلن انتخابی شما:\n $planName\n\n⌛ لطفا منتظر تایید بمانید."
                ]);

                if (!($result = json_decode($result))->ok) {
                    errorLog("Failed to send receipt error message to chat_id: $uid | Message: {$result->description}", "functions.php", 1752);
                    exit;
                }

                $caption = "📃 سند واریزی مورد تایید میباشد؟";
                $caption .= "\n\n📦 پلن: $planName";
                $caption .= "\n💸 مبلغ واریزی: $planPice";
                if ($RedisData['coupon_code']) {
                    $caption .= "\n💵 مبلغ اصلی: " . number_format($RedisData['original_price']);
                    $caption .= "\n🎁 کد تخفیف استفاده شده: " . $RedisData['coupon_code'];
                }
                
                //send receipt image to admin(s)
                foreach ($admins as $admin) {
                    $result = tg('sendPhoto',[
                        'chat_id' => $admin,
                        'photo' => $photo_id,
                        'caption' => $caption,
                        'reply_markup' => json_encode([
                            'inline_keyboard' => [
                                [
                                    ['text' => '✅ |  تایید', 'callback_data' => "payment_accept:$paymentId"],
                                    ['text' => '❌ |  لغو', 'callback_data' => "payment_reject:$paymentId"],
                                ]
                            ]
                        ])
                    ]);
    
                    if (!($result = json_decode($result))->ok) {
                        errorLog("Failed to send receipt error message to chat_id: $uid | Message: {$result->description}", "functions.php", 1781);
                        exit;
                    }
                }

                $redis->del("user:steps:$uid");
                $redis->close();
                return true;

            case 'wallet':
                $redis = new Redis();
                $redis->connect('127.0.0.1', 6379);
                $walletData = $redis->hgetall("user:steps:$uid");
                $txID = $walletData['txID'];
                $amount = $walletData['amount'];
                $textAmount = number_format($amount);

                // Check autopayment setting
                if ($autoPayment) {
                    $smsCheck = smsPayment('check', $amount);
                    if ($smsCheck != false) {
                        $smsID = $smsCheck;

                        // Increase wallet balance
                        walletReqs("accept:$txID");

                        $data = [
                            'sms_id' => $smsID,
                            'payment_id' => $txID,
                            'payment_type' => 'wallet',
                        ];
                        smsPayment('pay', $data);
                        exit;
                    }
                }

                $photo_id = end($receipt)['file_id'];

                // Receipt received message
                $result = tg('sendMessage',[
                    'chat_id' => $uid,
                    'text' => "✅ سند پرداخت شما با موفقیت دریافت شد.\n\n💰 افزایش موجودی کیف پول:\n💵 مبلغ : $textAmount\n\n⌛ لطفا منتظر تایید بمانید."
                ]);

                if (!($result = json_decode($result))->ok) {
                    errorLog("Failed to send receipt error message to chat_id: $uid | Message: {$result->description}", "functions.php", 1826);
                    exit;
                }

                $user = getUser($uid);
                $userID = $user['telegram_id'] ?? null;

                if (!$userID) {
                    $userID = "نامشخص";
                }

                //send receipt image to admin(s)
                foreach ($admins as $admin) {
                    $result = tg('sendPhoto',[
                        'chat_id' => $admin,
                        'photo' => $photo_id,
                        'caption' => "📃 سند واریزی مورد تایید میباشد؟\n\n💰 افزایش موجودی کیف پول:\n🔢 آیدی: <code>$uid</code>\n👤 نام کاربری: @$userID\n💵 مبلغ : $textAmount",
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode([
                            'inline_keyboard' => [
                                [
                                    ['text' => '✅ |  تایید', 'callback_data' => "wallet_accept:$txID"],
                                    ['text' => '❌ |  لغو', 'callback_data' => "wallet_reject:$txID"],
                                ]
                            ]
                        ])
                    ]);

                    if (!($result = json_decode($result))->ok) {
                        errorLog("Failed to send receipt error message to chat_id: $uid | Message: {$result->description}", "functions.php", 1855);
                        exit;
                    }
                }

        }
    } catch (Exception $e) {
        errorLog("Error: Database operation failed: " . $e->getMessage(), "functions.php", 1862);
    }
}

function savePayment ($client_id, $plan_id, $price, $isPaid, $method, $coupon = null) {
    global $db_host, $db_user, $db_pass, $db_name;
    $uid = UID;
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    $stmt = $conn->prepare("INSERT INTO payments (chat_id, client_id, plan_id, price, coupon, is_paid, method, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("sssssss", $uid, $client_id, $plan_id, $price, $coupon, $isPaid, $method);
    $result = $stmt->execute();
    $paymentId = $conn->insert_id;
    
    //Today Date
    $yy = date('y');
    $mm = date('m');
    $dd = date('d');
    
    //last order of the day
    $prefix = "CX{$yy}{$mm}{$dd}";
    $q = $conn->prepare("
        SELECT COUNT(*) AS total 
        FROM payments 
        WHERE order_number LIKE CONCAT(?, '%')
    ");
    $q->bind_param("s", $prefix);
    $q->execute();
    $count = $q->get_result()->fetch_assoc()['total'] + 1;
    $orderNumber = $prefix . str_pad($count, 2, '0', STR_PAD_LEFT);

    // Submit order_number to database
    $u = $conn->prepare("
        UPDATE payments 
        SET order_number = ? 
        WHERE id = ?
    ");
    $u->bind_param("si", $orderNumber, $paymentId);
    $u->execute();

    $conn->commit();

    $stmt->close();
    $conn->close();

    if (!$result) {
        errorLog("Error in inserting payment: " . $conn->error, "functions.php", 1911);
    } 
    return $paymentId;
}

function paycheck($query) {
    $parts = explode(':', $query);
    $paymentStatus = $parts[0];
    $paymentId = $parts[1];

    global $db_host, $db_user, $db_pass, $db_name;
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    $stmt = $conn->prepare("SELECT * FROM payments WHERE id = ?");
    $stmt->bind_param("i", $paymentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $payment = $result->fetch_assoc();
    $orderNumber = $payment['order_number'];
    $chat_id = $payment['chat_id'];
    $paidStatus = $payment['is_paid'];
    $stmt->close();

    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);
    $key = "user:steps:" . UID;
    $redisData = $redis->hgetall($key);
    $redis->close();

    switch ($paymentStatus) {
        case "accept":

            if ($paidStatus != null) {

                $paidStatusName = match ($paidStatus) {
                    "0" => 'رد شده',
                    "1" => 'تایید شده'
                };
                $paidStatusIcon = match ($paidStatus) {
                    "0" => '❌',
                    "1" => '✅'
                };
                $caption = "⚠️ سفارش شماره <code>$orderNumber</code> در وضعیت $paidStatusName است. ";
                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => "$paidStatusIcon | $paidStatusName", 'callback_data' => 'not']
                        ]
                    ]
                ];

                return ['caption' => $caption, 'reply_markup' => $keyboard];

            }
            // Create or Update Account plan
            switch ($payment['client_id']) {
                // Create New Account
                case 'new':
                    $user = getUser($chat_id);
                    $name = $user['name'];
                    $telegram_id = $user['telegram_id'];
                    $user_id = $user['id'];
                    $response = createClient($name, $chat_id, $telegram_id, $payment['plan_id']);
                    if ($response === false) {
                        errorLog("Error in creating client: " . $conn->error, "functions.php", 1977);
                        break 2;
                    }
                    $client_id = json_decode($response, true)['client_id'];
                    $client = getClientData($client_id);
                    $plan = $client['plans'][0];
                    $planName = parsePlanTitle($plan['name'])['text'];
                    $clientUsername = $client['username'] ?? '';
                    $clientPassword = $client['password'] ?? '';
                    $clientSublink = $client['subscription_link'] ?? null;
                    $clientCOD = $client['count_of_devices'] ?? 0;

                    // Create Cleint in DB
                    $stmt = $conn->prepare("INSERT INTO clients (id, count_of_devices, username, password, chat_id, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->bind_param("sissii", $client_id, $clientCOD, $clientUsername, $clientPassword, $chat_id, $user_id);
                    $result = $stmt->execute();
                    $stmt->close();
                    if (!$result) {
                        errorLog("Error in inserting client: " . $conn->error, "functions.php", 1995);
                    }

                    //Update Client ID in Payment
                    $stmt = $conn->prepare("UPDATE payments SET client_id = ? WHERE id = ?");
                    $stmt->bind_param("si", $client_id, $paymentId);
                    $result = $stmt->execute();
                    $stmt->close();
                    if (!$result) {
                        errorLog("Error in updating payment: " . $conn->error, "functions.php", 2004);
                    }

                    // Send account data to user
                    $msg = "\n\n👤 نام کاربری: <code>$clientUsername</code>\n🔑 رمز عبور: <code>$clientPassword</code>\n📦 پلن:\n$planName\n";
                    if ($clientSublink) {
                        $msg .= "\n🔗 لینک سابسکریبشن: <code>$clientSublink</code>";
                    }
                    $message = 'اکانت شما با موفقیت ایجاد شد.';
                    $message .= $msg;
                    $keyboard = [
                        'inline_keyboard' => [
                            [
                                ['text' => '📦 | اکانت های من', 'callback_data' => 'accounts']
                            ],
                            [
                                ['text' => '↪️ | بازگشت', 'callback_data' => 'new_menu']
                            ]
                        ]
                    ];
                    tg ('sendMessage',[
                        'chat_id' => $chat_id,
                        'text' => $message,
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode($keyboard)
                    ]);
                    
                    break;
                default: // Update Account
                    $response = updateClient($payment['client_id'], $payment['plan_id']);
                    if ($response === false) {
                        errorLog("Error in updating client: " . $conn->error, "functions.php", 2035);
                        break 2;
                    }
                    $client_id = $payment['client_id'];
                    $client = getClientData($client_id);
                    $clientUsername = $client['username'] ?? '';
                    $plan = $client['plans'][0] ?? null;
                    $planName = parsePlanTitle($plan['name'])['text'];

                    $msg = "\n\n👤 نام کاربری: <code>$clientUsername</code>\n📦 پلن:\n $planName";
                    $message = "اکانت شما با موفقیت تمدید شد.\n\n";
                    $message .= "🛍 شماره سفارش: <code>$orderNumber</code>\n"; 
                    $message .= $msg;
                    $keyboard = [
                        'inline_keyboard' => [
                            [
                                ['text' => '📦 | اکانت های من', 'callback_data' => 'accounts']
                            ],
                            [
                                ['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']
                            ]
                        ]
                    ];
                    tg ('sendMessage',[
                        'chat_id' => $chat_id,
                        'text' => $message,
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode($keyboard)
                    ]);
                    break;
            }


            $stmt = $conn->prepare("UPDATE payments SET is_paid = 1 WHERE id = ?");
            $stmt->bind_param("i", $paymentId);
            $result = $stmt->execute();
            $stmt->close();
            $conn->close();
            if (!$result) {
                errorLog("Error in updating payment: " . $conn->error, "functions.php", 2074);
            }

            $plan = getSellerPlans($payment['plan_id']);
            $planName = parsePlanTitle($plan['title'])['text'];
            $planPrice = $redisData['final_price'] ?? $plan['sell_price'] ?? null;
            // Update paycheck message for admin
            $caption = "✅ سفارش شماره <code>$orderNumber</code> با موفقیت تایید شد\n\n👤 نام کاربری: <code>$clientUsername</code>\n📦 پلن:\n $planName\n💵 مبلغ: $planPrice";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✅ | تایید شده', 'callback_data' => 'not']
                    ]
                ]
            ];

            return ['caption' => $caption, 'reply_markup' => $keyboard];


        case "reject":
            if ($paidStatus != null) {

                $paidStatusName = match ($paidStatus) {
                    "0" => 'رد شده',
                    "1" => 'تایید شده'
                };
                $paidStatusIcon = match ($paidStatus) {
                    "0" => '❌',
                    "1" => '✅'
                };
                $caption = "⚠️ سفارش شماره <code>$orderNumber</code> در وضعیت $paidStatusName است. ";
                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => "$paidStatusIcon | $paidStatusName", 'callback_data' => 'not']
                        ]
                    ]
                ];

                return ['caption' => $caption, 'reply_markup' => $keyboard];
                
            }

            // Update paid status to false
            $stmt = $conn->prepare("UPDATE payments SET is_paid = 0 WHERE id = ?");
            $stmt->bind_param("i", $paymentId);
            $result = $stmt->execute();
            $stmt->close();
            if (!$result) {
                errorLog("Error in updating payment: " . $conn->error, "functions.php", 2123);
            }

            $plan = getSellerPlans($payment['plan_id']);
            $planName = parsePlanTitle($plan['title'])['text'];
            $planPrice = $redisData['final_price'] ?? $plan['sell_price'] ?? null;

            tg('sendMessage',[
                'chat_id' => $chat_id,
                'text' => "❌پرداخت شما تایید نشد.\n🛍 شماره سفارش: <code>$orderNumber</code>\n\n جهت اطلاع از وضعیت پرداخت، لطفا با پشتیبانی تماس بگیرید.",
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => '🏡 | خانه', 'callback_data' => 'new_menu']
                        ]
                    ]
                ])
            ]);

            // Update paycheck message for admin
            $caption = "❌ سفارش شماره <code>$orderNumber</code> تایید نشد\n\n📦 پلن:\n $planName\n💵 مبلغ: $planPrice";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '❌ | تایید نشده', 'callback_data' => 'not']
                    ]
                ]
            ];

            return ['caption' => $caption, 'reply_markup' => $keyboard];
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
        errorLog("❌ اکانت یافت نشد یا خطا در ارتباط با سرور. | آیدی آکانت: $cid", "functions.php", 2179);
        return false;
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
        ? ['text' => '📆 | رزرو اشتراک جدید برای این اکانت', 'callback_data' => "renew_acc:" . $client['username'] . ":accounts"]
        : ['text' => '🛒 | خرید اشتراک برای این اکانت', 'callback_data' => "renew_acc:" . $client['username'] . ":accounts"];

    $keyboard = [
        'inline_keyboard' => [
            [ $actionButton ],
            [
                ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
                ['text' => '↪️ | بازگشت', 'callback_data' => 'accounts']
            ]
        ]
    ];

    $data = [
        'text' => $message,
        'reply_markup' => $keyboard
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
        case "Iran Access":
            $validPlans = [];
            foreach ($data['seller_plan_group'] as $group) {
                foreach ($group['seller_plans'] as $plan) {
                    if ($plan['is_displayed_in_robot'] == true && $plan['type'] == "Premium" && stripos($plan['title'], 'Iran Access') !== false) {
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
            // Search by id
            $validPlans = [];
            foreach ($data['seller_plan_group'] as $group) {
                foreach ($group['seller_plans'] as $plan) {
                    if (
                        // $plan['is_displayed_in_robot'] == false && 
                        $plan['id'] == $type
                        ) {
                        $validPlans[] = $plan;
                    }
                }
            }
            
            if (empty($validPlans)) {
                return false;
            }
            return $validPlans[0];
    }
}

function getTest($type) {
    try {
        static $plans = null;
    
        if ($plans === null) {
            $plans = getSellerPlans("free");
            if ($plans === false) {
                errorLog("Error: Failed to retrieve seller plans", "functions.php", 2398);
                $message = "خطا در دریافت لیست پلن‌ها از سرور";
                return ['text' => $message, 'reply_markup' => []];
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
            errorLog("Error: No suitable plan found for type: $type", "functions.php", 2427);
            $message = "پلن مناسب برای نوع درخواستی ($type) یافت نشد.";
            return ['text' => $message, 'reply_markup' => []];
        }

        // Get user data
        global $db_host, $db_user, $db_pass, $db_name, $panelToken;
        $uid = UID;
        $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
        if ($conn->connect_error) {
            errorLog("Error: Database connection failed: " . $conn->connect_error, "functions.php", 2437);
            return ['text' => 'خطا در اتصال به دیتابیس', 'reply_markup' => []];
        }
        
        $stmt = $conn->prepare("SELECT * FROM users WHERE chat_id = ?");
        if (!$stmt) {
            errorLog("Error: Prepare failed: " . $conn->error, "functions.php", 2443);
            $conn->close();
            return ['text' => 'خطا در دریافت اطلاعات کاربر', 'reply_markup' => []];
        }
        
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $userResult = $stmt->get_result();
        $user = $userResult->fetch_assoc();
        $stmt->close();
        
        if (!$user) {
            errorLog("Error: User not found for chat_id: $uid", "functions.php", 2455);
            $conn->close();
            return ['text' => 'کاربر یافت نشد', 'reply_markup' => []];
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
            return ['text' => '⚠️ شما قبلا درخواست تست داده اید!', 'reply_markup' => $keyboard];
        }

        $name = $user['name'] ?? null;
        $telegram_id = $user['telegram_id'] ?? null;
        $user_id = $user['id'] ?? null;
        $planId = $selectedPlan['id'];

        $response = createClient($name, $uid, $telegram_id, $planId);
        if ($response === false) {
            $conn->close();
            return ['text' => 'خطا در ایجاد اکانت روی سرور', 'reply_markup' => []];
        }

        $result = json_decode($response, true);
        if (!isset($result['client_id'])) {
            errorLog("Error: Failed to create client on panel. Response: " . print_r($result, true), "functions.php", 2486);
            $conn->close();
            return ['text' => 'خطا در ایجاد اکانت', 'reply_markup' => []];
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
            errorLog("Error: Database operation failed: " . $e->getMessage(), "functions.php", 2535);
            $conn->close();
            return ['text' => 'خطا در ذخیره اطلاعات اکانت', 'reply_markup' => []];
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
                    ['text' => '📦 | اکانت های من', 'callback_data' => 'accounts']
                ],
                [
                    ['text' => '↪️ | بازگشت', 'callback_data' => 'new_menu']
                ]
            ]
        ];
        
        return ['text' => $message, 'reply_markup' => $keyboard];
            
    } catch (Exception $e) {
        errorLog("Error: Create test account exception: " . $e->getMessage(), "functions.php", 2571);
        return ['text' => 'خطا: ' . $e->getMessage(), 'reply_markup' => []];
    }
}

function createClient($name, $uid, $telegram_id, $planId) {
    global $panelToken;
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
        errorLog("Error: cURL failed to create client: " . curl_error($ch), "functions.php", 2638);
    }
    curl_close($ch);
    return $response;
}

function updateClient($client_id, $plan_id) {
    global $panelToken;

    $endpoint = 'https://api.connectix.vip/v1/seller/clients/add-plan';

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            "id" => $client_id,
            "plan_id" => $plan_id
        ]),
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$panelToken}",
            "Accept: application/json",
            "Content-Type: application/json",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
        ],
    ]);
    $response = curl_exec($ch);
    if ($response === false || curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);

        errorLog("Error: cURL updateClient failed | HTTP: {$httpCode} | cURL: {$curlErr} | Response: {$response} | Client ID: $client_id | Plan ID: $plan_id", "functions.php", 2671);

        return false;
    }
    curl_close($ch);

    return $response;
}

function parsePlanTitle($title, $short = false) {
    $title = trim($title);

    // Exact pattern for all Connectix plans
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

    // Convert period to text
    $periodText = match($periodUnit) {
        'D' => "$periodNum روز",
        'W' => "$periodNum هفته",
        'M' => "$periodNum ماه",
        'Y' => "$periodNum سال",
        default => "$periodNum ماه"
    };

    // Parse extras
    $extras = [];
    if ($giftDays) $extras[] = "+$giftDays روز هدیه";
    if (str_contains($extraText, 'Sublink')) $extras[] = 'ساب‌لینک';
    if (str_contains($extraText, 'Static IP')) $extras[] = 'آی‌پی ثابت';
    if (str_contains($extraText, 'Iran Access')) $extras[] = 'ایران اکسس';

    // Short Mode (Just show devices and Traffic)
    if ($short) {
        if ($isFree) {
            $text = "تست رایگان • $periodText";
        } elseif ($isUnlimited) {
            $text = "$devices دستگاه • نامحدود • $periodText";
        } else {
            // If traffic is specified, show total traffic
            if ($traffic) {
                $text = "$devices دستگاه • {$traffic}GB";
            } else {
                // fallback 
                $text = "$devices دستگاه • $periodText";
            }

            if (in_array('ساب‌لینک', $extras)) {
                $text .= " • ساب‌لینک";
            } elseif (in_array('آی‌پی ثابت', $extras)) {
                $text .= " • آی‌پی ثابت";
            } elseif (empty($extras) || (count($extras) === 1 && $extras[0] === "+$giftDays روز هدیه")) {
                $text .= " • ویژه";
            }
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

    // Full Mode (Default)
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

    // Add "ویژه" if there are no extras
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
        "Iran Access" => "ایران اکسس",
        "Static IP" => "آی‌پی ثابت",
        default => $type
    };
    return $name;
}

function keyboard($keyboard) {
    try {
        $uid = UID;
        global $db_host, $db_user, $db_pass, $db_name;
        //get bot data from bot_config.json
        $data = file_get_contents('setup/bot_config.json');
        $config = json_decode($data, true);
        $supportTelegram = $config['support_telegram'] ?? '';
        $channelTelegram = $config['channel_telegram'] ?? '';
        switch ($keyboard) {
            case "main_menu":
                //check if user get test account
                $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
                $stmt = $conn->prepare("SELECT test FROM users WHERE chat_id = ?");
                $stmt->bind_param("s", $uid);
                $stmt->execute();
                //handle error
                if ($conn->connect_error || $stmt->error) {
                    errorLog("Error in connecting to DB or preparing statement: " . ($conn->connect_error ?? $stmt->error), "functions.php", 2832);
                }
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();
                $stmt->close();
                $conn->close();

                // include test button row only when user didn't get test account
                $testBtn = ($user['test'] == 0) ? [
                    ['text' => '🎁 | دریافت اکانت تست', 'callback_data' => 'get_test']
                ] : [
                    ['text' => '🙋🏻 | همون همیشگی', 'callback_data' => 'always_select:0']
                ];
                
                //panel link
                if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
                    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                    || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')) {
                    $protocol = "https";
                } else {
                    $protocol = "http";
                }

                $current_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
                $app_url = str_replace("bot.php", "app.php", $current_url);
                
                $panelBtn = match (strval($uid)) {
                    strval($config['admin_id']) => [
                        ['text' => '👨🏻‍💻 | پنل مدیریت', 'web_app' => ['url' => $app_url]]
                    ],
                    default => [
                        ['text' => '👤 | پروفایل', 'web_app' => ['url' => $app_url]]
                    ]
                };

                $keyboard = [
                    // test row (may be empty)
                    $testBtn,
                    [
                        ['text' => '📦 | اکانت های من', 'callback_data' => 'accounts'],
                        ['text' => '🛍️ | خرید / تمدید اکانت ', 'callback_data' => 'action:buy_or_renew_service']
                    ],
                    [
                        ['text' => '📲 | دانلود نرم افزار', 'callback_data' => 'apps'],
                        ['text' => '💡 | آموزش ها', 'callback_data' => 'guide']
                    ],
                    [
                        ['text' => '💁🏻‍♂️ | پشتیبانی', 'callback_data' => 'support'],
                        ['text' => '❓ | سوالات متداول', 'callback_data' => 'faq'],
                    ],
                    [
                        ['text' => '👝 |  کیف پول', 'callback_data' => 'wallet']
                    ],
                    $panelBtn,
                    [
                        ['text' => '📣 | اخبار و اطلاعیه ها', 'url' => "t.me/$channelTelegram"]
                    ],
                ];
                break;

            case "accounts":
                $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
                $stmt = $conn->prepare("SELECT * FROM clients WHERE chat_id = ?");
                $stmt->bind_param("s", $uid);
                $stmt->execute();

                if ($conn->connect_error || $stmt->error) {
                    errorLog("Error in connecting to DB or preparing statement: " . ($conn->connect_error ?? $stmt->error), "functions.php", 2893);
                }

                $result = $stmt->get_result();
                $clients = $result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                $conn->close();

                $keyboard = [];

                if (empty($clients)) {
                    $keyboard[] = [['text' => '🤷🏻 | اکانتی به تلگرام شما متصل نیست', 'callback_data' => 'not']];
                    $keyboard[] = [['text' => '🛍 | خرید اکانت جدید', 'callback_data' => 'group']];
                } else {
                    foreach (array_reverse($clients) as $client) {
                        $clientData = getClientData($client['id']);
                        $plans = $clientData['plans'] ?? [];

                        // Find active and queued plans
                        $activePlan = null;
                        $queuedPlan = null;

                        foreach ($plans as $plan) {
                            if ($plan['is_active'] == 1) {
                                $activePlan = $plan;
                                break; // Found first active plan
                            }
                            if ($plan['is_in_queue'] && !$queuedPlan) {
                                $queuedPlan = $plan; // First queued plan
                            }
                        }

                        // If no active plan, use queued plan
                        $currentPlan = $activePlan ?? $queuedPlan;

                        if (!$currentPlan) {
                            $status = "🔴 غیرفعال";
                            $name = "بدون اشتراک";
                        } else {
                            $isActive = $currentPlan['is_active'] == 1;
                            $status = $isActive ? "🟢 فعال" : "🔵 در صف";

                            // Parse plan title
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
                    ['text' => '➕ | افزودن اکانت به لیست', 'callback_data' => 'add_account'],
                    ['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']
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
                // Check if user account
                $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
                $stmt = $conn->prepare("SELECT * FROM clients WHERE chat_id = ?");
                $stmt->bind_param("s", $uid);
                $stmt->execute();
                $result = $stmt->get_result();
                $clients = $result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                $conn->close();
                $keyboard = (empty($clients)) ? [
                    [
                        ['text' => '🛍 | خرید اکانت جدید', 'callback_data' => 'group']
                    ],
                    [
                        ['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']
                    ]
                ] : [
                    [
                        ['text' => '🔄️ | تمدید اکانت فعلی', 'callback_data' => 'renew']
                    ],
                    [
                        ['text' => '🛍 | خرید اکانت جدید', 'callback_data' => 'group']
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
                        "Iran Access" => "🏠 | $name",
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
                $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
                $stmt = $conn->prepare("SELECT * FROM clients WHERE chat_id = ?");
                $stmt->bind_param("s", $uid);
                $stmt->execute();

                if ($conn->connect_error || $stmt->error) {
                    errorLog("Error in connecting to DB or preparing statement: " . ($conn->connect_error ?? $stmt->error), "functions.php", 3023);
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

                        // Find active and queued plans
                        $activePlan = null;
                        $queuedPlan = null;

                        foreach ($plans as $plan) {
                            if ($plan['is_active'] == 1) {
                                $activePlan = $plan;
                                break; // Found first active plan
                            }
                            if ($plan['is_in_queue'] && !$queuedPlan) {
                                $queuedPlan = $plan; // Found first queued plan
                            }
                        }

                        // If no active plan, use queued plan
                        $currentPlan = $activePlan ?? $queuedPlan;

                        if (!$currentPlan) {
                            $status = "🔴 غیرفعال";
                            $name = "بدون اشتراک";
                        } else {
                            $isActive = $currentPlan['is_active'] == 1;
                            $status = $isActive ? "🟢 فعال" : "🔵 در صف";

                            // Parse plan title
                            $parsed = parsePlanTitle($currentPlan['name'], true);
                            $name = $parsed['text'];
                        }

                        $keyboard[] = [
                            ['text' => $name, 'callback_data' => 'renew_acc:' . $client['username']],
                            ['text' => $status . ' | ' . $client['username'], 'callback_data' => 'renew_acc:' . $client['username']]
                        ];
                    }
                }
                $keyboard[] = [
                    ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
                    ['text' => '↪️ | بازگشت', 'callback_data' => 'buy']
                ];
                break;
            case "add_account":
                $keyboard = [
                    [
                        ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
                        ['text' => '↪️ | بازگشت', 'callback_data' => 'accounts']
                    ]
                ];
                break;

            case "apps":
                $keyboard = [
                    [
                        ['text' => '📱 | آیفون (iOS)', 'callback_data' => 'app_ios'],
                        ['text' => '🤖 | اندروید', 'callback_data' => 'app_android']
                    ],
                    [
                        ['text' => '🖥 | مک', 'callback_data' => 'app_mac'],
                        ['text' => '💻 | ویندوز', 'callback_data' => 'app_windows']
                    ],
                    [
                        ['text' => '🐧 | لینوکس (Debian)', 'callback_data' => 'app_linux']
                    ],
                    [
                        ['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']
                    ]
                ];
                break;

            case "guide":
                $keyboard = [
                    [
                        ['text' => '📲 | آموزش استفاده از نرم افزار', 'callback_data' => 'guide_use']
                    ],
                    [
                        ['text' => '⚙ | آموزش نصب نرم افزار', 'callback_data' => 'guide_install'],
                    ],
                    [
                        ['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']
                    ]
                ];
                break;

            case "faq":
                $keyboard = [
                    [
                        ['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']
                    ]
                ];
                break;

            case "support":
                $keyboard = [
                    [
                        ['text' => '📩 |  پیام به پشتیبانی', 'url' => "t.me/$supportTelegram"]
                    ],
                    [
                        ['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']
                    ]
                ];
                break;

            case "wallet":
                $keyboard = [
                    [
                        ['text' => '💰 | افزایش موجودی', 'callback_data' => 'wallet_increase:0']
                    ],
                    [
                        ['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']
                    ]
                ];
                break;

            case "wallet_increase":
                $keyboard = [
                    [
                        ['text' => '❌ | انصراف', 'callback_data' => 'wallet']
                    ]
                ];
                break;
                
            default:
                return json_encode(['ok' => true]);
        }
        return json_encode(['inline_keyboard' => $keyboard]);
    } catch (Exception $e) {
        errorLog("Error in keyboard function: " . $e->getMessage(), "functions.php", 3165);
    }
}

function message($message, $variables = []) {
    //get name ffrom bot_config.json
    $data = file_get_contents('setup/bot_config.json');
    $config = json_decode($data, true);
    $appName = $config['app_name'] ?? '';
    $welcomeMessage = $config['messages']['welcome_text'] ?? '';
    $supportMessage = $config['messages']['contact_support'] ?? '';
    $faq = $config['messages']['questions_and_answers'] ?? '';

    if ($variables['groupName']) {
        $typeEmoji = match ($variables['groupName']) {
            "ویژه" => "📱",
            "ساب‌لینک" => "🔗",
            "آی‌پی ثابت" => "📍",
            "ایران اکسس" => "🏠",
            default => "📱"
        };
        $groupName = $variables['groupName'];
    }

    $groupMessage = "لطفاً ابتدا نوع سرویس مدنظر را انتخاب کنید: 👇";
    $groups = getSellerPlans('group');

    foreach ($groups as $group) {
        switch ($group['name']) {
            case "default":
                $groupMessage .= "\n\n<b>📱 ویژه (پیشنهاد میشود):</b>\nدریافت نام کاربری و رمز عبور جهت ورود به نرم افزار Connectix و استفاده از 4 پروتکل و بیش از 10 کشور برای اتصال.";
                break;
            case "Iran Access":
                $groupMessage .= "\n\n<b>🏠 ایران اکسس</b>\nسرویس دسترسی به آیپی ایران برای هموطنان ایرانی مقیم خارج کشور";
                break;
            case "Sublink":
                $groupMessage .= "\n\n<b>🔗 سابسکریبشن:</b>\nدریافت لینک سابسکریپشن جهت استفاده در نرم افزار هایی که از سرویس V2Ray پشتیبانی میکنند (مثل V2RayNG و V2Box)";
                break;
            case "Static IP":
                $groupMessage .= "\n\n<b>📍 آی‌پی ثابت:</b>\nدریافت نام کاربری و رمز عبور جهت ورود به نرم افزار Connectix و استفاده از آیپی ثابت.";
                break;
            default:
                $typeEmoji = "📱";
                break;
        }
    }

        

    $msg = match ($message) {
        "welcome_message" => $welcomeMessage,
        "accounts" => "📦 اکانت های متصل یه حساب تلگرام شما:\n\n* در صورت عدم مشاهده اکانت خود، آن را اضافه کنید.",
        "get_test" => "🎁 لطفا نوع اکانت تست را انتخاب کنید:\n\n<b>📱 ویژه(پیشنهاد میشود):</b>\nدریافت نام کاربری و رمز عبور جهت ورود به نرم افزار Connectix و استفاده از 4 پروتکل و بیش از 10 کشور برای اتصال.\n\n<b>🔗 سابسکریبشن:</b>\nدریافت لینک سابسکریپشن جهت استفاده در نرم افزار هایی که از سرویس V2Ray پشتیبانی میکنند (مثل V2RayNG و V2Box)",
        "count" => "$typeEmoji نوع سرویس $groupName انتخاب شد.\n\n🔢 این اکانت را برای چند کاربر (دستگاه) قابل استفاده باشد؟",
        "buy" => "با تشکر از اعتماد و حسن انتخاب شما در خرید سرویس فیلترشکن {$appName} .\nلطفا نوع خرید خود را انتخاب کنید:\n\n<b>🔄️ تمدید اکانت فعلی:</b>\nاین دکمه برای خرید اشتراک برای اکانت قبلی استفاده میشود.\n\n<b>🛍️ خرید اکانت جدید:</b>\nاین دکمه برای خرید اکانت جدید استفاده میشود.",
        "group" => $groupMessage,
        "renew" => "📦 لطفا اکانت مدنظر خود را جهت تمدید اشتراک انتخاب کنید:",
        "card" => "💸  لطفاً مبلغ لازمه را به شماره کارت زیر واریز و سپس سند پرداخت را به صورت تصویری در ادامه ارسال کنید:\n\n💴 مبلغ: " . $variables['amount'] . "\n💳 شماره کارت: " . $config['card_number'] . "\n👤 به نام: " . $config['card_name'] . "\n",
        "add_account" => "🔗 شما در حال متصل کردن اکانت قبلی به حساب تلگرام خود هستید.\n\n👤 لطفا نام کاربری اکانت را وارد نمایید:",
        "apps" => "⚙ لطفا سیستم عامل مدنظر خود را انتخاب کنید:",
        "guide" => "📖 لطفا نحوه آموزش را انتحاب کنید.",
        "faq" => $faq,
        "support" => $supportMessage,
        "wallet" => "🤑 موجودی کیف پول شما: \n💵 " . $variables['walletBalance'] . " تومان\n\n👤 نام: " . $variables['userName'] . "\n🔢 آیدی عددی: " . UID,
        "wallet_increase" => "💰 لطفا مبلغ مدنظر جهت افزایش موجودی کیف پول خود به (تومان) را وارد نمایید.\n حداقل مبلغ واریزی 10,000 تومان میباشد.",
        default => "پیام پیشفرض",
    };
    return $msg;
}