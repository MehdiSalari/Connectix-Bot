<?php
//check php version
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    die('PHP version 8.0.0 or higher is required.');
}
session_start();
if (!isset($_SESSION['admin_id']) || empty($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}
require_once '../functions.php';

$adminId = $_SESSION['admin_id'];
$admin = getAdminById($adminId);

$itemsPerPage = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$search = isset($_GET['search']) ? trim($_GET['search']) : null;

$data = getTransactions($page, $itemsPerPage, $search);
$transactions = $data['transactions'];
$totalTransactions = $data['total'];
$totalPages = max(1, (int)ceil($totalTransactions / $itemsPerPage));

$appName = json_decode(file_get_contents('../setup/bot_config.json'), true)['app_name'] ?? 'Connectix Bot';
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $appName ?> | لیست تراکنش‌ها</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
    <link rel="icon" href="../favicon.ico" type="image/x-icon">
    <style>
        body { font-family: 'Vazirmatn', sans-serif; }
        .table-row:hover { background-color: #f8fafc; transform: translateY(-1px); }
        .username { direction: ltr; text-align: right; }
        .pay-accepted { background-color: #d4edda; color: #155724; }
        .pay-rejected { background-color: #f8d7da; color: #721c24; }
        .pay-pending { background-color: #fff3cd; color: #856404; }
        .uuid { font-family: monospace; font-size: 0.85em; color: #4c1d95; }
        .modal { transition: opacity 0.3s ease; }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-7xl">

        <!-- Header -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <div class="grid md:grid-cols-2 gap-6 items-start">
                <div class="text-right">
                    <h1 class="text-3xl font-bold text-gray-800">پنل مدیریت <?= $appName ?></h1>
                    <p class="text-gray-600 mt-1">خوش آمدید، <?= htmlspecialchars($admin['email']) ?></p>
                </div>
                <div class="flex flex-col gap-5 items-end">
                    <a href="../" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg font-semibold transition flex items-center gap-2">
                        <i class="fas fa-arrow-right"></i> بازگشت
                    </a>
                </div>
            </div>
        </div>

        <!-- Transactions List -->
        <div class="bg-white rounded-xl shadow-xl overflow-hidden">
            <div class="p-6 border-b border-gray-200">
                <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                    <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-3">
                        <i class="fas fa-credit-card text-indigo-600"></i>
                        لیست تراکنش‌ها (<?= number_format($totalTransactions) ?> مورد)
                    </h2>
                    <form method="GET" class="flex gap-2 items-center">
                        <div class="relative">
                            <input type="text" name="search" value="<?= htmlspecialchars($search ?? '') ?>" placeholder="جستجو در کد سفارش، آیدی کاربر، کوپن، مبلغ..."
                                class="pl-12 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-4 focus:ring-indigo-200 focus:border-indigo-500 outline-none transition w-full md:w-96">
                            <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        </div>
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-lg transition">جستجو</button>
                        <?php if ($search): ?>
                            <a href="./" class="bg-gray-400 hover:bg-gray-500 text-white px-6 py-3 rounded-lg transition">پاک کردن</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gradient-to-r from-indigo-50 to-purple-50">
                        <tr>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">کد سفارش</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">کاربر</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">پلن ID</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">کلاینت ID</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">مبلغ (تومان)</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">کوپن</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">وضعیت</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">روش پرداخت</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">تاریخ</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">عملیات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="9" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-credit-card text-4xl mb-3 block opacity-50"></i>
                                هیچ تراکنشی یافت نشد
                            </td>
                        </tr>
                        <?php else: foreach ($transactions as $t): ?>
                        <tr class="table-row transition-all duration-200">
                            <td class="px-6 py-4 whitespace-nowrap font-mono text-sm text-gray-900">
                                <?= htmlspecialchars($t['order_number']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-bold text-gray-900">
                                    <?= htmlspecialchars($t['user_name'] ?? 'نامشخص') ?>
                                    <?php if (!empty($t['user_telegram'])): ?>
                                        <br><a href="https://t.me/<?= htmlspecialchars($t['user_telegram']) ?>" target="_blank" class="text-blue-600 text-xs">@<?= htmlspecialchars($t['user_telegram']) ?></a>
                                    <?php endif; ?>
                                    <div class="text-xs text-gray-500 mt-1">آیدی: <?= $t['chat_id'] ?></div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="uuid"><?= htmlspecialchars(substr($t['plan_id'], 0, 8)) ?>...</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="uuid"><?= htmlspecialchars(substr($t['client_id'], 0, 8)) ?>...</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-green-600 font-mono">
                                <?= $t['price'] ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?= htmlspecialchars($t['coupon'] ?: 'ندارد') ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                    <?php 
                                        switch ($t['is_paid']) {
                                            case true:
                                                $statusMsg = 'پرداخت شده';
                                                $statusStyle = 'accepted';
                                                break;
                                            case false:
                                                $statusMsg = 'رد شده';
                                                $statusStyle = 'rejected';
                                                break;
                                            case null:
                                                $statusMsg = 'در انتظار';
                                                $statusStyle = 'pending';
                                                break;
                                        }
                                    ?>
                                <span class="px-3 py-1 rounded-full text-xs font-bold pay-<?= $statusStyle ?>">
                                    <?= $statusMsg ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-3 py-1 rounded-full text-xs">
                                    <?= $t['method'] == 'wallet' ? '<i class="fas fa-wallet"></i> کیف پول' : '<i class="fas fa-credit-card"></i> کارت به کارت' ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?= jdate($t['created_at'], true) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <button onclick="showTransactionDetails(<?= $t['id'] ?>, '<?= htmlspecialchars($t['plan_id']) ?>', '<?= htmlspecialchars($t['client_id']) ?>', <?= $t['user_id'] ?>)"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-white font-bold transition bg-indigo-600 hover:bg-indigo-700">
                                    <i class="fas fa-eye"></i> جزئیات
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <?php
            $window = 2;
            $start = max(1, $page - $window);
            $end = min($totalPages, $page + $window);
            $baseUrl = 'transactions.php?' . ($search ? 'search=' . urlencode($search) . '&' : '');
            ?>
            <div class="bg-gray-50 px-6 py-4 flex justify-center">
                <div class="flex gap-2 items-center">
                    <?php if ($start > 1): ?>
                        <a href="<?= $baseUrl ?>page=1" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">اولین</a>
                        <?php if ($start > 2): ?><span class="px-4 py-2 text-gray-600">...</span><?php endif; ?>
                    <?php endif; ?>

                    <?php for ($p = $start; $p <= $end; $p++): ?>
                        <a href="<?= $baseUrl ?>page=<?= $p ?>" class="px-4 py-2 <?= $p == $page ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700' ?> rounded-lg hover:bg-indigo-700 hover:text-white transition"><?= $p ?></a>
                    <?php endfor; ?>

                    <?php if ($end < $totalPages): ?>
                        <?php if ($end < $totalPages - 1): ?><span class="px-4 py-2 text-gray-600">...</span><?php endif; ?>
                        <a href="<?= $baseUrl ?>page=<?= $totalPages ?>" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">آخرین</a>
                    <?php endif; ?>

                    <span class="px-4 py-2 text-gray-600">صفحه <?= $page ?> از <?= $totalPages ?></span>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal for displaying transaction details -->
    <div id="detailModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 modal">
        <div class="bg-white rounded-xl shadow-2xl max-w-4xl w-full mx-4 max-h-screen overflow-y-auto">
            <div class="p-6 border-b border-gray-200 flex justify-between items-center">
                <div class="flex flex-row gap-5">
                    <h3 class="text-2xl font-bold text-gray-800">جزئیات تراکنش</h3>
                    <button id="show-user"
                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-white font-bold transition bg-indigo-600 hover:bg-indigo-700">
                        <i class="fas fa-user ml-1"></i>
                        مشاهده کاربر
                    </button>
                </div>
                <button onclick="closeModal()" class="text-red-500 hover:text-red-700 text-2xl"><i class="fas fa-times-circle"></i></button>
            </div>
            <div class="p-6" id="modalContent">
                <div class="text-center py-10">
                    <i class="fas fa-spinner fa-spin text-4xl text-indigo-600"></i>
                    <p class="mt-4 text-gray-600">در حال بارگذاری اطلاعات از سرور...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showTransactionDetails(transactionId, planId, clientId, userId) {
            const modal = document.getElementById('detailModal');
            const content = document.getElementById('modalContent');
            const showUserBtn = document.getElementById('show-user');
            showUserBtn.setAttribute('onclick', `window.location.href = ('../users/user.php?id=${userId}&tab=transactions');`);

            modal.classList.remove('hidden');
            modal.classList.add('flex');

            content.innerHTML = `
                <div class="text-center py-10">
                    <i class="fas fa-spinner fa-spin text-4xl text-indigo-600"></i>
                    <p class="mt-4 text-gray-600">در حال بارگذاری اطلاعات از سرور...</p>
                </div>
            `;

            // simultaneous requests for plan and client
            Promise.all([
                fetch(`actions/get_plan_details.php?id=${planId}`).then(r => r.json()),
                fetch(`actions/get_client_details.php?id=${clientId}`).then(r => r.json())
            ]).then(([planData, clientData]) => {
                let html = `<div class="grid md:grid-cols-2 gap-8">`;

                // Plan details
                html += `<div>
                    <h4 class="text-xl font-bold text-indigo-600 mb-4 flex items-center gap-2">
                        <i class="fas fa-box"></i> اطلاعات پلن
                    </h4>`;
                if (planData && planData.title) {
                    html += `
                        <div class="space-y-3 text-sm">
                            <div><strong>عنوان:</strong> ${planData.title}</div>
                            <div><strong>حجم:</strong> ${planData.traffic_amount || 'نامحدود'} GB</div>
                            <div><strong>مدت زمان:</strong> ${planData.full_period}</div>
                            <div><strong>تعداد دستگاه:</strong> ${planData.count_of_devices}</div>
                            <div><strong>نوع:</strong> ${planData.type}</div>
                            <div><strong>قیمت فروش:</strong> ${planData.sell_price || 'رایگان'} تومان</div>
                        </div>`;
                } else {
                    html += `<p class="text-red-600">پلن یافت نشد یا خطا در دریافت اطلاعات</p>`;
                }
                html += `</div>`;

                // Client details
                html += `<div>
                    <h4 class="text-xl font-bold text-purple-600 mb-4 flex items-center gap-2">
                        <i class="fas fa-user"></i> اطلاعات اکانت
                    </h4>`;
                if (clientData && clientData.client) {
                    const c = clientData.client;
                    html += `
                        <div class="space-y-3 text-sm">
                            <div><strong>نام:</strong> ${c.name || 'ندارد'}</div>
                            <div><strong>یوزرنیم:</strong> ${c.username || 'ندارد'}</div>
                            <div><strong>ایمیل:</strong> ${c.email || 'ندارد'}</div>
                            <div><strong>رمز عبور:</strong> <code class="bg-gray-200 px-2 py-1 rounded">${c.password || 'ندارد'}</code></div>
                            <div><strong>تاریخ انقضا:</strong> ${c.expire_date || 'نامشخص'}</div>
                            <div><strong>تعداد دستگاه مجاز:</strong> ${c.count_of_devices}</div>
                            <div><strong>ترافیک مصرفی:</strong> ${c.plans?.[0]?.total_used_traffic || 'نامشخص'}</div>
                        </div>`;
                } else {
                    html += `<p class="text-red-600">اکانت یافت نشد یا خطا در دریافت اطلاعات</p>`;
                }
                html += `</div></div>`;

                content.innerHTML = html;
            }).catch(err => {
                content.innerHTML = `<p class="text-red-600 text-center">خطا در ارتباط با سرور: ${err.message}</p>`;
            });
        }

        function closeModal() {
            document.getElementById('detailModal').classList.add('hidden');
        }

        // Close modal with outside click
        document.getElementById('detailModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
    </script>
</body>
</html>