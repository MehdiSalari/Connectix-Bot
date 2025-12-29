<?php
set_time_limit(0);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../gregorian_jalali.php';

//get data
$db_host = $_POST['db_host'] ?? '';
$db_name = $_POST['db_name'] ?? '';
$db_user = $_POST['db_user'] ?? '';
$db_pass = $_POST['db_pass'] ?? '';
$panelEmail = $_POST['panelEmail'] ?? '';
$panelPassword = $_POST['panelPassword'] ?? '';
$botToken = $_POST['botToken'] ?? '';
$admin_email = $_POST['email'] ?? '';
$admin_password = $_POST['adminPassword'] ?? '';
$admin_chat_id = $_POST['chatId'] ?? '';

logFlush("Starting Connectix Bot Setup...");

if (empty($admin_password) || empty($admin_email)) {
    logFlush("Admin email and password are required");
    logFlush($admin_email);
    logFlush($admin_password);
    exit(1);
}

function checkRequirements(){
    // Check PHP version
    if (version_compare(PHP_VERSION, '8.0.0', '<')) {
        logFlush("PHP version 8.0.0 or higher is required.");
        exit(1);
    }

    // Check required extensions
    if (!extension_loaded('curl')) {
        logFlush("CURL extension is required. Please install it.");
        exit(1);
    }
    if (!extension_loaded('json')) {
        logFlush("JSON extension is required. Please install it.");
        exit(1);
    }

    // Check Redis
    if (!extension_loaded('redis')) {
        logFlush("Redis extension is required. Please install it.");
        exit(1);
    }

    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);
    // set tset key
    $redis->set('test', 'test');

    if ($redis->get('test') !== 'test') {
        logFlush("Redis connection failed.");
        exit(1);
    }
    $redis->del('test');
    $redis->close();
}

function getPanelToken($panelEmail, $panelPassword) {
    $endpiont = 'https://api.connectix.vip/v1/seller/auth/login';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $endpiont,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'email' => $panelEmail,
            'password' => $panelPassword,
            'rememberMe' => false,
            'device_browser' => 'Chrome',
            'device_os' => 'Windows'
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ]
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $json = json_decode($response);
    return $json->token;
}

function setConfig($db_host, $db_name, $db_user, $db_pass, $panelToken, $botToken) {
    try {
        // Database Test before writing config.php
        try {
            $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            logFlush("Database Connection Success");
        } catch (PDOException $e) {
            throw new Exception("Database Connection Failed: " . $e->getMessage());
        }

        $config = "<?php
\$db_host = '$db_host';
\$db_name = '$db_name';
\$db_user = '$db_user';
\$db_pass = '$db_pass';
\$panelToken = '$panelToken';
\$botToken = '$botToken';
?>";
        file_put_contents("../config.php", $config);
        logFlush("config.php Created");
    } catch (Exception $e) {
        logFlush("config.php Error: " . $e->getMessage());
        die($e->getMessage());
    }
}

function setAdmin($email, $password, $panelToken, $admin_chat_id, $db_host, $db_name, $db_user, $db_pass) {
    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec("CREATE TABLE IF NOT EXISTS admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            token VARCHAR(255) NOT NULL,
            chat_id VARCHAR(255) NOT NULL,
            role ENUM('admin','editor') DEFAULT 'admin'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO admins (email, password, token, chat_id, role) 
                            VALUES (?, ?, ?, ?, 'admin') 
                            ON DUPLICATE KEY UPDATE password=VALUES(password), token=VALUES(token), chat_id=VALUES(chat_id)");
        $stmt->execute([$email, $hash, $panelToken, $admin_chat_id]);
        logFlush("Admin account created/updated");
    } catch (Exception $e) {
        logFlush("Set Admin Error: " . $e->getMessage());
        exit(1);
    }
}

