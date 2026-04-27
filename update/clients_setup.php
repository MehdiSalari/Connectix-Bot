<?php
$ok = $_GET['ok'] ?? null;
if ($ok !== 'true') {
    echo "<script>alert('Unauthorized Access')</script>";
    header('Location: ../index.php?updated=false');
    exit();
}

require_once '../functions.php';
require_once '../config.php';

function logFlush($msg) {
    echo $msg . "\n";
    ob_flush();
    flush();
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
    avatar TEXT DEFAULT NULL,
    action LONGTEXT NULL DEFAULT NULL,
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
    is_paid VARCHAR(50) DEFAULT NULL,
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
    logFlush("\n✅ Setup completed successfully!");
} catch (Exception $e) {
    logFlush("FATAL ERROR: " . $e->getMessage());
    logFlush("ERROR: Setup failed permanently");
} finally {
    // Always execute this last line to finalize the setup process
    logFlush("SETUP_FINISHED");
}
