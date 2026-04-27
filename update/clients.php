<?php
$ok = $_GET['ok'] ?? null;
if ($ok !== 'true') {
    echo "<script>alert('Unauthorized Access')</script>";
    header('Location: ../index.php?updated=false');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
    <style>
        body {
            font-family: 'Vazirmatn', sans-serif;
        }

        input:focus,
        select:focus {
            outline: none;
            ring: 4px solid #a78bfa;
            border-color: #a78bfa;
        }

        .input-focus:focus {
            @apply ring-4 ring-indigo-200 border-indigo-500;
        }

        .progress-bar {
            height: 12px;
            background: #e5e7eb;
            border-radius: 999px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #a78bfa, #6366f1);
            border-radius: 999px;
            width: 0%;
            transition: width 0.6s ease;
        }

        .log-box {
            background: #1a1a1a;
            color: #33ff33;
            font-family: 'Courier New', monospace;
            padding: 16px;
            border-radius: 12px;
            height: 280px;
            overflow-y: auto;
            direction: ltr;
            text-align: left;
        }

        .copyright {
            width: 100%;
            text-align: center;
            color: #777;
            font-size: 15px;
            direction: ltr;
            margin: 30px 0 15px;
        }

        .copyright a {
            color: #b500bbff;
            text-decoration: none;
        }

        .fade-in {
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
<!-- Main Card -->
<div class="bg-white rounded-2xl shadow-2xl overflow-hidden fade-in"></div>
    <!-- Progress Section -->
    <div id="progressSection" class="hidden p-8 md:p-12 bg-gray-50">
        <div class="text-center mb-8">
            <i id="statusIcon" class="fas fa-sync-alt text-6xl text-indigo-600 animate-spin mb-6"></i>
            <h3 class="text-2xl font-bold text-gray-800">در حال نصب و همگام‌سازی اطلاعات...</h3>
            <p class="text-gray-600 mt-3">لطفاً منتظر بمانید، این عملیات ممکن است چند دقیقه طول بکشد.</p>
        </div>
        <div class="mb-6">
            <div class="progress-bar">
                <div class="progress-fill" id="progressFill"></div>
            </div>
            <div class="text-center mt-3">
                <span class="text-2xl font-bold text-indigo-600" id="progressPercent">0%</span>
            </div>
        </div>

        <div class="log-box text-sm" id="logBox"></div>

        <div class="text-center mt-8">
            <button onclick="window.location.href='../index.php?updated=true'" id="finishBtn"
                class="hidden px-10 py-4 bg-gradient-to-r from-green-500 to-emerald-600 text-white font-bold rounded-xl shadow-lg hover:shadow-2xl transition transform hover:scale-105">
                <i class="fas fa-check-circle ml-3"></i>
                تکمیل شد! برو به پنل
            </button>
        </div>
    </div>
</div>
<form id="setupFormElement" class="space-y-8">
    <div class="flex gap-4 pt-6">
        <button type="submit"
            class="flex-1 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white font-bold py-4 rounded-xl shadow-lg transition transform hover:scale-105">
            <i class="fas fa-rocket ml-3"></i>
            شروع نصب و همگام‌سازی
        </button>
        <button type="button" onclick="window.location.href='../'"
            class="px-8 py-4 bg-gray-200 hover:bg-gray-300 text-gray-700 font-bold rounded-xl transition">
            لغو
        </button>
    </div>
</form>
<script>
    document.getElementById("setupFormElement").addEventListener("submit", function (e) {
        e.preventDefault();

        const form = this;
        const formData = new FormData(form);

        if (formData.get("adminPassword") !== formData.get("reAdminPassword")) {
            alert("رمز عبور و تکرار آن یکسان نیست!");
            return;
        }

        // document.getElementById("setupForm").classList.add("hidden");
        document.getElementById("progressSection").classList.remove("hidden");

        const progressFill = document.getElementById("progressFill");
        const progressPercent = document.getElementById("progressPercent");
        const logBox = document.getElementById("logBox");
        const finishBtn = document.getElementById("finishBtn");

        let hasError = false;
        let setupCompleted = false;

        function log(msg) {
            // اگر خطا بود علامت قرمز بذار
            if (msg.includes("ERROR:") || msg.includes("FATAL") || msg.includes("failed") || msg.includes("خطا")) {
                hasError = true;
                logBox.innerHTML += `<span class="text-red-400 font-bold">✗ ${msg}</span><br>`;
            } else if (msg.includes("SETUP_FINISHED")) {
                setupCompleted = true;
            } else {
                logBox.innerHTML += msg + "<br>";
            }
            logBox.scrollTop = logBox.scrollHeight;
        }

        function showErrorState() {
            progressFill.style.width = "100%";
            progressFill.style.background = "linear-gradient(90deg, #ef4444, #b91c1c)";
            progressPercent.textContent = "خطا!";
            progressPercent.classList.add("text-red-600");

            // عوض کردن آیکون به علامت خطا
            document.getElementById("statusIcon").className = "fas fa-exclamation-triangle text-6xl text-red-600 mb-6";
            document.querySelector("#progressSection h3").textContent = "نصب با خطا مواجه شد!";
            document.querySelector("#progressSection p").textContent = "لطفاً اطلاعات وارد شده را بررسی کنید و دوباره تلاش کنید.";

            finishBtn.classList.remove("hidden");
            finishBtn.textContent = "تلاش مجدد";
            finishBtn.className = "px-10 py-4 bg-red-600 hover:bg-red-700 text-white font-bold rounded-xl shadow-lg transition";
            finishBtn.onclick = () => location.reload();
        }

        function showSuccessState() {
            progressFill.style.width = "100%";
            progressPercent.textContent = "100%";

            // عوض کردن آیکون به تیک سبز
            document.getElementById("statusIcon").className = "fas fa-check-circle text-6xl text-green-600 mb-6";
            document.querySelector("#progressSection h3").textContent = "نصب با موفقیت انجام شد!";
            document.querySelector("#progressSection p").textContent = "همه چیز آماده است. حالا می‌تونید وارد پنل بشید.";

            log("<span class='text-green-400 font-bold'>نصب با موفقیت تکمیل شد!</span>");

            finishBtn.classList.remove("hidden");
            finishBtn.textContent = "تکمیل شد! برو به پنل";
            finishBtn.className = "px-10 py-4 bg-gradient-to-r from-green-500 to-emerald-600 text-white font-bold rounded-xl shadow-lg hover:shadow-2xl transition transform hover:scale-105";
            finishBtn.onclick = () => window.location.href = '../';
        }

        // Poll progress (اختیاری، می‌تونی نگه داری)
        const poll = setInterval(() => {
            fetch("setup_progress.php")
                .then(r => r.json())
                .then(d => {
                    if (!hasError && d.total_clients > 0) {
                        // استفاده از percent که از سرور میاد
                        let percent = d.percent || 0;
                        if (percent >= 0) {
                            progressFill.style.width = percent + "%";
                            progressPercent.textContent = percent + "%";
                        }
                    }
                })
                .catch(() => {});
        }, 2000);

        fetch("clients_setup.php", {
            method: "POST",
            body: formData
        })
        .then(r => r.body.getReader())
        .then(reader => {
            const decoder = new TextDecoder("utf-8");
            let buffer = "";

            function read() {
                reader.read().then(({ done, value }) => {
                    if (done) {
                        clearInterval(poll);

                        // بعد از اتمام استریم بررسی کن
                        if (hasError || !setupCompleted) {
                            showErrorState();
                        } else {
                            showSuccessState();
                        }
                        return;
                    }

                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split("\n");
                    buffer = lines.pop();

                    lines.forEach(line => {
                        if (line.trim()) {
                            log(line.trim());
                        }
                    });

                    read();
                });
            }
            read();
        })
        .catch(err => {
            clearInterval(poll);
            log("خطا در ارتباط با سرور: " + err.message);
            showErrorState();
        });
    });
</script>
 
</body>
</html>