function fetchBotConfig($panelToken) {
    try {
        try {
            $endpoint = "https://api.connectix.vip/v1/seller/telegram-bot";
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false, // Connectix sometimes has SSL issues
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Bearer {$panelToken}",
                    "Accept: application/json",
                    "Content-Type: application/json",
                    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36",
                    "Origin: https://connectix.vip",
                    "Referer: https://connectix.vip/"
                ],
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code !== 200) {
                throw new Exception("Failed to fetch messages - HTTP $http_code");
            }

            $data = json_decode($response, true);

            if ($data && isset($data['bot']['app_name']) && !empty($data['bot']['app_name'])) {
                $appName = $data['bot']['app_name'] ?? null;
                $adminId = $data['bot']['admin_id'] ?? null;
                $telegramSupport = $data['bot']['support_telegram'] ?? null;
                $telegramChannel = $data['bot']['channel_telegram'] ?? null;
                $cardNumber = $data['bot']['card_number'] ?? null;
                $cardName = $data['bot']['card_name'] ?? null;

                $welcomeMessage = $data['telegramMessages']['welcome_text'] ?? null;
                $supportMessage = $data['telegramMessages']['contact_support'] ?? null;
                $faqMessage = $data['telegramMessages']['questions_and_answers'] ?? null;
                $freeTrialMessage = $data['telegramMessages']['free_test_account_created'] ?? null;
                logFlush("Bot messages retrieved from panel API - App Name: {$appName}");
            } else {
                logFlush("Panel API response was invalid, couldn't find messages: " . json_encode($data));
            }

            //check if telegramSupport and telegramChannel start with @, remove it
            if (strpos($telegramSupport, '@') === 0) {
                $telegramSupport = substr($telegramSupport, 1);
            }
            if (strpos($telegramChannel, '@') === 0) {
                $telegramChannel = substr($telegramChannel, 1);
            }

            //save bot configs to json file
            $botConfig = [
                'app_name' => $appName,
                'admin_id' => $adminId,
                'support_telegram' => $telegramSupport,
                'channel_telegram' => $telegramChannel,
                'card_number' => $cardNumber,
                'card_name' => $cardName,
                'messages' => [
                    'welcome_text' => $welcomeMessage,
                    'contact_support' => $supportMessage,
                    'questions_and_answers' => $faqMessage,
                    'free_test_account_created' => $freeTrialMessage
                ],
                'bank' => [
                    'name' => null,
                    'bot_notice' => false
                ]
            ];

            $botConfigJson = json_encode($botConfig, JSON_PRETTY_PRINT);
            file_put_contents(__DIR__ . '/bot_config.json', $botConfigJson);

            //create banks json flie for auto accept payments
            $bankJson = [
                    'blu' => ['name' => 'بلو','code' => 'blu'],
                    'mellat' => ['name' => 'ملت','code' => 'mellat'],
                    'sepah' => ['name' => 'سپه','code' => 'sepah'],
                    'saderat' => ['name' => 'صادرات','code' => 'saderat'],
                    'parsian' => ['name' => 'پارسیان','code' => 'parsian'],
                    'melli' => ['name' => 'ملی','code' => 'melli'],
                    'tose' => ['name' => 'توسعه','code' => 'tose'],
                    'tejarat' => ['name' => 'تجارت','code' => 'tejarat'],
                    'refah' => ['name' => 'رفاه','code' => 'refah'],
                    'maskan' => ['name' => 'مسکن','code' => 'maskan'],
                    'keshavarzi' => ['name' => 'کشاورزی','code' => 'keshavarzi'],
                    'sanat' => ['name' => 'صنعت و معدن','code' => 'sanat'],
                    'saman' => ['name' => 'سامان','code' => 'saman'],
                    'shahr' => ['name' => 'شهر','code' => 'shahr'],
                    'mehr' => ['name' => 'مهر','code' => 'mehr'],
                    'day' => ['name' => 'دی','code' => 'day'],
                    'post' => ['name' => 'پست','code' => 'post'],
                    'sarmaye' => ['name' => 'سرمایه','code' => 'sarmaye'],
                    'resalat' => ['name' => 'رسالت','code' => 'resalat'],
                    'gardeshgari' => ['name' => 'گردشگری','code' => 'gardeshgari'],
                    
                ];

            $bankJson = json_encode($bankJson, JSON_PRETTY_PRINT);
            file_put_contents(__DIR__ . '/banks.json', $bankJson);


        } catch (Exception $e) {
            logFlush("Error: couldn't get messages from panel API → " . $e->getMessage());
            return;
        }
        logFlush("Messages fetched and saved.");
    } catch (Exception $e) {
        logFlush("Fetch Messages Error: " . $e->getMessage());
    }
}

