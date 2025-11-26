<?php
set_time_limit(0);
header('Content-Type: text/plain; charset=utf-8');

//get data
$db_host = $_POST['db_host'] ?? '';
$db_name = $_POST['db_name'] ?? '';
$db_user = $_POST['db_user'] ?? '';
$db_pass = $_POST['db_pass'] ?? '';
$panelToken = $_POST['panelToken'] ?? '';
$botToken = $_POST['botToken'] ?? '';
$admin_email = $_POST['email'] ?? '';
$admin_password = $_POST['adminPassword'] ?? ''; // Keep as plain password
$admin_chat_id = $_POST['chatId'] ?? '';

logFlush("Starting Connectix Bot Setup...");

if (empty($admin_password) || empty($admin_email)) {
    logFlush("Admin email and password are required");
    logFlush($admin_email);
    logFlush($admin_password);
    exit(1);
}

function config($db_host, $db_name, $db_user, $db_pass, $panelToken, $botToken) {
    try {
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
    }
}

function setAdmin($email, $password, $panelToken, $admin_chat_id, $db_host, $db_name, $db_user, $db_pass) {
    try {
        // Hash the password inside the function
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
        
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // چک کردن وجود جدول مدیران
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'admins'");
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('admins', $result)) {
            // اگر جدول مدیران وجود نداشت، آن را ایجاد می‌کنیم
            $pdo->exec("
                CREATE TABLE admins (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(190) UNIQUE NOT NULL,
                    password VARCHAR(255) NOT NULL,
                    token VARCHAR(255) NOT NULL,
                    chat_id VARCHAR(255) NOT NULL,
                    role ENUM('admin','editor') NOT NULL DEFAULT 'editor'
                )
            ");
            logFlush("admins table created");
        }

        // افزودن یا بروزرسانی مدیر اصلی
        $stmt = $pdo->prepare("INSERT INTO admins (email, password, token, chat_id, role) VALUES (:email, :password, :token, :chat_id, :role) ON DUPLICATE KEY UPDATE password = :password, token = :token, chat_id = :chat_id, role = :role");
        $stmt->execute([
            ':email' => $email,
            ':password' => $hashed_password,
            ':token' => $panelToken,
            ':chat_id' => $admin_chat_id,
            ':role' => 'admin'
        ]);
        logFlush("Main admin added/updated: {$email}");
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
                ]
            ];

            $botConfigJson = json_encode($botConfig, JSON_PRETTY_PRINT);
            file_put_contents(__DIR__ . '/bot_config.json', $botConfigJson);


        } catch (Exception $e) {
            logFlush("Error: couldn't get messages from panel API → " . $e->getMessage());
            return;
        }
        logFlush("Fetching messages from panel... (not implemented yet)");
    } catch (Exception $e) {
        logFlush("Fetch Messages Error: " . $e->getMessage());
    }
}

function setBotWebhook($token) {

    // $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    // $protocol = "https";

    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) {
        $protocol = "https";
    } else {
        $protocol = "http";
    }
    // 2. ترکیب پروتکل، هاست و URI برای ساخت URL کامل
    // $full_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    // مسیر فعلی کامل
    $current_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

    // حذف setup/setup.php و اضافه کردن index.php
    $full_url = str_replace("setup/setup.php", "bot.php", $current_url);

    $url = "https://api.telegram.org/bot{$token}/setWebhook?url={$full_url}";
    logFlush("Set Bot Webhook for URL: {$full_url}");
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $result = json_decode($response, true);
    if ($result['ok'] !== true) {
        logFlush("Webhook Error: " . $result['description']);
        throw new Exception("Webhook Error: " . $result['description']);
    }
    logFlush("Webhook set successfully.");

}

function logFlush($msg) {
    echo $msg . "\n";
    ob_flush();
    flush();
}

