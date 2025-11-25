<?php
if (!file_exists('config.php')) {
    header('Location: setup');
    exit();
}
require_once 'config.php';
session_start();

// === چک‌های امنیتی ===
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
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پنل مدیریت | ارسال پیام همگانی</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@100..900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Vazirmatn', sans-serif;
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
            position: fixed;
            left: 0;
            bottom: 0;
            width: 100%;
            text-align: center;
            color: #777;
            font-size: 15px;
            direction: ltr;
            margin-bottom: 10px;
        }
        .copyright a {
            color: #b500bbff;
            text-decoration: none;
        }
    </style>
</head>

<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <!-- هدر -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8 flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold text-gray-800">پنل مدیریت Connectix Bot</h1>
                <p class="text-gray-600 mt-1">خوش آمدید، <?= htmlspecialchars($admin['email']) ?></p>
            </div>
            <div class="flex items-center gap-4">
                <a href="setup"
                    class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg font-semibold transition flex items-center gap-2">
                    <i class="fas fa-cog"></i> تنظیمات اولیه
                </a>
                <a href="logout.php"
                    class="bg-red-500 hover:bg-red-600 text-white px-6 py-3 rounded-lg font-semibold transition flex items-center gap-2">
                    <i class="fas fa-sign-out-alt"></i> خروج
                </a>
            </div>
        </div>

        <!-- فرم ارسال پیام -->
        <div class="bg-white rounded-xl shadow-xl p-8 mb-8">
            <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center gap-3">
                <i class="fas fa-paper-plane text-blue-600"></i>
                ارسال پیام همگانی به <?= number_format($totalUsers) ?> کاربر
            </h2>

            <form id="broadcastForm" enctype="multipart/form-data" class="space-y-6">
                <!-- آپلود فایل -->
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

                <!-- متن پیام -->
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">متن پیام (کپشن):</label>
                    <textarea id="message" name="message" rows="5"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-4 focus:ring-blue-200 focus:border-blue-500 outline-none transition"
                        placeholder="متن پیام یا کپشن فایل را اینجا بنویسید..."></textarea>
                </div>

                <!-- دکمه‌ها -->
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

        <!-- بخش پیشرفت زنده -->
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
    <div class="copyright">
        <p style="text-align: center; color: #777; font-size: 12px;">&copy; 2024 - <?= date('Y'); ?> Connectix Bot designed by <a href="https://github.com/MehdiSalari" target="_blank">Mehdi Salari</a>. All rights reserved.</p>
    </div>

    <script>
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

        // تست پیام (با نمایش لودینگ تا تکمیل درخواست)
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

        // ارسال همگانی
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

            // اتصال به SSE برای دریافت پیشرفت زنده — فقط یک اتصال ایجاد کن
            eventSource = new EventSource('broadcast/broadcast_progress.php');
            eventSource.onopen = function () {
                // اتصال برقرار شد — پاک کن هر پیغام خطای قبلی
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
                // اگر به اینجا رسیدیم، ارور واقعی رخ داده — لاگ کن اما اجازه بده دیگر پیام‌ها دریافت شوند
                logContainer.innerHTML += '<div class="log-item error p-3 rounded-lg">خطا در ارتباط با سرور!</div>';
            };
        });

        closeProgress.addEventListener('click', () => {
            progressContainer.classList.add('hidden');
        });
    </script>
</body>

</html>