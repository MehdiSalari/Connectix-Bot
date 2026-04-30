<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$ok = $_GET['ok'] ?? null;
if ($ok !== 'true') {
    header('Location: ../index.php?updated=false');
    exit();
}

require "../functions.php";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    $_SESSION['update_users_report'] = [
        'success' => false,
        'message' => 'Database connection failed.',
        'total' => 0,
        'updated' => 0,
        'not_updated' => 0,
        'logs' => []
    ];

    header('Location: ../index.php?updated=false');
    exit();
}

$conn->set_charset("utf8mb4");

$stmt = $conn->prepare("SELECT id, telegram_id, name, avatar FROM users WHERE telegram_id IS NOT NULL AND telegram_id != ''");
$stmt->execute();
$result = $stmt->get_result();
$users = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalUsers = count($users);
$updatedCount = 0;
$notUpdatedCount = 0;
$logs = [];

$updateStmt = $conn->prepare("UPDATE users SET name = ?, avatar = ? WHERE id = ?");

foreach ($users as $user) {
    $telegramUsername = normalizeBaleUsername($user['telegram_id']);
    $currentName = $user['name'] ?? null;
    $currentAvatar = $user['avatar'] ?? null;
    $fallbackName = $currentName ?: 'No name';

    if ($telegramUsername === '') {
        $notUpdatedCount++;
        $logs[] = $fallbackName . ' (@) -> Invalid username';
        continue;
    }

    $profile = fetchBaleProfile($telegramUsername);
    if (!$profile['exists']) {
        $notUpdatedCount++;
        $logs[] = $fallbackName . ' (@' . $telegramUsername . ') -> Not found';
        continue;
    }

    $newName = $profile['display_name'] ?: $currentName;
    $newAvatar = $profile['avatar'] ?: $currentAvatar;
    $shownName = $newName ?: $fallbackName;

    if ($newName === $currentName && $newAvatar === $currentAvatar) {
        $updatedCount++;
        $logs[] = $shownName . ' (@' . $telegramUsername . ') -> Already up to date';
        continue;
    }

    $updateStmt->bind_param("ssi", $newName, $newAvatar, $user['id']);
    if ($updateStmt->execute()) {
        $updatedCount++;
        $logs[] = $shownName . ' (@' . $telegramUsername . ') -> Updated';
        continue;
    }

    $notUpdatedCount++;
    $logs[] = $fallbackName . ' (@' . $telegramUsername . ') -> Update failed';
}

$updateStmt->close();
$conn->close();

$_SESSION['update_users_report'] = [
    'success' => true,
    'message' => 'Users update completed.',
    'total' => $totalUsers,
    'updated' => $updatedCount,
    'not_updated' => $notUpdatedCount,
    'logs' => $logs
];

header('Location: ../index.php?updated=true');
exit();
