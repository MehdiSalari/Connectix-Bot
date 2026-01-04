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

$data = getWalletTransactions($page, $itemsPerPage, $search);
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
    <title><?= $appName ?> | تراکنش‌های کیف پول</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
    <link rel="icon" href="../favicon.ico" type="image/x-icon">
    <style>
        body { font-family: 'Vazirmatn', sans-serif; }
        .table-row:hover { background-color: #f8fafc; transform: translateY(-1px); }
        .increase { color: #155724; background-color: #d4edda; }
        .decrease { color: #721c24; background-color: #f8d7da; }
        .status-success { background-color: #d4edda; color: #155724; }
        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-rejected { background-color: #f8d7da; color: #721c24; }
        .status-canceled { background-color: #e2e3e5; color: #41464b; }
        .copyright { width: 100%; text-align: center; color: #777; font-size: 15px; direction: ltr; margin: 20px 0 10px; padding-bottom: 10px; }
        .copyright a { color: #b500bbff; text-decoration: none; }
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

        <!-- Wallet Transactions List -->
        <div class="bg-white rounded-xl shadow-xl overflow-hidden">
            <div class="p-6 border-b border-gray-200">
                <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                    <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-3">
                        <i class="fas fa-wallet text-indigo-600"></i>
                        تراکنش‌های کیف پول (<?= number_format($totalTransactions) ?> مورد)
                    </h2>
                    <form method="GET" class="flex gap-2 items-center">
                        <div class="relative">
                            <input type="text" name="search" value="<?= htmlspecialchars($search ?? '') ?>" placeholder="جستجو در مبلغ، نوع، وضعیت، آیدی کاربر..."
                                class="pl-12 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-4 focus:ring-indigo-200 focus:border-indigo-500 outline-none transition w-full md:w-96">
                            <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        </div>
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-lg transition">جستجو</button>
                        <?php if ($search): ?>
                            <a href="wallet_transactions.php" class="bg-gray-400 hover:bg-gray-500 text-white px-6 py-3 rounded-lg transition">پاک کردن</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gradient-to-r from-indigo-50 to-purple-50">
                        <tr>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">کاربر</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">مبلغ (تومان)</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">عملیات</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">نوع</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">وضعیت</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">تاریخ</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">عملیات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-wallet text-4xl mb-3 block opacity-50"></i>
                                هیچ تراکنش کیف پولی یافت نشد
                            </td>
                        </tr>
                        <?php else: foreach ($transactions as $t): ?>
                        <tr class="table-row transition-all duration-200">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-bold text-gray-900">
                                    <?= htmlspecialchars($t['user_name'] ?? 'نامشخص') ?>
                                    <?php if (!empty($t['user_telegram'])): ?>
                                        <br><a href="https://t.me/<?= htmlspecialchars($t['user_telegram']) ?>" target="_blank" class="text-blue-600 text-xs">@<?= htmlspecialchars($t['user_telegram']) ?></a>
                                    <?php endif; ?>
                                    <div class="text-xs text-gray-500 mt-1">آیدی: <?= $t['chat_id'] ?></div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-mono font-bold <?= $t['operation'] === 'INCREASE' ? 'text-green-600' : 'text-red-600' ?>">
                                <?= number_format($t['amount']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-3 py-1 rounded-full text-xs font-bold <?= $t['operation'] === 'INCREASE' ? 'increase' : 'decrease' ?>">
                                    <?= $t['operation'] === 'INCREASE' ? 'افزایش موجودی' : 'کاهش موجودی' ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                <?= parseWalletTransactionsType($t['type']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-3 py-1 rounded-full text-xs font-bold 
                                    <?= $t['status'] === 'SUCCESS' ? 'status-success' : 
                                       ($t['status'] === 'PENDING' ? 'status-pending' : 
                                       ($t['status'] === 'REJECTED_BY_ADMIN' || $t['status'] === 'CANCLED_BY_USER' ? 'status-rejected' : 'status-canceled')) ?>">
                                    <?= parseWalletTransactionsStatus($t['status']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?= jdate($t['created_at'], true) ?>
                            </td>
                            <td>
                                <a href="../users/user.php?id=<?= $t['user_id'] ?>"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-white font-bold transition bg-indigo-600 hover:bg-indigo-700">
                                    <i class="fas fa-user"></i> مشاهده کاربر
                                </a>
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
            $baseUrl = 'wallet_transactions.php?' . ($search ? 'search=' . urlencode($search) . '&' : '');
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
</body>
</html>