function setBotWebhook($token) {
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')) {
        $protocol = "https";
    } else {
        $protocol = "http";
    }

    $current_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $full_url = str_replace("setup/setup.php", "bot.php", $current_url);

    // If it's HTTP, throw an error and stop (Telegram only accepts HTTPS)
    if ($protocol !== 'https') {
        logFlush("ERROR: Webhook URL is HTTP! Telegram only accepts HTTPS.");
        logFlush("ERROR: Current URL detected: " . $full_url);
        logFlush("ERROR: Please use a valid SSL certificate (Let's Encrypt, Cloudflare, etc.)");
        throw new Exception("Webhook requires HTTPS. Current URL is HTTP.");
    }

    $url = "https://api.telegram.org/bot{$token}/setWebhook?url={$full_url}";
    logFlush("Setting Webhook → {$full_url}");

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $result = json_decode($response, true);
    curl_close($ch);

    if ($httpCode !== 200 || empty($result['ok'])) {
        $error = $result['description'] ?? 'Unknown error';
        logFlush("Webhook Error (HTTP {$httpCode}): " . $error);
        throw new Exception("Failed to set webhook: " . $error);
    }

    logFlush("Webhook set successfully!");
}

function logFlush($msg) {
    echo $msg . "\n";
    ob_flush();
    flush();
}

function http_get_json(string $token, string $url, array $headers = [])
{
    $defaultHeaders = [
        "Accept: application/json",
        "Authorization: Bearer {$token}"
    ];

    $headers = array_merge($defaultHeaders, $headers);

    // Retry logic for SSL errors
    $maxRetries = 3;
    $retryDelay = 2; // seconds
    $attempt = 0;

    while ($attempt < $maxRetries) {
        $attempt++;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        // Improved SSL handling
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT:!aNULL:!eNULL:!MD5:!3DES:!DES:!RC4:!IDEA:!SEED:!aDSS:!SRP:!PSK');
        
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        
        $res = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // If we got a valid JSON response
        if ($res !== false && $httpCode >= 200 && $httpCode < 300) {
            $json = json_decode($res, true);
            if ($json !== null) {
                return $json;
            } else {
                throw new Exception("Invalid JSON from {$url}: " . substr($res, 0, 500));
            }
        }

        // If there was an SSL error and we have more retries left
        if ($curlError && strpos($curlError, 'SSL') !== false && $attempt < $maxRetries) {
            logFlush("   ⚠️ SSL error (attempt {$attempt}/{$maxRetries}), retrying in {$retryDelay}s...");
            sleep($retryDelay);
            $retryDelay += 1; // افزایش تدریجی delay
            continue;
        }

        // If there was a cURL error
        if ($res === false) {
            throw new Exception("cURL error for {$url}: {$curlError}");
        }

        // If there was an HTTP error
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("HTTP {$httpCode} when requesting {$url}: response: " . substr($res, 0, 500));
        }
    }

    // if all attempts failed
    throw new Exception("Failed to fetch {$url} after {$maxRetries} attempts");
}

