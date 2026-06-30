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

    file_put_contents(__DIR__ . '/setup_progress.json', json_encode([
        'page' => 0,
        'processedClients' => 0,
        'insertedUsers' => 0,
        'insertedClients' => 0,
        'insertedPlans' => 0,
        'skipped' => 0,
        'total_clients' => 0,
        'percent' => 0,
        'stage' => 'Starting client sync...'
    ]));

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
                
                // Write initial progress with total_clients set
                file_put_contents(__DIR__ . '/setup_progress.json', json_encode([
                    'page' => $pageNum,
                    'processedClients' => 0,
                    'insertedUsers' => $insertedUsers,
                    'insertedClients' => $insertedClients,
                    'insertedPlans' => $insertedPlans,
                    'skipped' => $skippedClientsNoDetail,
                    'total_clients' => $totalClientsCount,
                    'percent' => 0
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
            $currentPercent = $totalClientsCount > 0 ? (int)(($processedClients / $totalClientsCount) * 100) : 0;
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
    logFlush("Starting Connectix client sync...");
    dbSetup();
    logFlush("\n✅ Client sync completed successfully!");
} catch (Exception $e) {
    logFlush("FATAL ERROR: " . $e->getMessage());
    logFlush("ERROR: Client sync failed permanently");
} finally {
    // Always execute this last line to finalize the setup process
    logFlush("SETUP_FINISHED");
}
