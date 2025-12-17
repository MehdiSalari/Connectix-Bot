<?php
if (!file_exists('config.php')) {
    header('Location: setup');
    exit();
}
require_once 'config.php';
session_start();

// === Security Checks ===
if (empty($db_host) || empty($db_name) || empty($db_user) || empty($panelToken) || empty($botToken)) {
    header('Location: setup');
    exit();
}
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error)
    die("Connection failed: {$conn->connect_error}");

$result = $conn->query("SHOW TABLES LIKE 'admins'");
if ($result->num_rows == 0) {
    header('Location: setup.php');
    exit();
}

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->bind_param("i", $_SESSION['admin_id']);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

$totalUsers = $conn->query("SELECT COUNT(*) as cnt FROM users")->fetch_assoc()['cnt'];
$adminChatId = $admin['chat_id'] ?? null; // chat_id ادمین
$conn->close();


// Handle Configuration Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // get data from request
    $appName = $_POST['app_name'] ?? '';
    $adminId = $_POST['admin_id'] ?? '';
    $telegramSupport = $_POST['telegram_support'] ?? '';
    $telegramChannel = $_POST['telegram_channel'] ?? '';
    $cardNumber = $_POST['card_number'] ?? '';
    $cardName = $_POST['card_name'] ?? '';
    $welcomeMessage = $_POST['welcome_message'] ?? '';
    $supportMessage = $_POST['support_message'] ?? '';
    $faqMessage = $_POST['faq_message'] ?? '';
    $freeTrialMessage = $_POST['free_trial_message'] ?? '';

    // update config file
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

    $config = json_encode($botConfig, JSON_PRETTY_PRINT);
    file_put_contents('setup/bot_config.json', $config);

    //update config in main panel
    //get data from api
    $endpoint = "https://api.connectix.vip/v1/seller/telegram-bot";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false, // Connectix sometimes has SSL issues
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
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

    if ($http_code != 200) {
        $errorMsg = "خطا در ارتباط با پنل مدیریت. کد خطا: $http_code";
        echo "<script>alert('$errorMsg')</script>";
        exit();
    }

    $data = json_decode($response, true);

    if (isset($data['bot']) && !empty($data['bot'])) {
        // Update local config file
        $updateData = [
            'app_name' => $appName,
            'support_telegram' => $telegramSupport,
            'channel_id' => $data['bot']['channel_id'],
            'channel_telegram' => $telegramChannel,
            'token' => $data['bot']['token'],
            'card_number' => $cardNumber,
            'card_name' => $cardName,
            'is_enabled' => $data['bot']['is_enabled'],
            'admin_id' => $adminId,
            'is_90_percent_plan_notifications_enabled' => $data['bot']['is_90_percent_plan_notifications_enabled'],
            'is_expired_plan_notifications_enabled' => $data['bot']['is_expired_plan_notifications_enabled'],
        ];

        $newConfig = json_encode($updateData, JSON_PRETTY_PRINT);
        $endpoint = "https://api.connectix.vip/v1/seller/telegram-bot/update-bot";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false, // Connectix sometimes has SSL issues
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$panelToken}",
                "Accept: application/json",
                "Content-Type: application/json",
                "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36",
                "Origin: https://connectix.vip",
                "Referer: https://connectix.vip/"
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $newConfig
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code != 200) {
            $errorMsg = "خطا در ارتباط با پنل مدیریت. کد خطا: $http_code";
            echo "<script>alert('$errorMsg')</script>";
            exit();
        }

        $uploadBasePath = 'assets/videos/guide/';
        if (!is_dir($uploadBasePath)) {
            mkdir($uploadBasePath, 0755, true);
        }

        $platforms = ['android', 'ios', 'windows', 'mac', 'use'];
        foreach ($platforms as $plat) {
            if (!empty($_FILES["video_$plat"]['name'])) {
                $file = $_FILES["video_$plat"];

                // اعتبارسنجی دوباره در سرور
                if ($file['error'] !== UPLOAD_ERR_OK)
                    continue;

                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($ext !== 'mp4' || $file['type'] !== 'video/mp4') {
                    continue;
                }

                if ($file['size'] > 10 * 1024 * 1024)
                    continue;

                $targetPath = $uploadBasePath . $plat . '.mp4';
                move_uploaded_file($file['tmp_name'], $targetPath);

            }
        }

        echo "<script>alert('تنظیمات با موفقیت ذخیره شد!')</script>";
    }
}


