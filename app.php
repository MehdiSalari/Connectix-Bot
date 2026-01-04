<?php
require_once 'functions.php';
$config = json_decode(file_get_contents('setup/bot_config.json'), true);
$appName = $config['app_name'] ?? 'Connectix Bot';
$adminID = $config['admin_id'] ?? null;
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $appName ?> | WebApp</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <style>
        html {background-color: #e7edff;}
        body { font-family: 'Vazirmatn', sans-serif; }
        .client-card { transition: all 0.3s; }
        .client-card:hover { transform: translateY(-6px); box-shadow: 0 20px 30px rgba(0,0,0,0.1); }
        .status-active { background: linear-gradient(90deg, #d4edda, #c3e6cb); }
        .status-expired { background: linear-gradient(90deg, #f8d7da, #f5c6cb); }
        .copy-btn { transition: all 0.2s; }
        .copy-btn:hover { transform: scale(1.2); }
        .loading { opacity: 0.6; pointer-events: none; }
        .copyright { width: 100%; text-align: center; color: #777; font-size: 15px; direction: ltr; margin: 20px 0 10px; }
        .copyright a { color: #b500bbff; text-decoration: none; }
        /* Mobile Width */
        @media (max-width: 768px) {
            .operation { width: 100vw; }
            .type { width: 100vw; text-align: center; }
            .status { text-align: left; }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen" id="body" style="display: none">
    <div class="container mx-auto px-4 py-8 max-w-7xl">

        <!-- Header -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <div class="grid md:grid-cols-2 gap-6 items-start">
                <div class="text-right">
                    <h1 class="text-3xl font-bold text-gray-800">پنل مدیریت <?= $appName ?></h1>
                    <p class="text-gray-600 mt-1">خوش آمدید، <span id="adminName"></span></p>
                </div>
            </div>
        </div>

        <!-- Buttons -->
        <div class="bg-white rounded-xl shadow-lg p-6">
            <div class="flex flex-col gap-4">

                <button id="profileBtn" class="bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-semibold py-4 px-6 rounded-xl shadow-lg transition transform hover:scale-105">
                    <div class="flex justify-center items-center gap-2">
                        <i class="fas fa-user-circle icon"></i>
                        <span>پروفایل من</span>
                    </div>
                </button>

                <button id="adminBtn" class="bg-gradient-to-r from-purple-500 to-blue-500 hover:from-purple-600 hover:to-blue-600 text-white font-semibold py-4 px-6 rounded-xl shadow-lg transition transform hover:scale-105">
                    <div class="flex justify-center items-center gap-2">
                        <i class="fas fa-cog icon"></i>
                        <span>مدیریت پنل</span>
                    </div>
                </button>

            </div>
        </div>
    </div>

    <script>
    Telegram.WebApp.ready();
    Telegram.WebApp.expand();
    Telegram.WebApp.BackButton.onClick(() => Telegram.WebApp.close());

    const user = Telegram.WebApp.initDataUnsafe.user;

    if (!user) {
        document.body.innerHTML = 'No Telegram User';
        window.location.href = 'index.php';
    }

    const user_id = user.id;
    const user_name = user.first_name || '';
    const user_last_name = user.last_name || '';
    const userPic = user.photo_url || '';
    const profileBtn = document.getElementById('profileBtn');
    const adminBtn = document.getElementById('adminBtn');

    document.getElementById('adminName').textContent = user_name + ' ' + user_last_name;

    if (String(user_id) === String(<?= $adminID ?>)) {
        document.getElementById('body').style.display = 'block';
    } else {
        window.location.href = 'users/profile.php?userID=' + user_id + '&userPic=' + encodeURIComponent(userPic);
    }

    profileBtn.addEventListener('click', function() {
        window.location.href = 'users/profile.php?userID=' + user_id + '&userPic=' + encodeURIComponent(userPic);
    });

    adminBtn.addEventListener('click', function() {
        window.location.href = 'index.php';
    });
    </script>
</body>
</html>