function dbSetup()
{
    global $panelToken, $db_host, $db_name, $db_user, $db_pass;

    $endpoint = 'https://api.connectix.vip/v1/seller';

    $token = $panelToken;
    $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        die("DB connection failed: " . $e->getMessage());
    }

    // ---- create tables if not exists (simple schema) ----
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chat_id VARCHAR(255) UNIQUE,
    telegram_id VARCHAR(255),
    name VARCHAR(255),
    email VARCHAR(255),
    phone VARCHAR(50),
    test TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS clients (
    id VARCHAR(100) PRIMARY KEY,
    count_of_devices INT,
    username VARCHAR(255),
    password VARCHAR(255),
    chat_id VARCHAR(255),
    user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(20) UNIQUE,
    chat_id VARCHAR(255),
    client_id VARCHAR(255),
    plan_id VARCHAR(255),
    price VARCHAR(255),
    coupon VARCHAR(255),
    is_paid TINYINT(1) NULL,
    method VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS wallets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chat_id VARCHAR(255) UNIQUE,
    balance VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS wallet_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wallet_id INT,
    amount VARCHAR(255),
    operation VARCHAR(255),
    chat_id VARCHAR(255),
    status VARCHAR(255),
    type VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");


    // prepared statements for speed
    $selectUserByChat = $pdo->prepare("SELECT id FROM users WHERE chat_id = ? LIMIT 1");
    $insertUser = $pdo->prepare("INSERT INTO users (chat_id, telegram_id, name, email, phone, test) VALUES (?, ?, ?, ?, ?, ?)");
    $updateUser = $pdo->prepare("UPDATE users SET telegram_id = ?, name = ?, email = ?, phone = ?, test = ? WHERE chat_id = ?");

    $selectClient = $pdo->prepare("SELECT id FROM clients WHERE id = ? LIMIT 1");
    $insertClient = $pdo->prepare("INSERT INTO clients (id, count_of_devices, username, password, chat_id, user_id) VALUES (?, ?, ?, ?, ?, ?)");
    $updateClient = $pdo->prepare("UPDATE clients SET count_of_devices = ?, username = ?, password = ?, chat_id = ?, user_id = ? WHERE id = ?");


    // counters
    $processedClients = $insertedUsers = $insertedClients = $insertedPlans = 0;
    $skippedClientsNoDetail = 0;
    $totalClientsCount = 0;

    // NOTE: Do NOT reset progress file here! It already has 5% from setup steps.
    // We'll read the existing progress to keep 5% as starting point for client import.

    try {

        // pagination loop
        $pageUrl = rtrim($endpoint, '/') . '/clients?page=1';
        $pageNum = 0;
        while ($pageUrl !== null) {
            $pageNum++;
            $pageUrlParts = explode('=', $pageUrl);
            $pageNumber = $pageUrlParts[1];
            logFlush("Fetching page: {$pageNumber}");
            $resp = http_get_json($token, $pageUrl);
            if (!isset($resp['clients']) || !isset($resp['clients']['data'])) {
                throw new Exception("Unexpected response structure from {$pageUrl}");
            }
            $clientsArray = $resp['clients']['data'];

            // On first page attempt to capture total clients count (API fields may vary)
            if ($pageNum === 1) {
                if (!empty($resp['clients']['total'])) {
                    $totalClientsCount = (int)$resp['clients']['total'];
                } elseif (!empty($resp['clients']['total_clients'])) {
                    $totalClientsCount = (int)$resp['clients']['total_clients'];
                } elseif (!empty($resp['clients']['meta']['total'])) {
                    $totalClientsCount = (int)$resp['clients']['meta']['total'];
                }
                
                // If still 0, use count of current page as fallback
                if ($totalClientsCount === 0) {
                    $totalClientsCount = count($clientsArray);
                }
                
                logFlush("Total clients to import: {$totalClientsCount}");
                logFlush("Current page has: " . count($clientsArray) . " clients");
                
                // Write initial progress with total_clients set (starts at 5% since initial setup done)
                file_put_contents(__DIR__ . '/setup_progress.json', json_encode([
                    'page' => $pageNum,
                    'processedClients' => 0,
                    'insertedUsers' => $insertedUsers,
                    'insertedClients' => $insertedClients,
                    'insertedPlans' => $insertedPlans,
                    'skipped' => $skippedClientsNoDetail,
                    'total_clients' => $totalClientsCount,
                    'percent' => 5
                ]));
            }

            foreach ($clientsArray as $c) {
                $clientId = $c['id'] ?? null;
                if (!$clientId)
                    continue;
                // echo " -> processing client id: {$clientId}<br>";
                logFlush(" -> processing client id: {$clientId}");
                // get detail
                $detailUrl = rtrim($endpoint, '/') . '/clients/show?id=' . urlencode($clientId);
                try {
                    $detailResp = http_get_json($token, $detailUrl);
                } catch (Exception $ex) {
                    // echo "   ! failed to fetch detail for {$clientId}: " . $ex->getMessage() . "<br>";
                    logFlush("   ! failed to fetch detail for {$clientId}: " . $ex->getMessage());
                    $skippedClientsNoDetail++;
                    continue;
                }
                if (!isset($detailResp['client'])) {
                    // echo "   ! no client field for {$clientId}, skipping<br>";
                    logFlush("   ! no client field for {$clientId}, skipping");
                    $skippedClientsNoDetail++;
                    continue;
                }
                $clientDetail = $detailResp['client'];

                $pdo->beginTransaction();
                try {
                    $userId = null;
                    // create user row only if chat_id exists and non-empty
                    $chat_id = $clientDetail['chat_id'] ?? null;
                    if ($chat_id !== null && $chat_id !== '' && strtolower($chat_id) !== 'null') {
                        // check existing
                        $selectUserByChat->execute([$chat_id]);
                        $row = $selectUserByChat->fetch();
                        if ($row) {
                            $userId = $row['id'];
                            // update user info
                            $updateUser->execute([
                                $clientDetail['telegram_id'] ?? null,
                                $clientDetail['name'] ?? null,
                                $clientDetail['email'] ?? null,
                                $clientDetail['phone'] ?? null,
                                1, // test field default True
                                $chat_id
                            ]);
                            // we don't increment insertedUsers since it's existing
                        } else {
                            $insertUser->execute([
                                $chat_id,
                                $clientDetail['telegram_id'] ?? null,
                                $clientDetail['name'] ?? null,
                                $clientDetail['email'] ?? null,
                                $clientDetail['phone'] ?? null,
                                1 // test field default True
                            ]);
                            $userId = $pdo->lastInsertId();
                            $insertedUsers++;
                            // echo "   + inserted user (chat_id={$chat_id}) as id {$userId}<br>";
                            logFlush("   + inserted user (chat_id={$chat_id}) as id {$userId}");
                        }
                    }

                    // insert/update client
                    $selectClient->execute([$clientDetail['id']]);
                    $exists = $selectClient->fetch();
                    $count_of_devices = isset($clientDetail['count_of_devices']) ? (int) $clientDetail['count_of_devices'] : null;
                    $username = $clientDetail['username'] ?? null;
                    $password = $clientDetail['password'] ?? null;
                    $chatIdForClient = $clientDetail['chat_id'] ?? null;

                    if ($exists) {
                        $updateClient->execute([$count_of_devices, $username, $password, $chatIdForClient, $userId, $clientDetail['id']]);
                        // not counting updates as insertedClients
                    } else {
                        $insertClient->execute([$clientDetail['id'], $count_of_devices, $username, $password, $chatIdForClient, $userId]);
                        $insertedClients++;
                        // echo "   + inserted client {$clientDetail['id']}<br>";
                        logFlush("   + inserted client {$clientDetail['id']}");
                    }

                    $pdo->commit();
                    $processedClients++;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    // echo "   ! DB error for client {$clientId}: " . $e->getMessage() . "<br>";
                    logFlush("   ! DB error for client {$clientId}: " . $e->getMessage());
                }
            } // end foreach clientsArray

            // Write progress after each page
            // Percent calculation: 5% (initial setup) + 95% (client import progress)
            $currentPercent = $totalClientsCount > 0 ? (int)(5 + ($processedClients / $totalClientsCount * 95)) : 5;
            file_put_contents(__DIR__ . '/setup_progress.json', json_encode([
                'page' => $pageNum,
                'processedClients' => $processedClients,
                'insertedUsers' => $insertedUsers,
                'insertedClients' => $insertedClients,
                'insertedPlans' => $insertedPlans,
                'skipped' => $skippedClientsNoDetail,
                'total_clients' => $totalClientsCount,
                'percent' => $currentPercent
            ]));
            logFlush("Progress: {$processedClients}/{$totalClientsCount} clients ({$currentPercent}%)");

            // prepare next page
            $nextPage = $resp['clients']['next_page_url'] ?? null;
            if ($nextPage) {
                // Fix link to use HTTPS and absolute path for next page
                if (strpos($nextPage, '//') === false) {
                    // relative path -> add base path to the beginning
                    $nextPage = rtrim($endpoint, '/') . '/' . ltrim($nextPage, '/');
                } elseif (strpos($nextPage, 'http://') === 0) {
                    // If the link starts with http, replace it with https
                    $nextPage = preg_replace('#^http://#', 'https://', $nextPage);
                }

                $pageUrl = $nextPage;
            } else {
                $pageUrl = null;
            }

            // echo "<hr><br>";
            logFlush("<hr><br>");

        } // end while pagination

        
        // ====================== Starting wallet and transaction import ======================
        logFlush("<br><strong>Starting wallet and transaction import...</strong><br>");

        // prepared statements for wallets and wallet_transactions
        $insertWallet = $pdo->prepare("
            INSERT INTO wallets (chat_id, balance) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE balance = VALUES(balance)
        ");

        $insertTransaction = $pdo->prepare("
            INSERT IGNORE INTO wallet_transactions 
            (wallet_id, amount, operation, chat_id, status, type, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        // New wallets counter
        $insertedWallets = $updatedWallets = $insertedTransactions = 0;

        try {
            $walletsEndpoint = 'https://api.connectix.vip/v1/seller/telegram-wallets';
            logFlush("Retrieving list of wallets");

            $walletsResp = http_get_json($token, $walletsEndpoint);

            if (!isset($walletsResp['data']) || !is_array($walletsResp['data'])) {
                throw new Exception("The structure of the API response for wallets is invalid.");
            }

            $allWallets = $walletsResp['data'];
            $totalWallets = count($allWallets);

            logFlush("Retrieved wallets count: {$totalWallets}");

            foreach ($allWallets as $index => $wallet) {
                $walletId = $wallet['id'] ?? null;
                $chat_id = $wallet['chat_id'] ?? null;
                $balance = $wallet['balance'] ?? '0';

                if (!$walletId || !$chat_id) continue;

                $cleanBalance = str_replace(',', '', $balance);

                $pdo->beginTransaction();
                try {
                    $insertWallet->execute([$chat_id, $cleanBalance]);

                    // دریافت id کیف پول از دیتابیس
                    if ($pdo->lastInsertId() > 0) {
                        $walletDbId = $pdo->lastInsertId();
                        $insertedWallets++;
                    } else {
                        // اگر آپدیت شده بود
                        $stmt = $pdo->prepare("SELECT id FROM wallets WHERE chat_id = ?");
                        $stmt->execute([$chat_id]);
                        $row = $stmt->fetch();
                        $walletDbId = $row['id'] ?? null;
                        $updatedWallets++;
                    }

                    // دریافت تراکنش‌ها
                    $detailUrl = "https://api.connectix.vip/v1/seller/telegram-wallets/{$walletId}?status=All";
                    $detailResp = http_get_json($token, $detailUrl);

                    if (isset($detailResp['wallet']['transactions']) && is_array($detailResp['wallet']['transactions'])) {
                        foreach ($detailResp['wallet']['transactions'] as $tx) {
                            $txAmount = str_replace(',', '', $tx['amount'] ?? '0');
                            $txOperation = $tx['type'] ?? 'UNKNOWN';
                            $txCreatedRaw = $tx['created_at'] ?? null;
                            $txType = $tx['transaction_id'] ?? 'null';
                            $txStatus = $tx['status'] ?? 'null';
                            if ($txType == 'null' && $txOperation == 'DECREASE') {
                                $txType = 'BUY';
                            }

                            $txCreated = null;
                            if ($txCreatedRaw && strpos($txCreatedRaw, ' ') !== false) {
                                list($datePart, $timePart) = explode(' ', $txCreatedRaw, 2);
                                list($year, $month, $day) = explode('-', $datePart);
                                list($hour, $minute) = explode(':', $timePart . ':00:00'); // پیش‌فرض ثانیه

                                $gregorian = jalali_to_gregorian($year, $month, $day, true); // فرض بر اینه که تابع درست کار می‌کنه
                                $txCreated = sprintf("%s %02d:%02d:00", $gregorian, $hour, $minute);
                            }

                            if ($walletDbId) {
                                $insertTransaction->execute([$walletDbId, $txAmount, $txOperation, $chat_id, $txStatus, $txType, $txCreated]);
                                $insertedTransactions++;
                            }
                        }
                    }

                    $pdo->commit();

                    if (($index + 1) % 10 === 0 || ($index + 1) === $totalWallets) {
                        logFlush("   Processed wallets: " . ($index + 1) . "/{$totalWallets}");
                    }

                } catch (Exception $e) {
                    $pdo->rollBack();
                    logFlush("   ! Error processing wallet {$walletId}: " . $e->getMessage());
                }
            }

            logFlush("Wallets import completed successfully.");
            logFlush("New wallets inserted: {$insertedWallets}");
            logFlush("Wallets updated: {$updatedWallets}");
            logFlush("Transactions inserted: {$insertedTransactions}");

        } catch (Exception $e) {
            logFlush("General error in wallets import: " . $e->getMessage());
        }
        // ====================== End of wallets import ======================


        logFlush("Done. summary:");
        logFlush("Processed clients (detailed fetched): {$processedClients}<br>");
        logFlush("Inserted new users: {$insertedUsers}<br>");
        logFlush("Inserted new clients: {$insertedClients}<br>");
        logFlush("Inserted new plans: {$insertedPlans}<br>");
        logFlush("Skipped clients (no detail / errors): {$skippedClientsNoDetail}<br>");

    } catch (Exception $e) {
        // echo "Fatal error: " . $e->getMessage() . "<br>";
        logFlush("Fatal error: " . $e->getMessage() . "<br>");
        exit(1);
    }
}


try {
    // Step 0: Check requirements
    logFlush("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
    logFlush("Checking requirements...");
    checkRequirements();
    logFlush("✓ Requirements met");
    // Step 1: Get Panel Token
    logFlush("Step 1/6: Getting panel token...");
    $panelToken = getPanelToken(
        $panelEmail,
        $panelPassword
    );
    if (empty($panelToken)) {
        logFlush("✗ Panel token not obtained, check your credentials and try again");
        exit(1);
    }
    logFlush("✓ Panel token obtained");

    // Step 2: Create config.php first
    logFlush("\nStep 2/6: Creating config.php...");
    setConfig(
        $db_host,
        $db_name,
        $db_user,
        $db_pass,
        $panelToken,
        $botToken
    );
    logFlush("✓ Config file created");
    
    // Now require config.php since it was just created
    require_once '../config.php';

    // Step 3: Set up admin user
    logFlush("\nStep 3/6: Setting up admin user...");
    setAdmin(
        $admin_email,
        $admin_password,
        $panelToken,
        $admin_chat_id,
        $db_host,
        $db_name,
        $db_user,
        $db_pass
    );
    logFlush("✓ Admin user configured");

    // Step 4: Set bot webhook
    logFlush("\nStep 4/6: Setting bot webhook...");
    setBotWebhook($botToken);
    logFlush("✓ Webhook configured");

    // Step 5: Fetch bot config
    logFlush("\nStep 5/6: Fetching bot configuration...");
    fetchBotConfig(
        $panelToken,
    );
    logFlush("✓ Bot configuration fetched");

    // Update progress to 5% before starting dbSetup
    file_put_contents(__DIR__ . '/setup_progress.json', json_encode([
        'page' => 0,
        'processedClients' => 0,
        'insertedUsers' => 0,
        'insertedClients' => 0,
        'insertedPlans' => 0,
        'skipped' => 0,
        'total_clients' => 0,
        'percent' => 5,
        'stage' => 'Initial setup completed. Starting client import...'
    ]));

    // Step 6: Sync database with panel
    logFlush("\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
    logFlush("Step 6/6: Importing clients from panel (this may take a while)...");
    logFlush("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
    dbSetup();
    
    logFlush("\n✅ Setup completed successfully!");
} catch (Exception $e) {
    logFlush("FATAL ERROR: " . $e->getMessage());
    logFlush("ERROR: Setup failed permanently");
} finally {
    // Always execute this last line to finalize the setup process
    logFlush("SETUP_FINISHED");
}