?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پنل مدیریت | Connectix Bot</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@100..900&display=swap" rel="stylesheet">
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <style>
        html,
        body {
            height: 100%;
        }

        html {
            background-color: #e7edff;
        }

        body {
            font-family: 'Vazirmatn', sans-serif;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        body>.container {
            flex: 1 0 auto;
        }

        #messageForm label {
            font-weight: bolder;
        }

        .log-item.success {
            background: linear-gradient(90deg, #d4edda, #c3e6cb);
        }

        .log-item.error {
            background: linear-gradient(90deg, #f8d7da, #f5c6cb);
        }

        .log-item.blocked {
            background: linear-gradient(90deg, #fff3cd, #ffeaa7);
        }

        .progress-bar {
            transition: width 0.4s ease;
        }

        #filePreview {
            max-width: 300px;
            max-height: 200px;
            object-fit: cover;
        }

        .copyright {
            width: 100%;
            text-align: center;
            color: #777;
            font-size: 15px;
            direction: ltr;
            margin: auto;
            padding-bottom: 10px;
            flex-shrink: 0;
        }

        .copyright a {
            color: #b500bbff;
            text-decoration: none;
        }
    </style>
</head>

<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen scroll-smooth">
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <!-- Header -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <div class="grid md:grid-cols-2 gap-6 items-start">

                <div class="text-right">
                    <h1 class="text-3xl font-bold text-gray-800">پنل مدیریت Connectix Bot</h1>
                    <p class="text-gray-600 mt-1">خوش آمدید، <?= htmlspecialchars($admin['email']) ?></p>
                </div>

                <div class="flex flex-col gap-5 items-end">

                    <a href="logout.php"
                        class="bg-red-500 hover:bg-red-600 text-white px-6 py-3 rounded-lg font-semibold transition flex items-center gap-2 w-fit">
                        <i class="fas fa-sign-out-alt"></i> خروج
                    </a>


                    <div class="flex flex-wrap gap-3 justify-end w-full">
                        <a href="setup"
                            class="bg-blue-500 hover:bg-blue-600 text-white px-5 py-3 rounded-lg font-semibold transition flex items-center gap-2 whitespace-nowrap">
                            <i class="fas fa-cloud-arrow-down"></i> تنظیمات اولیه
                        </a>
                        <a id="messagesBtn" href="#"
                            class="bg-blue-500 hover:bg-blue-600 text-white px-5 py-3 rounded-lg font-semibold transition flex items-center gap-2 whitespace-nowrap">
                            <i class="fas fa-cog"></i> تنظیمات بات
                        </a>
                        <a id="broadcastBtn" href="#"
                            class="bg-blue-500 hover:bg-blue-600 text-white px-5 py-3 rounded-lg font-semibold transition flex items-center gap-2 whitespace-nowrap">
                            <i class="fas fa-comments"></i> ارسال پیام همگانی
                        </a>
                        <a href="users"
                            class="bg-gradient-to-r from-indigo-600 to-purple-600 text-white px-5 py-3 rounded-lg font-semibold transition flex items-center gap-2 whitespace-nowrap shadow-md">
                            <i class="fas fa-users"></i> لیست کاربران
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bot Settings Form -->
        <div id="messageFormContainer" class="bg-white rounded-xl shadow-xl p-8 mb-8" style="display: none;">
            <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center gap-3">
                <i class="fas fa-cog text-gray-600"></i>
                مدیریت تنظیمات بات
            </h2>

            <form id="messageForm" method="post" action="index.php" enctype="multipart/form-data" class="space-y-6">
                <?php
                //get data from bot_config.json
                $data = file_get_contents('setup/bot_config.json');
                $config = json_decode($data, true);
                $appName = $config['app_name'] ?? '';
                $adminId = $config['admin_id'] ?? '';
                $telegramSupport = $config['support_telegram'] ?? '';
                $telegramChannel = $config['channel_telegram'] ?? '';
                $cardNumber = $config['card_number'] ?? '';
                $cardName = $config['card_name'] ?? '';

                $welcomeMessage = $config['messages']['welcome_text'] ?? '';
                $supportMessage = $config['messages']['contact_support'] ?? '';
                $faqMessage = $config['messages']['questions_and_answers'] ?? '';
                $freeTrialMessage = $config['messages']['free_test_account_created'] ?? '';

                // Videos path
                $videos = $config['videos'] ?? [
                    'use' => '',
                    'android' => '',
                    'ios' => '',
                    'windows' => '',
                    'mac' => '',
                    'linux' => '',
                ];
                ?>
                <!-- Main Settings -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="input-group">
                        <label class="block text-gray-700 font-semibold mb-2">نام برنامه</label>
                        <input type="text" id="app_name" name="app_name" value="<?= $appName ?>"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg file:mr-4 file:py-3 file:px-6 file:rounded-lg file:border-0 file:bg-blue-600 file:text-white hover:file:bg-blue-700">
                    </div>
                    <div class="input-group">
                        <label class="block text-gray-700 font-semibold mb-2"> آیدی عددی ادمین</label>
                        <input type="text" id="admin_id" name="admin_id" value="<?= $adminId ?>"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg file:mr-4 file:py-3 file:px-6 file:rounded-lg file:border-0 file:bg-blue-600 file:text-white hover:file:bg-blue-700">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="input-group">
                        <label class="block text-gray-700 font-semibold mb-2">نام کاربری پشتیبانی تلگرام</label>
                        <input type="text" id="telegram_support" name="telegram_support" value="<?= $telegramSupport ?>"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg file:mr-4 file:py-3 file:px-6 file:rounded-lg file:border-0 file:bg-blue-600 file:text-white hover:file:bg-blue-700">
                    </div>
                    <div class="input-group">
                        <label class="block text-gray-700 font-semibold mb-2">نام کاربری کانال تلگرام</label>
                        <input type="text" id="telegram_channel" name="telegram_channel" value="<?= $telegramChannel ?>"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg file:mr-4 file:py-3 file:px-6 file:rounded-lg file:border-0 file:bg-blue-600 file:text-white hover:file:bg-blue-700">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="input-group">
                        <label class="block text-gray-700 font-semibold mb-2">نام دارنده کارت</label>
                        <input type="text" id="card_name" name="card_name" value="<?= $cardName ?>"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg file:mr-4 file:py-3 file:px-6 file:rounded-lg file:border-0 file:bg-blue-600 file:text-white hover:file:bg-blue-700">
                    </div>
                    <div class="input-group">
                        <label class="block text-gray-700 font-semibold mb-2">شماره کارت</label>
                        <input type="text" id="card_number" name="card_number" value="<?= $cardNumber ?>"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg file:mr-4 file:py-3 file:px-6 file:rounded-lg file:border-0 file:bg-blue-600 file:text-white hover:file:bg-blue-700">
                    </div>
                </div>

                <!-- Guide Videos -->
                <div class="border-t-2 border-gray-200 pt-8">
                    <h3 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-3">
                        <i class="fas fa-video text-purple-600"></i>
                        ویدیوهای آموزشی
                    </h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                        <?php
                        $platforms = [
                            'use' => 'نحوه استفاده کلی',
                            'android' => 'آموزش اندروید',
                            'ios' => 'آموزش iOS',
                            'windows' => 'آموزش ویندوز',
                            'mac' => 'آموزش مک',
                            'linux' => 'آموزش لینوکس'
                        ];

                        $uploadBasePath = 'assets/videos/guide/';
                        if (!is_dir($uploadBasePath)) {
                            mkdir($uploadBasePath, 0755, true);
                        }

                        foreach ($platforms as $key => $label):
                            $videoPath = $uploadBasePath . $key . '.mp4';
                            $videoUrl = $videoPath . '?t=' . (file_exists($videoPath) ? filemtime($videoPath) : time());
                            ?>
                            <div class="space-y-3">
                                <label class="block text-gray-700 font-semibold"><?= $label ?></label>

                                <!-- پیش‌نمایش ویدیو -->
                                <div id="preview-<?= $key ?>"
                                    class="rounded-xl overflow-hidden shadow-lg bg-gray-50 aspect-video relative">
                                    <?php if (file_exists($videoPath)): ?>
                                        <video controls class="w-full h-full object-cover">
                                            <source src="<?= $videoUrl ?>" type="video/mp4">
                                            مرورگر شما از ویدیو پشتیبانی نمی‌کند.
                                        </video>
                                    <?php else: ?>
                                        <div class="flex flex-col items-center justify-center h-full text-gray-400">
                                            <i class="fas fa-video text-5xl mb-3"></i>
                                            <p class="text-sm">ویدیویی آپلود نشده</p>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!--  Just Upload mp4 -->
                                <input type="file" name="video_<?= $key ?>" id="video_<?= $key ?>" accept="video/mp4" class="block w-full text-sm text-gray-600 
                                    file:mr-4 file:py-3 file:px-6 file:rounded-lg file:border-0 
                                    file:text-sm file:font-semibold file:bg-indigo-600 file:text-white 
                                    hover:file:bg-indigo-700 cursor-pointer">

                                <p class="text-xs text-gray-500 mt-1">فقط فایل MP4 (حداکثر ۱۰ مگابایت)</p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Messages -->
                <div class="border-t-2 border-gray-200 pt-8"></div>
                <h3 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-3">
                    <i class="fas fa-comments text-blue-600"></i>
                    پیام های بات
                </h3>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">پیام خوش آمد گویی</label>
                    <textarea id="welcome_message" name="welcome_message" rows="5"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-4 focus:ring-blue-200 focus:border-blue-500 outline-none transition"
                        placeholder="متن پیام خوش آمد گویی را اینجا بنویسید..."><?= htmlspecialchars($welcomeMessage) ?>
                        </textarea>
                </div>

                <div>
                    <label class="block text-gray-700 font-semibold mb-2">پیام پشتیبانی</label>
                    <textarea id="support_message" name="support_message" rows="5"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-4 focus:ring-blue-200 focus:border-blue-500 outline-none transition"
                        placeholder="متن پیام خوش آمد گویی را اینجا بنویسید..."><?= htmlspecialchars($supportMessage) ?>
                        </textarea>
                </div>

                <div>
                    <label class="block text-gray-700 font-semibold mb-2">سوالات متداول</label>
                    <textarea id="faq_message" name="faq_message" rows="5"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-4 focus:ring-blue-200 focus:border-blue-500 outline-none transition"
                        placeholder="متن پیام خوش آمد گویی را اینجا بنویسید..."><?= htmlspecialchars($faqMessage) ?>
                        </textarea>
                </div>

                <div>
                    <label class="block text-gray-700 font-semibold mb-2">متن پیام دریافت اکانت تست</label>
                    <textarea id="free_trial_message" name="free_trial_message" rows="5"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-4 focus:ring-blue-200 focus:border-blue-500 outline-none transition"
                        placeholder="متن پیام خوش آمد گویی را اینجا بنویسید..."><?= htmlspecialchars($freeTrialMessage) ?>
                        </textarea>
                </div>

                <!-- Buttons -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <button type="button" id="closeBtn"
                        class="bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white font-bold py-4 rounded-lg text-lg transition transform hover:scale-105 flex items-center justify-center gap-3">
                        <i class="fas fa-circle-xmark"></i>بستن
                    </button>

                    <button type="submit" id="submitBtn"
                        class="bg-gradient-to-r from-blue-600 to-indigo-700 hover:from-blue-700 hover:to-indigo-800 text-white font-bold py-4 rounded-lg text-lg transition transform hover:scale-105 flex items-center justify-center gap-3">
                        <i class="fas fa-circle-check"></i>
                        <span id="btnText">ثبت اطلاعات</span>
                    </button>
                </div>
        </div>
        </form>
        <!-- </div> -->

        <!-- Broadcast Form -->
        <div id="broadcastFormContainer" class="bg-white rounded-xl shadow-xl p-8 mb-8" style="display: none;">
            <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center gap-3">
                <i class="fas fa-paper-plane text-blue-600"></i>
                ارسال پیام همگانی به <?= number_format($totalUsers) ?> کاربر
            </h2>

            <form id="broadcastForm" enctype="multipart/form-data" class="space-y-6">
                <!-- Upload File -->
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">فایل (عکس، ویدیو، فایل و ...):</label>
                    <input type="file" id="media" name="media" accept="image/*,video/*,audio/*,.pdf,.doc,.docx"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg file:mr-4 file:py-3 file:px-6 file:rounded-lg file:border-0 file:bg-blue-600 file:text-white hover:file:bg-blue-700 cursor-pointer">
                    <div id="previewContainer" class="mt-4 hidden">
                        <p class="text-sm text-gray-600 mb-2">پیش‌نمایش:</p>
                        <img id="filePreview" class="rounded-lg shadow-md" alt="پیش‌نمایش">
                        <video id="videoPreview" class="rounded-lg shadow-md hidden" controls></video>
                        <div id="fileInfo" class="mt-2 text-sm text-gray-600"></div>
                    </div>
                </div>

                <!-- Message Text -->
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">متن پیام (کپشن):</label>
                    <textarea id="message" name="message" rows="5"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-4 focus:ring-blue-200 focus:border-blue-500 outline-none transition"
                        placeholder="متن پیام یا کپشن فایل را اینجا بنویسید..."></textarea>
                </div>

                <!-- Buttons -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <button type="button" id="testBtn"
                        class="bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white font-bold py-4 rounded-lg text-lg transition transform hover:scale-105 flex items-center justify-center gap-3">
                        <i class="fas fa-eye"></i> تست پیام (فقط برای شما)
                    </button>

                    <button type="submit" id="sendBtn"
                        class="bg-gradient-to-r from-blue-600 to-indigo-700 hover:from-blue-700 hover:to-indigo-800 text-white font-bold py-4 rounded-lg text-lg transition transform hover:scale-105 flex items-center justify-center gap-3">
                        <i class="fas fa-paper-plane"></i>
                        <span id="btnText">ارسال به همه کاربران</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Live Progress Section -->
        <div id="progressContainer" class="hidden bg-white rounded-xl shadow-xl p-8">
            <div class="text-center mb-6">
                <h3 class="text-2xl font-bold text-gray-800">در حال ارسال...</h3>
                <p class="text-gray-600 mt-2">لطفاً صفحه را نبندید</p>
            </div>

            <div class="mb-8">
                <div class="flex justify-between text-sm text-gray-600 mb-2">
                    <span>پیشرفت:</span>
                    <span id="progressText">0 از <?= number_format($totalUsers) ?></span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-6 overflow-hidden">
                    <div id="progressBar"
                        class="progress-bar h-full bg-gradient-to-r from-green-500 to-emerald-600 rounded-full flex items-center justify-end pr-4 text-white font-bold text-sm"
                        style="width: 0%">0%</div>
                </div>
            </div>

            <div class="bg-gray-50 rounded-lg p-4 h-96 overflow-y-auto border border-gray-200">
                <div id="logContainer" class="space-y-2 text-sm"></div>
            </div>

            <div class="mt-6 text-center">
                <button id="closeProgress"
                    class="hidden bg-green-600 hover:bg-green-700 text-white px-8 py-3 rounded-lg font-bold transition">
                    ارسال با موفقیت به پایان رسید
                </button>
            </div>
        </div>
    </div>
    </div>
    <div class="copyright">
        <p>&copy; 2024 - <?= date('Y') ?> Connectix Bot designed by <a href="https://github.com/MehdiSalari"
                target="_blank">Mehdi Salari</a>. All rights reserved.</p>
    </div>

    <script>
        const messageFormContainer = document.getElementById('messageFormContainer');
        const broadcastFormContainer = document.getElementById('broadcastFormContainer');


        const messagesBtn = document.getElementById('messagesBtn');
        messagesBtn.addEventListener('click', function () {
            messageFormContainer.style.display = messageFormContainer.style.display === 'none' ? 'block' : 'none';
        });

        closeBtn.addEventListener('click', function () {
            messageFormContainer.style.display = 'none';
        });

        const broadcastBtn = document.getElementById('broadcastBtn');
        broadcastBtn.addEventListener('click', function () {
            broadcastFormContainer.style.display = broadcastFormContainer.style.display === 'none' ? 'block' : 'none';
        });



        const mediaInput = document.getElementById('media');
        const previewContainer = document.getElementById('previewContainer');
        const filePreview = document.getElementById('filePreview');
        const videoPreview = document.getElementById('videoPreview');
        const fileInfo = document.getElementById('fileInfo');

        mediaInput.addEventListener('change', function () {
            const file = this.files[0];
            if (!file) {
                previewContainer.classList.add('hidden');
                return;
            }

            const url = URL.createObjectURL(file);
            fileInfo.textContent = `${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)`;

            if (file.type.startsWith('image/')) {
                filePreview.src = url;
                filePreview.classList.remove('hidden');
                videoPreview.classList.add('hidden');
                previewContainer.classList.remove('hidden');
            } else if (file.type.startsWith('video/')) {
                videoPreview.src = url;
                videoPreview.classList.remove('hidden');
                filePreview.classList.add('hidden');
                previewContainer.classList.remove('hidden');
            } else {
                filePreview.classList.add('hidden');
                videoPreview.classList.add('hidden');
                previewContainer.classList.remove('hidden');
            }
        });

        // Test Message (with loading indicator until request completes)
        document.getElementById('testBtn').addEventListener('click', async function () {
            const testBtn = this;
            const message = document.getElementById('message').value;
            const file = mediaInput.files[0];

            if (!message && !file) {
                alert('حداقل متن یا فایل وارد کنید!');
                return;
            }

            const formData = new FormData();
            formData.append('test', '1');
            formData.append('message', message);
            if (file) formData.append('media', file);

            // show loading state
            const originalHTML = testBtn.innerHTML;
            testBtn.disabled = true;
            testBtn.innerHTML = '⏳ در حال ارسال...';

            try {
                const res = await fetch('broadcast/broadcast_start.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                if (data && data.success) {
                    alert(data.message || 'تست با موفقیت ارسال شد!');
                } else {
                    let errorMsg = (data && data.message) ? data.message : 'خطا در ارسال تست';
                    if (data && data.description) {
                        errorMsg += '\n\nتوضیح: ' + data.description;
                    }
                    if (data && data.response) {
                        errorMsg += '\n\nپاسخ سرور: ' + JSON.stringify(data.response, null, 2);
                    }
                    alert(errorMsg);
                }
            } catch (err) {
                alert('خطا در ارسال درخواست: ' + (err && err.message ? err.message : String(err)));
            } finally {
                // restore button state
                testBtn.disabled = false;
                testBtn.innerHTML = originalHTML;
            }
        });

        // Broadcast to All Users
        document.getElementById('broadcastForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const message = document.getElementById('message').value.trim();
            const file = mediaInput.files[0];

            if (!message && !file) {
                alert('لطفاً پیام یا فایل وارد کنید!');
                return;
            }

            if (!confirm(`آیا از ارسال این پیام به ${<?= $totalUsers ?>} کاربر مطمئن هستید؟`)) return;

            const formData = new FormData();
            formData.append('message', message);
            if (file) formData.append('media', file);

            document.getElementById('progressContainer').classList.remove('hidden');
            document.getElementById('logContainer').innerHTML = '';
            document.getElementById('progressBar').style.width = '0%';

            fetch('broadcast/broadcast_start.php', { method: 'POST', body: formData });

            // Connect to SSE for live progress — only create one connection
            eventSource = new EventSource('broadcast/broadcast_progress.php');
            eventSource.onopen = function () {
                // Connection established — clear any previous error messages
                console.log('SSE connection opened');
            };

            let sent = 0;
            const total = <?= $totalUsers ?>;

            eventSource.onmessage = function (e) {
                const data = JSON.parse(e.data);

                if (data.type === 'progress') {
                    sent++;
                    const percent = Math.round((sent / total) * 100);
                    progressBar.style.width = percent + '%';
                    progressBar.textContent = percent + '%';
                    progressText.textContent = `${sent} از ${total}`;
                }

                if (data.type === 'log') {
                    const item = document.createElement('div');
                    item.className = `log-item p-3 rounded-lg flex items-center gap-3 ${data.status}`;

                    let icon = '✅';
                    if (data.status === 'error') icon = '❌';
                    if (data.status === 'blocked') icon = '🚫';

                    item.innerHTML = `
                        ${icon} 
                        <strong>کاربر ${data.userId}:</strong> 
                        <span>${data.message}</span>
                    `;
                    logContainer.appendChild(item);
                    logContainer.scrollTop = logContainer.scrollHeight;
                }

                if (data.type === 'done') {
                    eventSource.close();
                    closeProgress.classList.remove('hidden');
                    sendBtn.disabled = false;
                    btnText.textContent = 'ارسال پیام به همه کاربران';
                }
            };

            eventSource.onerror = function (e) {
                // readyState: 0 = CONNECTING, 1 = OPEN, 2 = CLOSED
                try {
                    if (eventSource.readyState === EventSource.CONNECTING) {
                        // transient reconnecting — نگران نباش
                        console.log('SSE reconnecting...');
                        return;
                    }
                    if (eventSource.readyState === EventSource.CLOSED) {
                        // connection closed normally; don't show error
                        console.log('SSE closed');
                        return;
                    }
                } catch (ex) {
                    // ignore and continue
                }
                // If we get here, an actual error occurred — log it but allow other messages to be received
                logContainer.innerHTML += '<div class="log-item error p-3 rounded-lg">خطا در ارتباط با سرور!</div>';
            };
        });

        closeProgress.addEventListener('click', () => {
            progressContainer.classList.add('hidden');
        });

        document.querySelectorAll('input[type="file"][accept="video/mp4"]').forEach(input => {
            input.addEventListener('change', function () {
                const file = this.files[0];
                const previewId = 'preview-' + this.id.replace('video_', '');
                const previewContainer = document.getElementById(previewId);

                if (file) {
                    const url = URL.createObjectURL(file);
                    previewContainer.innerHTML = `
                        <video controls class="w-full h-full object-cover rounded-xl">
                            <source src="${url}" type="${file.type}">
                            مرورگر شما از ویدیو پشتیبانی نمی‌کند.
                        </video>
                    `;
                }
            });
        });
    </script>
</body>

</html>