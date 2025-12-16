<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}
require_once '../functions.php';

$adminId = $_SESSION['admin_id'];
$admin = getAdminById($adminId);

$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($userId <= 0) die('کاربر یافت نشد.');

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// دریافت اطلاعات کاربر از دیتابیس
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$userResult = $stmt->get_result();
$user = $userResult->fetch_assoc();
$stmt->close();

if (!$user) die('کاربر یافت نشد.');

// دریافت لیست اکانت‌های متصل
$clients = [];
$stmt = $conn->prepare("SELECT id, username, password, count_of_devices, created_at FROM clients WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $clients[] = $row;
}
$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مشاهده کاربر | <?= htmlspecialchars($user['name'] ?? 'کاربر') ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
    <link rel="icon" href="../favicon.ico" type="image/x-icon">
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
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen">
<div class="container mx-auto px-4 py-8 max-w-7xl">

    <!-- هدر -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
        <div class="grid md:grid-cols-2 gap-6 items-start">
            <div class="text-right">
                <h1 class="text-3xl font-bold text-gray-800">پنل مدیریت Connectix Bot</h1>
                <p class="text-gray-600 mt-1">خوش آمدید، <?= htmlspecialchars($admin['email']) ?></p>
            </div>
            <div class="flex flex-col gap-5 items-end">
                <a href="index.php" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-lg font-semibold transition flex items-center gap-2 w-fit shadow-md">
                    بازگشت به لیست کاربران
                </a>
            </div>
        </div>
    </div>

    <!-- اطلاعات کاربر -->
    <div class="bg-white rounded-xl shadow-xl p-8 mb-8">
        <div class="flex flex-col md:flex-row items-center gap-8">
            <div class="w-28 h-28 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-full flex items-center justify-center text-white text-5xl font-bold shadow-xl">
                <?= mb_substr($user['name'] ?? 'U', 0, 1) ?>
            </div>
            <div class="text-center md:text-right">
                <h2 class="text-3xl font-bold text-gray-800"><?= htmlspecialchars($user['name'] ?? 'نامشخص') ?></h2>
                <div class="mt-3 space-y-2 text-gray-600">
                    <p>آیدی عددی: <code class="bg-gray-100 px-3 py-1 rounded font-mono"><?= $user['chat_id'] ?></code></p>
                    <?php if ($user['telegram_id']): ?>
                        <p>یوزرنیم: <a href="https://t.me/<?= htmlspecialchars($user['telegram_id']) ?>" target="_blank" class="text-blue-600 hover:underline">@<?= htmlspecialchars($user['telegram_id']) ?></a></p>
                    <?php endif; ?>
                    <p class="text-sm">ثبت‌نام: <?= jdate($user['created_at'], true) ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- اکانت‌های متصل -->
    <div class="bg-white rounded-xl shadow-xl overflow-hidden">
        <div class="p-6 border-b border-gray-200 bg-gradient-to-r from-indigo-50 to-purple-50">
            <h3 class="text-2xl font-bold text-gray-800 flex items-center gap-3">
                اکانت‌های متصل (<?= count($clients) ?>)
            </h3>
        </div>

        <?php if (empty($clients)): ?>
            <div class="p-16 text-center">
                <i class="fas fa-inbox text-6xl text-gray-300 mb-4"></i>
                <p class="text-gray-500 text-lg">هنوز هیچ اکانتی متصل نشده است.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 2xl:grid-cols-3 gap-8 p-8" id="clientsContainer">
                <?php foreach ($clients as $index => $client): ?>
                    <div class="client-card bg-white border border-gray-200 rounded-xl overflow-hidden shadow-lg relative loading" id="client-<?= $client['id'] ?>">
                        <div class="absolute inset-0 bg-gray-100 animate-pulse"></div>
                        <div class="relative p-6 space-y-5">
                            <div class="flex justify-between items-start">
                                <div>
                                    <h4 class="font-bold text-lg text-gray-800">در حال بارگذاری...</h4>
                                    <p class="text-xs text-gray-500">آیدی: <?= $client['id'] ?></p>
                                </div>
                                <div class="w-10 h-10 rounded-full bg-gray-300 animate-pulse"></div>
                            </div>
                            <div class="space-y-3 text-sm text-gray-600">
                                <div class="bg-gray-200 h-5 rounded w-32 animate-pulse"></div>
                                <div class="bg-gray-200 h-5 rounded w-40 animate-pulse"></div>
                                <div class="bg-gray-200 h-5 rounded w-28 animate-pulse"></div>
                            </div>
                            <div class="pt-4 border-t border-gray-200">
                                <button class="w-full bg-gray-300 text-white py-3 rounded-lg animate-pulse">در حال بارگذاری...</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="copyright">
    <p>&copy; 2024 - <?= date('Y') ?> Connectix Bot designed by <a href="https://github.com/MehdiSalari" target="_blank">Mehdi Salari</a>. All rights reserved.</p>