function http_get_json(string $token, string $url, array $headers = [])
{

    // global $token; // 👈 برای دسترسی به توکن بیرون تابع

    $defaultHeaders = [
        "Accept: application/json",
        "Authorization: Bearer {$token}" // 👈 این خط جدید اضافه شده
    ];

    // اگر هدرهای اضافی هم فرستاده بشن، باهم ترکیب می‌شن
    $headers = array_merge($defaultHeaders, $headers);



    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $res = curl_exec($ch);
    if ($res === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception("cURL error for {$url}: {$err}");
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception("HTTP {$httpCode} when requesting {$url}: response: " . substr($res, 0, 500));
    }
    $json = json_decode($res, true);
    if ($json === null) {
        throw new Exception("Invalid JSON from {$url}: " . substr($res, 0, 500));
    }
    return $json;
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
    CREATE TABLE IF NOT EXISTS clients_plans (
    plan_id VARCHAR(100) PRIMARY KEY,
    client_id VARCHAR(100),
    name VARCHAR(255),
    price VARCHAR(100),
    created_at_raw VARCHAR(100),
    expire_date_raw VARCHAR(100),
    is_in_queue TINYINT(1),
    is_active TINYINT(1),
    activated_at VARCHAR(100),
    expired_at VARCHAR(100),
    total_used_traffic VARCHAR(100),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // ---- helper: http GET ----
    

    // prepared statements for speed
    $selectUserByChat = $pdo->prepare("SELECT id FROM users WHERE chat_id = ? LIMIT 1");
    $insertUser = $pdo->prepare("INSERT INTO users (chat_id, telegram_id, name, email, phone, test) VALUES (?, ?, ?, ?, ?, ?)");
    $updateUser = $pdo->prepare("UPDATE users SET telegram_id = ?, name = ?, email = ?, phone = ?, test = ? WHERE chat_id = ?");

    $selectClient = $pdo->prepare("SELECT id FROM clients WHERE id = ? LIMIT 1");
    $insertClient = $pdo->prepare("INSERT INTO clients (id, count_of_devices, username, password, chat_id, user_id) VALUES (?, ?, ?, ?, ?, ?)");
    $updateClient = $pdo->prepare("UPDATE clients SET count_of_devices = ?, username = ?, password = ?, chat_id = ?, user_id = ? WHERE id = ?");

    $selectPlan = $pdo->prepare("SELECT plan_id FROM clients_plans WHERE plan_id = ? LIMIT 1");
    $insertPlan = $pdo->prepare("INSERT INTO clients_plans (plan_id, client_id, name, price, created_at_raw, expire_date_raw, is_in_queue, is_active, activated_at, expired_at, total_used_traffic) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $updatePlan = $pdo->prepare("UPDATE clients_plans SET client_id = ?, name = ?, price = ?, created_at_raw = ?, expire_date_raw = ?, is_in_queue = ?, is_active = ?, activated_at = ?, expired_at = ?, total_used_traffic = ? WHERE plan_id = ?");

    // counters
    $processedClients = $insertedUsers = $insertedClients = $insertedPlans = 0;
    $skippedClientsNoDetail = 0;

    try {
        // pagination loop
        $pageUrl = rtrim($endpoint, '/') . '/clients?page=1';
        $pageNum = 0;
        $totalClientsCount = 0;
        while ($pageUrl !== null) {
            $pageNum++;
            // echo "<strong>Fetching page: {$pageUrl}</strong><br>";
            logFlush("Fetching page: {$pageUrl}");
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

                    // plans
                    if (!empty($clientDetail['plans']) && is_array($clientDetail['plans'])) {
                        foreach ($clientDetail['plans'] as $plan) {
                            $planId = $plan['id'] ?? null;
                            if (!$planId)
                                continue;
                            $selectPlan->execute([$planId]);
                            $planExists = $selectPlan->fetch();
                            $p_name = $plan['name'] ?? null;
                            $p_price = $plan['price'] ?? null;
                            $p_created_at = $plan['created_at'] ?? null;
                            $p_expire_date = $plan['expire_date'] ?? null;
                            $p_is_in_queue = !empty($plan['is_in_queue']) ? 1 : 0;
                            $p_is_active = !empty($plan['is_active']) ? 1 : 0;
                            $p_activated_at = $plan['activated_at'] ?? null;
                            $p_expired_at = $plan['expired_at'] ?? null;
                            $p_total_used = $plan['total_used_traffic'] ?? null;

                            if ($planExists) {
                                $updatePlan->execute([
                                    $clientDetail['id'],
                                    $p_name,
                                    $p_price,
                                    $p_created_at,
                                    $p_expire_date,
                                    $p_is_in_queue,
                                    $p_is_active,
                                    $p_activated_at,
                                    $p_expired_at,
                                    $p_total_used,
                                    $planId
                                ]);
                            } else {
                                $insertPlan->execute([
                                    $planId,
                                    $clientDetail['id'],
                                    $p_name,
                                    $p_price,
                                    $p_created_at,
                                    $p_expire_date,
                                    $p_is_in_queue,
                                    $p_is_active,
                                    $p_activated_at,
                                    $p_expired_at,
                                    $p_total_used
                                ]);
                                $insertedPlans++;
                            }
                        }
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
            file_put_contents(__DIR__ . '/setup_progress.json', json_encode([
                'page' => $pageNum,
                'processedClients' => $processedClients,
                'insertedUsers' => $insertedUsers,
                'insertedClients' => $insertedClients,
                'insertedPlans' => $insertedPlans,
                'skipped' => $skippedClientsNoDetail,
                'total_clients' => $totalClientsCount
            ]));

            // prepare next page
            $nextPage = $resp['clients']['next_page_url'] ?? null;
            if ($nextPage) {
                // 🔹 اصلاح لینک برای اطمینان از HTTPS و مسیر کامل
                if (strpos($nextPage, '//') === false) {
                    // مسیر نسبی است → به ابتدای آن دامنه را اضافه کن
                    $nextPage = rtrim($endpoint, '/') . '/' . ltrim($nextPage, '/');
                } elseif (strpos($nextPage, 'http://') === 0) {
                    // اگر با http شروع شده → با https جایگزین کن
                    $nextPage = preg_replace('#^http://#', 'https://', $nextPage);
                }

                $pageUrl = $nextPage;
            } else {
                $pageUrl = null;
            }

            // echo "<hr><br>";
            logFlush("<hr><br>");

        } // end while pagination

        // echo "<br>Done. summary:<br>";
        // echo "Processed clients (detailed fetched): {$processedClients}<br>";
        // echo "Inserted new users: {$insertedUsers}<br>";
        // echo "Inserted new clients: {$insertedClients}<br>";
        // echo "Inserted new plans: {$insertedPlans}<br>";
        // echo "Skipped clients (no detail / errors): {$skippedClientsNoDetail}<br>";

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
    // Step 1: Create config.php first
    config(
        $db_host,
        $db_name,
        $db_user,
        $db_pass,
        $panelToken,
        $botToken
    );
    
    // Now require config.php since it was just created
    require_once '../config.php';

    // Step 2: Set up admin user
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

    // Step 3: Set bot webhook
    try {
        setBotWebhook(
            $botToken
        );
    } catch (Exception $webhookErr) {
        logFlush("Warning: Webhook setup failed, but setup will continue: " . $webhookErr->getMessage());
    }

    // Step 4: Fetch clients from panel
    fetchBotConfig(
        $panelToken,
    );


    // Step 5: Sync database with panel
    dbSetup();
    
    logFlush("\n✅ Setup completed successfully!");
} catch (Exception $e) {
    // echo "Fatal error: " . $e->getMessage() . "<br>";
    logFlush("Fatal error: " . $e->getMessage() . "<br>");
    exit(1);
}