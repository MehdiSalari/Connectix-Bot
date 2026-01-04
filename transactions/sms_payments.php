<?php
session_start();
if (!isset($_SESSION['admin_id']) || empty($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}
require_once '../functions.php';

$adminId = $_SESSION['admin_id'];
$admin = getAdminById($adminId);

// Load Banks
$banksJson = @file_get_contents('../bank/banks.json');
$banksData = $banksJson ? json_decode($banksJson, true) : ['banks' => []];
$banks = [];
foreach ($banksData['banks'] ?? [] as $b) {
    $banks[$b['name']] = $b['title'];
}

// Pagination and Search
$itemsPerPage = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$offset = ($page - 1) * $itemsPerPage;

// Connect to the database
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

// Query with filters
$where = "WHERE (payment_id IS NOT NULL AND payment_id != '' OR expired_at > NOW())";
$params = [];
$types = '';

if ($search !== '') {
    $where .= " AND (amount LIKE ? OR bank LIKE ? OR payment_type LIKE ?)";
    $searchLike = "%" . $conn->real_escape_string($search) . "%";
    $params = [$searchLike, $searchLike, $searchLike];
    $types = 'sss';
}

// Count all payments
$countQuery = "SELECT COUNT(*) as total FROM sms_payments $where";
$stmt = $conn->prepare($countQuery);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$totalPayments = $stmt->get_result()->fetch_assoc()['total'];
$totalPages = max(1, ceil($totalPayments / $itemsPerPage));

// Get Data
$query = "SELECT * FROM sms_payments $where ORDER BY created_at DESC LIMIT ?, ?";
$types .= 'ii';
$params[] = $offset;
$params[] = $itemsPerPage;

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$payments = [];
while ($row = $result->fetch_assoc()) {
    $payments[] = $row;
}
$stmt->close();
$conn->close();

$appName = json_decode(file_get_contents('../setup/bot_config.json'), true)['app_name'] ?? 'Connectix Bot';
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $appName ?> | پیام‌های واریز</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
    <link rel="icon" href="../favicon.ico" type="image/x-icon">
    <style>
        body { font-family: 'Vazirmatn', sans-serif; }
        .table-row:hover { background-color: #f8fafc; transform: translateY(-1px); }
        .modal-content { max-height: 80vh; overflow-y: auto; }
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

        <!-- List of Variance Payments -->
        <div class="bg-white rounded-xl shadow-xl overflow-hidden">
            <div class="p-6 border-b border-gray-200">
                <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                    <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-3">
                        <i class="fas fa-sms text-indigo-600"></i>
                        پیام‌های واریز (<?= number_format($totalPayments) ?> مورد)
                    </h2>
                    <form method="GET" class="flex gap-2 items-center">
                        <div class="relative">
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="جستجو در مبلغ، بانک، نوع..."
                                class="pl-12 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-4 focus:ring-indigo-200 focus:border-indigo-500 outline-none transition w-full md:w-96">
                            <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        </div>
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-lg transition">جستجو</button>
                        <?php if ($search): ?>
                            <a href="sms_payments.php" class="bg-gray-400 hover:bg-gray-500 text-white px-6 py-3 rounded-lg transition">پاک کردن</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gradient-to-r from-indigo-50 to-purple-50">
                        <tr>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">مبلغ (تومان)</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">بانک</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">نوع پرداخت</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">تاریخ دریافت</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">وضعیت</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">عملیات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($payments)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-sms text-4xl mb-3 block opacity-50"></i>
                                هیچ پیام واریزی یافت نشد
                            </td>
                        </tr>
                        <?php else: foreach ($payments as $p):
                            $isPending = empty($p['payment_id']);
                            $status = $isPending ? 'در انتظار' : 'تأیید شده';
                            $bankName = $banks[$p['bank']] ?? ($p['bank'] ?: 'نامشخص');
                            $paymentType = $p['payment_type'] ?? null;

                            if (!function_exists('getPaymentTime')) {
                                function getPaymentTime($dateTime) {
                                    $time = explode(' ', $dateTime)[1];
                                    $time = explode(':', $time)[0] . ':' . explode(':', $time)[1];
                                    return $time;
                                }
                            }
                        ?>
                        <tr class="table-row transition-all duration-200">
                            <td class="px-6 py-4 whitespace-nowrap font-mono text-lg font-bold text-green-600">
                                <?= number_format($p['amount']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                <?= htmlspecialchars($bankName) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-3 py-1 rounded-full text-xs font-bold
                                    <?= $p['payment_type'] === 'buy'
                                        ? 'bg-blue-100 text-blue-800'
                                        : (!empty($p['payment_type'])
                                            ? 'bg-purple-100 text-purple-800'
                                            : 'bg-yellow-100 text-yellow-800') ?>">
                                    <?= $p['payment_type'] === 'buy'
                                        ? 'خرید اشتراک'
                                        : (!empty($p['payment_type']) ? 'شارژ کیف پول' : 'نامشخص') ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?= jdate($p['created_at'], true) . "-" . getPaymentTime($p['created_at'])
                                ?>
                                <?php if ($isPending): ?>
                                    <br><small class="text-orange-600">منقضی در: <?= getPaymentTime($p['expired_at']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-3 py-1 rounded-full text-xs font-bold <?= $isPending ? "bg-yellow-100 text-yellow-800" : "bg-green-100 text-green-800" ?>">
                                    <?= $status ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <?php if (!$isPending && !empty($p['payment_id'])): ?>
                                    <button onclick="viewTransaction(<?= $p['id'] ?>, '<?= $p['payment_type'] ?>', '<?= $p['payment_id'] ?>')"
                                        class="inline-flex items-center gap-1 px-4 py-2 rounded-lg text-white font-bold transition bg-indigo-600 hover:bg-indigo-700">
                                        <i class="fas fa-eye"></i> مشاهده
                                    </button>
                                <?php else: ?>
                                    <span class="text-gray-500 text-sm">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1):
                $window = 2;
                $start = max(1, $page - $window);
                $end = min($totalPages, $page + $window);
                $baseUrl = 'sms_payments.php?' . ($search ? 'search=' . urlencode($search) . '&' : '');
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

    <!-- Transaction Detail Modal -->
    <div id="transactionModal" class="fixed inset-0 bg-black bg-opacity-60 hidden flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl modal-content">
            <div class="p-6 rounded-t-2xl flex justify-between items-center border-b border-gray-200">
                <h3 class="text-2xl font-bold">جزئیات تراکنش واریز</h3>
                <button onclick="closeTransactionModal()" class="text-red-500 hover:text-red-700 text-2xl">
                    <i class="fas fa-times-circle"></i>
                </button>
            </div>
            <div class="p-8" id="transactionContent">
                <div class="text-center py-12">
                    <i class="fas fa-spinner fa-spin text-5xl text-indigo-600 mb-4"></i>
                    <p class="text-xl text-gray-600">در حال بارگذاری جزئیات...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        async function viewTransaction(smsId, type, transactionId) {
            const modal = document.getElementById('transactionModal');
            const content = document.getElementById('transactionContent');
            modal.classList.remove('hidden');
            content.innerHTML = `
                <div class="text-center py-12">
                    <i class="fas fa-spinner fa-spin text-5xl text-indigo-600 mb-4"></i>
                    <p class="text-xl text-gray-600">در حال بارگذاری جزئیات...</p>
                </div>
            `;

            const typeName = type === 'wallet' ? 'شارژ کیف پول' : 'خرید اشتراک';

            // adding timestamp to prevent caching
            const cacheBuster = Date.now();

            // get sms message text
            const smsRes = await fetch(`actions/get_sms_message.php?id=${smsId}&_=${cacheBuster}`);
            const smsData = await smsRes.json();

            // get main transaction data
            let transactionUrl = type === 'wallet'
                ? `actions/get_wallet_transaction.php?id=${transactionId}&_=${cacheBuster}`
                : `actions/get_payment_transaction.php?id=${transactionId}&_=${cacheBuster}`;

            fetch(transactionUrl)
                .then(r => r.json())
                .then(mainData => {
                    if (mainData.error) throw new Error(mainData.error);

                    let html = `<div class="grid lg:grid-cols-3 gap-4 mb-4">`;

                    // General information
                    html += `<div class="bg-gradient-to-br from-blue-50 to-indigo-100 rounded-xl p-6">
                        <h4 class="text-xl font-bold text-indigo-700 mb-4 flex items-center gap-2">
                            <i class="fas fa-info-circle"></i> اطلاعات واریز
                        </h4>
                        <div class="space-y-3 text-lg">
                            <div><strong>مبلغ:</strong> <span class="text-green-600 font-bold">${(mainData.amount || mainData.price || 0).toLocaleString()} تومان</span></div>
                            <div><strong>وضعیت:</strong> <span class="px-3 py-1 rounded-full text-xs font-bold bg-green-100 text-green-800">تأیید شده</span></div>
                            <div><strong>تاریخ:</strong> ${mainData.created_at || 'نامشخص'}</div>
                            <div><strong>نوع پرداخت:</strong> ${typeName}</div>
                        </div>
                    </div>`;

                    // User information
                    html += `<div class="bg-gradient-to-br from-purple-50 to-pink-100 rounded-xl p-6">
                        <h4 class="text-xl font-bold text-purple-700 mb-4 flex items-center gap-2">
                            <i class="fas fa-user"></i> اطلاعات کاربر
                        </h4>
                        <div class="space-y-3 text-lg">
                            <div><strong>نام:</strong> ${mainData.user_name || 'نامشخص'}</div>
                            <div><strong>چت آیدی:</strong> <code class="bg-white px-2 py-1 rounded">${mainData.chat_id || '—'}</code></div>
                            ${mainData.user_telegram ? `<div><strong>یوزرنیم:</strong> <a href="https://t.me/${mainData.user_telegram}" target="_blank" class="text-blue-600 hover:underline">@${mainData.user_telegram}</a></div>` : ''}
                            ${mainData.user_id ? `<a href="../users/user.php?id=${mainData.user_id}&tab=sms" class="inline-flex items-center gap-1 px-4 py-2 mt-4 rounded-lg text-white font-bold transition bg-indigo-600 hover:bg-indigo-700">
                                <i class="fas fa-user ml-1"></i> مشاهده کاربر
                            </a>` : ''}
                        </div>
                    </div>`;

                    // SMS message
                    html += `<div class="bg-gradient-to-br from-yellow-50 to-amber-100 rounded-xl p-6">
                        <h4 class="text-xl font-bold text-yellow-700 mb-4 flex items-center gap-2">
                            <i class="fas fa-comment-dots"></i> متن پیام واریز
                        </h4>
                        <div class="space-y-3 text-lg">
                            <div style="font-size: 14px; line-height: normal;">
                            ${smsData.message ? smsData.message.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>') : '<em class="text-gray-500">پیامی ثبت نشده</em>'}
                            </div>
                        </div>
                    </div>`;

                    // Plan information (if sms for buying plan)
                    if (type === 'buy' && mainData.plan_id) {
                        const planUrl = `actions/get_plan_details.php?id=${mainData.plan_id}&_=${cacheBuster}`;
                        const clientUrl = `actions/get_client_details.php?id=${mainData.client_id}&_=${cacheBuster}`;

                        Promise.all([
                            fetch(planUrl).then(r => r.json()),
                            fetch(clientUrl).then(r => r.json())
                        ]).then(([planData, clientData]) => {
                            html += `<div class="bg-gradient-to-br from-green-50 to-emerald-100 rounded-xl p-6 lg:col-span-3">
                                <h4 class="text-xl font-bold text-green-700 mb-6 text-center">جزئیات خرید اشتراک</h4>
                                <div class="grid md:grid-cols-2 gap-8">`;

                            if (planData && !planData.error) {
                                html += `<div>
                                    <h5 class="font-bold text-green-800 mb-3">پلن خریداری شده</h5>
                                    <div class="space-y-2 text-base">
                                        <div><strong>عنوان:</strong> ${planData.title || '—'}</div>
                                        <div><strong>حجم:</strong> ${planData.traffic_amount ? planData.traffic_amount + ' GB' : 'نامحدود'}</div>
                                        <div><strong>مدت:</strong> ${planData.full_period || '—'}</div>
                                        <div><strong>دستگاه:</strong> ${planData.count_of_devices || '—'}</div>
                                        <div><strong>قیمت:</strong> ${planData.sell_price ? planData.sell_price.toLocaleString() + ' تومان' : 'رایگان'}</div>
                                    </div>
                                </div>`;
                            }

                            if (clientData && clientData.client) {
                                const c = clientData.client;
                                html += `<div>
                                    <h5 class="font-bold text-green-800 mb-3">اکانت فعال شده</h5>
                                    <div class="space-y-2 text-base">
                                        <div><strong>نام:</strong> ${c.name || '—'}</div>
                                        <div><strong>یوزرنیم:</strong> ${c.username || '—'}</div>
                                        <div><strong>پسورد:</strong> <code class="bg-white px-2 py-1 rounded">${c.password || '—'}</code></div>
                                        <div><strong>انقضا:</strong> ${c.expire_date || '—'}</div>
                                        <div><strong>ترافیک مصرفی:</strong> ${c.plans?.[0]?.total_used_traffic || '—'}</div>
                                    </div>
                                </div>`;
                            }

                            html += `</div></div>`;
                            content.innerHTML = html;
                        }).catch(() => {
                            content.innerHTML = html + `<div class="text-center text-red-600 py-8 lg:col-span-3">خطا در بارگذاری جزئیات پلن یا اکانت</div>`;
                        });
                    } else {
                        content.innerHTML = html + `</div>`;
                    }
                })
                .catch(err => {
                    content.innerHTML = `<div class="text-center py-12 text-red-600 text-xl">خطا: ${err.message}</div>`;
                });
        }

        function closeTransactionModal() {
            document.getElementById('transactionModal').classList.add('hidden');
        }

        document.getElementById('transactionModal').addEventListener('click', function(e) {
            if (e.target === this) closeTransactionModal();
        });
    </script>
</body>
</html>