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

// Get user list with pagination (use single DB connection)
$itemsPerPage = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = max(0, ($page - 1) * $itemsPerPage);

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch total users (for pagination)
$countResult = $conn->query("SELECT COUNT(id) AS total FROM users");
if ($countResult) {
    $countRow = $countResult->fetch_assoc();
    $totalUsers = isset($countRow['total']) ? (int)$countRow['total'] : 0;
    $countResult->free();
} else {
    $totalUsers = 0;
}

// Fetch page rows
$users = [];
$stmt = $conn->prepare("SELECT * FROM users ORDER BY id DESC LIMIT ?, ?");
if ($stmt) {
    $stmt->bind_param("ii", $offset, $itemsPerPage);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        $users = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }
    $stmt->close();
}

$conn->close();

$totalPages = max(1, (int)ceil($totalUsers / $itemsPerPage));
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پنل مدیریت | لیست کاربران</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
    <link rel="icon" href="../favicon.ico" type="image/x-icon">
    <style>
        html, body { height: 100%; }
        html {background-color: #e7edff;}
        body { font-family: 'Vazirmatn', sans-serif; display: flex; flex-direction: column; min-height: 100vh; }
        body > .container { flex: 1 0 auto; }
        .table-row:hover { background-color: #f8fafc; transform: translateY(-1px); }
        .username {
            direction: ltr;
            text-align: right;
        }
        .copyright {
                width: 100%;
                text-align: center;
                color: #777;
                font-size: 15px;
                direction: ltr;
                margin: 20px 0 10px;
                padding-bottom: 10px;
                flex-shrink: 0;
            }
        .copyright a { color: #b500bbff; text-decoration: none; }
    </style>
</head>

<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-6xl">

        <!-- Header Section -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <div class="grid md:grid-cols-2 gap-6 items-start">
                <div class="text-right">
                    <h1 class="text-3xl font-bold text-gray-800">پنل مدیریت Connectix Bot</h1>
                    <p class="text-gray-600 mt-1">خوش آمدید، <?= htmlspecialchars($admin['email']) ?></p>
                </div>

                <div class="flex flex-col gap-5 items-end">
                    <a href="../" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg font-semibold transition flex items-center gap-2 w-fit">
                        <i class="fas fa-arrow-right"></i> بازگشت
                    </a>

                    <div class="flex flex-wrap gap-3 justify-end w-full">
                        
                    </div>
                </div>
            </div>
        </div>

        <!-- Users list Section -->
        <div class="bg-white rounded-xl shadow-xl overflow-hidden">
            <div class="p-6 border-b border-gray-200">
                <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                    <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-3">
                        <i class="fas fa-users text-indigo-600"></i>
                        لیست کاربران (<?= number_format($totalUsers) ?> نفر)
                    </h2>
                    <div class="relative">
                        <input type="text" id="searchInput" placeholder="جستجو در نام، آیدی یا نام کاربری..."
                            class="pl-12 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-4 focus:ring-indigo-200 focus:border-indigo-500 outline-none transition w-full md:w-96">
                        <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gradient-to-r from-indigo-50 to-purple-50">
                        <tr>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">نام</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">یوزرنیم</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">آیدی عددی</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">تاریخ ثبت</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">عملیات</th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody" class="bg-white divide-y divide-gray-200">
                        <?php
                        foreach ($users as $user) {
                        ?>
                        <tr class="table-row transition-all duration-200">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="ml-4">
                                        <div class="text-sm font-bold text-gray-900"><?= htmlspecialchars($user['name']  ?? '') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap username">
                                <?= $user['telegram_id'] ? '<a href="https://t.me/' . $user['telegram_id'] . '" target="_blank"><span class="text-blue-600">@' . htmlspecialchars($user['telegram_id']) . '</span></a>' : '<span class="text-gray-400">ندارد</span>' ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 font-mono"><?= $user['chat_id'] ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?= jdate($user['created_at'], true) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                <button onclick="viewUser(<?= $user['id'] ?>)"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-white font-bold transition bg-indigo-600 hover:bg-indigo-700">
                                    <i class="fas fa-eye"></i>
                                    مشاهده کاربر
                                </button>
                            </td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php
            $currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            // show a window of pages around current
            $window = 2; // pages on each side
            $start = max(1, $currentPage - $window);
            $end = min($totalPages, $currentPage + $window);
            ?>
            <div class="bg-gray-50 px-6 py-4 flex justify-center">
                <div class="flex gap-2 items-center">
                    <?php if ($start > 1) : ?>
                        <a href="?page=1" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">اولین</a>
                        <?php if ($start > 2) : ?>
                            <span class="px-4 py-2 text-gray-600">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($p = $start; $p <= $end; $p++) : ?>
                        <?php if ($p == $currentPage) : ?>
                            <a href="?page=<?= $p ?>" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition"><?= $p ?></a>
                        <?php else : ?>
                            <a href="?page=<?= $p ?>" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition"><?= $p ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($end < $totalPages) : ?>
                        <?php if ($end < $totalPages - 1) : ?>
                            <span class="px-4 py-2 text-gray-600">...</span>
                        <?php endif; ?>
                        <a href="?page=<?= $totalPages ?>" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">آخرین</a>
                    <?php endif; ?>

                    <span class="px-4 py-2 text-gray-600">صفحه <?= $currentPage ?> از <?= $totalPages ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="copyright">
        <p>&copy; 2024 - <?= date('Y') ?> Connectix Bot designed by <a href="https://github.com/MehdiSalari" target="_blank">Mehdi Salari</a>. All rights reserved.</p>
    </div>

    <script>
        let searchTimeout;
        let allUsers = [];
        let isSearching = false;

        // Initialize with current page users
        document.addEventListener('DOMContentLoaded', function() {
            const rows = document.querySelectorAll('#usersTableBody tr');
            rows.forEach(row => {
                const userId = row.querySelector('button[onclick*="viewUser"]').onclick.toString().match(/\d+/)[0];
                const nameCell = row.querySelector('td:first-child');
                const telegramCell = row.querySelector('td:nth-child(2)');
                const chatIdCell = row.querySelector('td:nth-child(3)');
                
                allUsers.push({
                    id: parseInt(userId),
                    name: nameCell.textContent.trim(),
                    telegram_id: telegramCell.textContent.trim(),
                    chat_id: chatIdCell.textContent.trim()
                });
            });
        });

        // جستجوی زنده - Global search
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const query = this.value.trim();
            
            // Clear previous timeout
            clearTimeout(searchTimeout);

            // If empty, show current page users only
            if (query.length === 0) {
                const rows = document.querySelectorAll('#usersTableBody tr');
                rows.forEach(row => row.style.display = '');
                return;
            }

            // Show loading state
            const searchInput = document.getElementById('searchInput');
            const originalPlaceholder = searchInput.placeholder;
            searchInput.style.opacity = '0.7';

            // Debounce the search (300ms delay)
            searchTimeout = setTimeout(() => {
                performGlobalSearch(query);
            }, 300);
        });

        // Perform global search across all users
        function performGlobalSearch(query) {
            if (query.length < 1) return;

            isSearching = true;
            const searchInput = document.getElementById('searchInput');
            
            fetch(`search.php?q=${encodeURIComponent(query)}&limit=100`)
                .then(response => {
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);
                    return response.text().then(text => {
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            console.error('Invalid JSON response:', text.substring(0, 200));
                            throw new Error('Invalid server response');
                        }
                    });
                })
                .then(data => {
                    if (data.error) {
                        showNotification('خطا: ' + data.error, 'error');
                        return;
                    }
                    displaySearchResults(data.results || []);
                    updateSearchInfo(data);
                })
                .catch(error => {
                    console.error('Search error:', error);
                    showNotification('خطا در جستجو: ' + error.message, 'error');
                })
                .finally(() => {
                    isSearching = false;
                    searchInput.style.opacity = '1';
                });
        }

        // Display search results in table
        function displaySearchResults(results) {
            const tbody = document.getElementById('usersTableBody');
            
            if (results.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                            <i class="fas fa-search text-3xl mb-2 block opacity-50"></i>
                            نتیجه‌ای یافت نشد
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = results.map(user => `
                <tr class="table-row transition-all duration-200">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <div class="ml-4">
                                <div class="text-sm font-bold text-gray-900">${user.name || '-'}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap username">
                        ${user.telegram_id ? `<a href="https://t.me/${user.telegram_id}" target="_blank" class="text-blue-600 hover:underline">@${user.telegram_id}</a>` : '<span class="text-gray-400">ندارد</span>'}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 font-mono">${user.chat_id}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                        ${formatDate(user.created_at)}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                        <button onclick="viewUser(${user.id})"
                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-white font-bold transition bg-indigo-600 hover:bg-indigo-700">
                            <i class="fas fa-eye"></i>
                            مشاهده کاربر
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        // Update search info text
        function updateSearchInfo(data) {
            const header = document.querySelector('h2');
            if (data.query) {
                header.innerHTML = `
                    <i class="fas fa-search text-indigo-600"></i>
                    <span>نتایج جستجو برای "<strong>${data.query}</strong>" (${data.displayed} از ${data.total})</span>
                    <button onclick="clearSearch()" class="ml-4 px-3 py-1 bg-gray-300 hover:bg-gray-400 text-gray-700 rounded-lg text-xs font-semibold transition">
                        پاک کردن
                    </button>
                `;
            }
        }

        // Clear search and return to pagination
        function clearSearch() {
            document.getElementById('searchInput').value = '';
            location.reload();
        }

        // Format date (Persian format)
        function formatDate(dateStr) {
            const date = new Date(dateStr);
            const options = { 
                year: 'numeric', 
                month: '2-digit', 
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            };
            return date.toLocaleDateString('fa-IR', options);
        }

        // Show notification
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 px-6 py-3 rounded-lg text-white font-semibold shadow-lg ${
                type === 'error' ? 'bg-red-500' : 'bg-green-500'
            }`;
            notification.textContent = message;
            document.body.appendChild(notification);

            setTimeout(() => notification.remove(), 3000);
        }

        // View user
        function viewUser(userId) {
            window.location.href = 'user.php?id=' + userId;
        }
    </script>
</body>
</html>