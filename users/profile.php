<?php
require_once '../functions.php';
session_start();
$config = json_decode(file_get_contents('../setup/bot_config.json'), true);
$appName = $config['app_name'] ?? 'Connectix Bot';
$adminID = $config['admin_id'] ?? null;

if (!isset($_GET['userID'])) {
    header("Location: ../index.php");
    exit;
}

$imgUrl = $_GET['userPic'];
$user = getUser($_GET['userID']);
$userId = $user['id'];
$userChatId = $user['chat_id'];

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($userChatId == $adminID && isset($_SESSION['admin_id'])) {
    header("Location: user.php?id=$userId");
    exit;
}

$clients = [];
$stmt = $conn->prepare("SELECT id, username, password, count_of_devices, created_at FROM clients WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $clients[] = $row;
}
$stmt->close();

$walletData = wallet('get', $user['chat_id']);

if (isset($_GET['create_wallet']) && $_GET['create_wallet'] == true && $walletData == null) {
    $createWallet = wallet('create', $userChatId, 0);
    if ($createWallet) {
        $walletData = wallet('get', $userChatId);
    }
}

?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $appName ?> | View <?= htmlspecialchars($user['name'] ?? 'کاربر') ?></title>
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
        #profileLightbox {animation: fadeIn 0.3s ease-out; display: flex; justify-content: center; align-items: center;}
        #profileLightbox.hidden {display: none;}
        #lightboxImage {animation: zoomIn 0.4s ease-out;max-width: 90vw;max-height: 90vh;object-fit: contain;}
        @keyframes fadeIn {from { opacity: 0; }to { opacity: 1; }}
        @keyframes zoomIn {from { transform: scale(0.8); opacity: 0; }to { transform: scale(1); opacity: 1; }}
        
        /* Mobile Width */
        @media (max-width: 768px) {
            .operation { width: 100vw; }
            .type { width: 100vw; text-align: center; }
            .status { text-align: left; }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-7xl">

        <!-- User Details and Wallet -->
        <div class="bg-white rounded-xl shadow-xl p-8 mb-8">
            <div class="flex flex-col md:flex-row items-center justify-between gap-8">
                <!-- Avatar and Primary Information -->
                <div class="flex flex-col md:flex-row items-center gap-8 flex-1">
                    <div class="w-28 h-28 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-full flex items-center justify-center text-white text-5xl font-bold shadow-xl">
                        <img id="avatar" class="w-28 h-28 rounded-full" src="<?= $imgUrl ?>" alt="<?= $user['name'] ?>">
                    </div>
                    <div class="text-center md:text-right">
                        <h2 class="text-3xl font-bold text-gray-800"><?=$user['name'] ?? 'نامشخص' ?></h2>
                        <div class="mt-3 space-y-2 text-gray-600">
                            <p>آیدی عددی: <code class="bg-gray-100 px-3 py-1 rounded font-mono"><?= $user['chat_id'] ?></code></p>
                            <?php if ($user['telegram_id']): ?>
                                <p>یوزرنیم: <a href="https://t.me/<?= $user['telegram_id'] ?>" target="_blank" class="text-blue-600 hover:underline">@<?= htmlspecialchars($user['telegram_id']) ?></a></p>
                            <?php endif; ?>
                            <p class="text-sm">ثبت‌نام: <?= jdate($user['created_at'], true) ?></p>
                        </div>
                    </div>
                </div>

                <!-- Wallet Section -->
                <div class="text-center md:text-right">
                    <?php if ($walletData): ?>
                    <p class="text-lg text-gray-600 mb-2">موجودی کیف پول</p>
                    <p class="text-4xl font-bold text-indigo-600 mb-4">
                        <?= number_format($walletData['balance'] ?? 0) ?> تومان
                    </p>
                    <button onclick="openWalletModal()" class="bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-semibold py-3 px-6 rounded-xl shadow-lg transition transform hover:scale-105">
                        مشاهده کیف پول
                    </button>
                    <?php else: ?>
                    <p class="text-lg text-gray-600 mb-2">کیف پول کاربر</p>
                    <p class="text-4xl font-bold text-gray-600 mb-4">بدون کیف پول</p>
                    <button onclick="createWallet()" class="bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-semibold py-3 px-6 rounded-xl shadow-lg transition transform hover:scale-105">
                        ایجاد کیف پول
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Wallet Modal -->
        <div id="walletModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50 overflow-y-auto">
            <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 my-8 max-h-screen overflow-y-auto" style="max-height: 90vh">
                <div class="p-8 relative">
                    <div>
                        <button onclick="closeWalletModal()" class="absolute top-4 left-4 text-red-500 hover:text-red-700 text-3xl z-10">
                            <i class="fas fa-times-circle"></i>
                        </button>
                        <h3 class="text-2xl font-bold text-gray-800 mb-6 text-center">کیف پول</h3>
                    </div>
                    
                    <div class="text-center mb-8 bg-gradient-to-r from-indigo-50 to-purple-50 rounded-xl p-6">
                        <p class="text-gray-700">کاربر: <strong><?= htmlspecialchars($user['name'] ?? 'نامشخص') ?></strong></p>
                        <p class="text-gray-700">آیدی عددی: <strong><?= htmlspecialchars($user['chat_id'] ?? 'نامشخص') ?></strong></p>
                        <p class="text-3xl font-bold text-indigo-600 mt-4">
                            موجودی فعلی: <?= number_format($walletData['balance'] ?? 0) ?> تومان
                        </p>
                    </div>

                    <!-- Transaction History -->
                    <div class="border-t-2 border-gray-200 pt-8">
                        <h4 class="text-xl font-bold text-gray-800 mb-6 text-center">تاریخچه تراکنش‌ها</h4>
                        <?php 
                        $transactions = wallet('transactions', $user['chat_id']) ?? [];
                        ?>
                        <?php if (empty($transactions)): ?>
                            <p class="text-center text-gray-500 py-8">هیچ تراکنشی ثبت نشده است.</p>
                        <?php else: ?>
                            <div class="space-y-4 max-h-96 overflow-y-auto">
                                <?php foreach ($transactions as $tx): ?>
                                    <div class="flex items-center justify-between p-4 rounded-xl <?= $tx['operation'] === 'INCREASE' ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200' ?>">
                                        <div class="operation">
                                            <p class="font-semibold <?= $tx['operation'] === 'INCREASE' ? 'text-green-700' : 'text-red-700' ?>">
                                                <?= $tx['operation'] === 'INCREASE' ? '+' : '-' ?> <?= number_format($tx['amount']) ?> تومان
                                            </p>
                                            <p class="text-sm text-gray-600 mt-1">
                                                <?php
                                                $time = explode(' ', $tx['created_at'])[1];
                                                $time = explode(':', $time)[0] . ':' . explode(':', $time)[1];
                                                    echo jdate($tx['created_at'], true) . ' ' . $time;
                                                ?>
                                            </p>
                                        </div>
                                        <div class="type">
                                            <div class="text-sm font-medium text-gray-600">
                                                <?= parseWalletTransactionsType($tx['type']) ?>
                                            </div>
                                        </div>
                                        <div class="status text-sm font-medium <?= $tx['status'] === 'SUCCESS' ? 'text-green-600' : 'text-red-600' ?>">
                                            <?= parseWalletTransactionsStatus($tx['status']) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        
        <!-- Profile Lightbox -->
        <div id="profileLightbox" class="fixed inset-0 hidden items-center justify-center z-50">
            <div class="absolute inset-0 bg-black bg-opacity-50" onclick="closeProfileLightbox()"></div>
            <img id="lightboxImage" class="relative max-w-full max-h-full rounded-2xl shadow-2xl z-10" src="" alt="<?= $user['name'] ?>">
        </div>

        <!-- Clients (Connected Accounts) -->
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
                                <div class="pt-4 border-t border-gray-200 space-y-3 loading">
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-gray-600">نام کاربری:</span>
                                        <div class="flex items-center gap-2">
                                            <code class="bg-gray-100 px-3 py-1 rounded font-mono animate-pulse">...</code>
                                        </div>
                                    </div>
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-gray-600">رمز عبور:</span>
                                        <div class="flex items-center gap-2">
                                            <code class="bg-gray-100 px-3 py-1 rounded font-mono animate-pulse">...</code>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
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
                        ${c.subscription_link ? `
                        <div class="flex flex-col items-center justify-between text-sm">
                            <span class="text-gray-600 mb-2 text-right w-full">لینک سابیکریپشن:</span>
                            <div class="flex items-center gap-2">
                                <code dir="ltr" class="bg-gray-100 px-3 py-1 rounded font-mono text-xs">${c.subscription_link || '—'}</code>
                                ${c.subscription_link ? `<button onclick="copyToClipboard('${c.subscription_link}')" class="copy-btn text-indigo-600 text-xs"><i class="fas fa-copy"></i></button>` : ''}
                            </div>
                        </div>` : ''}

                    </div>
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

    // Load all account data after page load
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[id^="client-"]').forEach(el => {
            const clientId = el.id.replace('client-', '');
            loadClientData(clientId, el);
        });
    });

    function openWalletModal() {
        document.getElementById('walletModal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeWalletModal() {
        document.getElementById('walletModal').classList.add('hidden');
        document.body.style.overflow = 'auto';
    }

    // Close the modal with a click outside of it
    document.getElementById('walletModal').addEventListener('click', function(e) {
        if (e.target === this) closeWalletModal();
    });
        
    function openProfileLightbox() {
        const lightbox = document.getElementById('profileLightbox');
        const lightboxImg = document.getElementById('lightboxImage');
        
        lightboxImg.src = '<?= $user['avatar'] ?>';
        lightbox.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeProfileLightbox() {
        document.getElementById('profileLightbox').classList.add('hidden');
        document.body.style.overflow = 'auto';
    }

    // Close the lightbox with a click outside of it
    document.getElementById('profileLightbox').addEventListener('click', function(e) {
        if (e.target === this || e.target.id === 'lightboxImage') {
            closeProfileLightbox();
        }
    });

    // Close with Escape key press
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeProfileLightbox();
        }
    });

    // Open the lightbox with a click on the avatar
    const avatar = document.getElementById('avatar');
    if (avatar) {
        avatar.addEventListener('click', function(e) {
            e.stopPropagation();
            if ('<?= $user['avatar'] ?>') {
                openProfileLightbox();
            }
        });
    }


    function createWallet() {
        window.location.href = window.location.href + '&create_wallet=true';
    }

    setTimeout(() => {
        const url = new URL(window.location);
        const userID = url.searchParams.get('userID');
        const userPic = url.searchParams.get('userPic');

        url.search = '';
        if (userID) {
            url.searchParams.set('userID', userID);
        }
        if (userPic) {
            url.searchParams.set('userPic', userPic);
        }

        window.history.replaceState({}, document.title, url.toString());
    }, 1000);
    </script>
</body>
</html>