</div>

<script>
async function loadClientData(clientId, element) {
    try {
        const response = await fetch(`https://api.connectix.vip/v1/seller/clients/show?id=${clientId}`, {
            headers: {
                'Authorization': `Bearer <?= $panelToken ?>`
            }
        });
        const data = await response.json();

        if (!data.client) {
            element.innerHTML = `<div class="p-8 text-center text-red-600">اطلاعات دریافت نشد</div>`;
            return;
        }

        const c = data.client;
        const activePlan = c.plans.find(p => p.is_active == 1) || c.plans[0] || null;
        const totalPlans = c.plans.length;
        const isActive = activePlan && activePlan.is_active == 1;
        const traffic = activePlan ? activePlan.total_used_traffic : '—';
        const expireDate = activePlan ? activePlan.expire_date : (c.expire_date || '—');

        element.classList.remove('loading');
        element.innerHTML = `
            <div class="p-6 space-y-5">
                <div class="flex justify-between items-start">
                    <div>
                        <h4 class="font-bold text-lg text-gray-800">${c.name || 'نامشخص'}</h4>
                        <p class="text-xs text-gray-500">آیدی: ${c.id}</p>
                        ${c.email ? `<p class="text-xs text-blue-600">${c.email}</p>` : ''}
                    </div>
                    <span class="px-3 py-1 rounded-full text-xs font-bold ${isActive ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}">
                        ${isActive ? 'فعال' : 'منقضی'}
                    </span>
                </div>

                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-600">پلن فعلی:</span>
                        <span class="font-semibold">${activePlan ? activePlan.name : '—'}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">ترافیک مصرفی:</span>
                        <span class="font-mono font-bold ${traffic.includes('/') && parseFloat(traffic.split('/')[0]) >= parseFloat(traffic.split('/')[1]) ? 'text-red-600' : 'text-green-600'}">
                            ${traffic}
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">انقضا:</span>
                        <span class="font-semibold">${expireDate}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">تعداد دستگاه:</span>
                        <span class="font-bold">${c.count_of_devices || 1}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">کل خریدها:</span>
                        <span class="font-bold text-indigo-600">${totalPlans} پلن</span>
                    </div>
                </div>

                <div class="pt-4 border-t border-gray-200 space-y-3">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-600">نام کاربری:</span>
                        <div class="flex items-center gap-2">
                            <code class="bg-gray-100 px-3 py-1 rounded font-mono">${c.username || '—'}</code>
                            ${c.username ? `<button onclick="copyToClipboard('${c.username}')" class="copy-btn text-indigo-600"><i class="fas fa-copy"></i></button>` : ''}
                        </div>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-600">رمز عبور:</span>
                        <div class="flex items-center gap-2">
                            <code class="bg-gray-100 px-3 py-1 rounded font-mono password-field">••••••</code>
                            <button type="button" class="text-indigo-600 hover:text-indigo-800 reveal-password" 
                                    data-password="${c.password || ''}">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <button onclick="window.open('https://seller.connectix.vip/client/${c.id}', '_blank')" 
                        class="w-full mt-4 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white font-bold py-3 rounded-lg transition flex items-center justify-center gap-2">
                    مشاهده در پنل Connectix
                </button>
            </div>
        `;
    } catch (err) {
        element.innerHTML = `<div class="p-8 text-center text-red-600">خطا در دریافت اطلاعات</div>`;
        console.error('API Error for', clientId, err);
    }
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => alert('کپی شد: ' + text));
}

document.addEventListener('click', function(e) {
    const btn = e.target.closest('.reveal-password');
    if (!btn) return;

    const passwordField = btn.parentElement.querySelector('.password-field');
    const realPassword = btn.getAttribute('data-password');

    if (passwordField.textContent === '••••••') {
        passwordField.textContent = realPassword || '—';
        btn.innerHTML = '<i class="fas fa-eye-slash"></i>';
    } else {
        passwordField.textContent = '••••••';
        btn.innerHTML = '<i class="fas fa-eye"></i>';
    }
});

// بارگذاری اطلاعات همه اکانت‌ها بعد از لود صفحه
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[id^="client-"]').forEach(el => {
        const clientId = el.id.replace('client-', '');
        loadClientData(clientId, el);
    });
});
</script>
</body>
</html>