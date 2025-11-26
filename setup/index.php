<?php
if (file_exists('../config.php')) {
    require_once '../config.php';
    session_start();
    if (isset($_SESSION['admin_id'])) {
        // get admin info
        $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
        if ($conn->connect_error) die("DB Error");
        $stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['admin_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $admin = $result->fetch_assoc();
        $conn->close();
    } else {
        // Redirect to login page
        header('Location: ../login.php');
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connectix Bot Setup</title>
</head>
<style>
    body {
        font-family: Arial, sans-serif;
        background-color: #f4f4f4;
        margin: 0;
        padding: 0;
    }
    .main {
        max-width: 600px;
        width: 80%;
        margin: 50px auto;
        padding: 20px;
        background-color: #fff;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        border-radius: 8px;
    }
    h2 {
        text-align: center;
        color: #333;
    }
    form {
        display: flex;
        flex-direction: column;
        width: 100%;
    }
    .form-group {
        /* margin-bottom: 5px; */
        display: flex;
        flex-direction: column;
    }
    .row {
        display: flex;
        flex-direction: row;
        flex: 1;
        justify-content: space-around;
        gap: 10px;
    }
    .col {
        display: flex;
        flex-direction: column;
        flex: 1;
        justify-content: space-between;
        margin-bottom: -10px;
    }
    .input-group {
        margin-bottom: 8px;
        display: flex;
        flex-direction: column;
    }
    label {
        margin-bottom: 5px;
        font-weight: bold;
    }
    input[type="text"], input[type="password"], input[type="email"] {
        padding: 10px;
        margin-bottom: 15px;
        border: 1px solid #ccc;
        border-radius: 4px;
    }
    input[type="submit"] {
        padding: 10px;
        background-color: #95009fff;
        color: white;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        font-size: 16px;
        transition: background-color 0.5s ease;
        font-weight: bold;
    }
    input[type="submit"]:hover {
        background-color: #480056ff;
    }
    input[type="button"] {
        padding: 10px;
        background-color: #ccc;
        color: #333;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        font-size: 16px;
        transition: background-color 0.5s ease;
        margin-top: 10px;
        font-weight: bold;
    }
    input[type="button"]:hover {
        background-color: #bbb;
    }
    a {
        color: #95009fff;
        text-decoration: none;
    }
    hr {
        border: none;
        border-top: 2px solid #ccc;
        margin: 0 0 15px 0;
    }
    .copyright {
        position: fixed;
        left: 0;
        bottom: 0;
        width: 100%;
        text-align: center;
        color: #777;
        /* background-color: white; */
    }
    @media only screen and (max-width: 600px) {
        .main {
            width: 100%;
            padding: 20px;
        }
        .form-group {
            flex-direction: column;
            width: 100%;
        }
        .row {
            flex-direction: column;
            width: 100%;
        }
        .col {
            width: 100%;
        }
    }
</style>

<body>
    <div class="main">
        <div class="form">
            <h2>Connectix Bot Setup</h2>
            <p>Please enter the required configuration details below</p>
            <form action="#" method="post">
                <!-- Database Configuration -->
                <div class="form-group">
                    <div class="row">
                        <div class="col">
                            <div class="input-group">
                                <label for="db_host">Database Host:</label>
                                <input type="text" id="db_host" name="db_host" value="<?= $db_host ?? '' ?>" required>
                            </div>
                        </div>
                        <div class="col">
                            <div class="input-group">
                                <label for="db_name">Database Name:</label>
                                <input type="text" id="db_name" name="db_name" value="<?= $db_name ?? '' ?>" autocomplete="off" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col">
                            <div class="input-group">
                                <label for="db_user">Database User:</label>
                                <input type="text" id="db_user" name="db_user" value="<?= $db_user ?? '' ?>" required>
                            </div>
                        </div>
                        <div class="col">
                            <div class="input-group">
                                <label for="db_pass">Database Password:</label>
                                <input type="password" id="db_pass" name="db_pass" value="<?= $db_pass ?? '' ?>">
                            </div>
                        </div>
                    </div>
                </div>
                <hr>
                <!-- Tokens and Admin Configuration -->
                <div class="form-group">
                    <div class="input-group">
                        <label for="panelToken">Panel Token:</label>
                        <input type="text" id="panelToken" name="panelToken" value="<?= $panelToken ?? '' ?>" required>
                    </div>
                    <div class="input-group">
                        <label for="botToken">Bot Token:</label>
                        <input type="text" id="botToken" name="botToken" value="<?= $botToken ?? '' ?>" required>
                    </div>
                    <hr>
                </div>
                <div class="form-group">
                    <div class="row">
                        <div class="col">
                            <div class="input-group">
                                <label for="email">Admin Email:</label>
                                <input type="email" id="email" name="email" value="<?= $admin['email'] ?? '' ?>" required>
                            </div>
                        </div>
                        <div class="col">
                            <div class="input-group">
                                <label for="chatId">Admin Chat ID: <a href="https://t.me/username_to_id_bot?start=GetChatID" target="_blank">(Get Chat ID)</a></label>
                                <input type="text" id="chatId" name="chatId" value="<?= $admin['chat_id'] ?? '' ?>" required>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <div class="row">
                        <div class="col">
                            <div class="input-group">
                                <label for="botPassword">Set Admin Password:</label>
                                <input type="password" id="adminPassword" name="adminPassword" required>
                            </div>
                        </div>
                        <div class="col">
                            <div class="input-group">
                                <label for="reBotPassword">Re-Enter Admin Password:</label>
                                <input type="password" id="reAdminPassword" name="reAdminPassword" required>
                            </div>
                        </div>
                    </div>
                </div>
                <input type="submit" value="Submit">
                <input type="button" name="cancel" id="cancel" value="Cancel" onclick="window.location.href='../login.php'">
            </form>
        </div>
        <div class="progress-container" style="display:none; margin-top:30px;">
            <div class="progress" style="width:100%; background:#eee; border-radius:8px;">
                <div class="bar" style="height:18px; width:0%; background:#95009fff; border-radius:8px;"></div>
            </div>
            <div class="percent" style="font-weight:bold; margin-top:5px;">0%</div>

            <div class="log"
                style="background:#111; color:#0f0; padding:10px; height:200px; margin-top:15px;
                    overflow-y:auto; font-family:monospace; border-radius:6px;">
            </div>

            <button onclick="window.location.href='../'" class="finish"
                    style="margin-top:20px; display:none; padding:10px 20px; background:#95009fff; color:white;
                        border:none; border-radius:8px; cursor:pointer;">
                Go to Panel
            </button>
        </div>

    </div>
    <div class="copyright">
        <p style="text-align: center; color: #777; font-size: 12px;">&copy; 2024 - <?= date('Y'); ?> Connectix Bot designed by <a href="https://github.com/MehdiSalari" target="_blank">Mehdi Salari</a>. All rights reserved.</p>
    </div>
    <script>
    document.querySelector("form").addEventListener("submit", function(e){
        e.preventDefault();

        const form = this;
        const formData = new FormData(form);

        //password match check
        const adminPassword = formData.get("adminPassword");
        const reAdminPassword = formData.get("reAdminPassword");
        if (adminPassword !== reAdminPassword) {
            alert("Passwords do not match!");
            return;
        }

        // مخفی کردن فرم و نمایش بخش پروگرس
        document.querySelector(".form").style.display = "none";
        document.querySelector(".progress-container").style.display = "block";

        const percentEl = document.querySelector(".percent");
        const barEl = document.querySelector(".bar");
        const logEl = document.querySelector(".log");

        let totalClients = 0;
        let processedClients = 0;
        let hasError = false;
        let lastProgressMsg = '';
        
        // Render percent bar based on authoritative values
        function renderPercent() {
            let percent = totalClients > 0 ? Math.round((processedClients / totalClients) * 100) : 0;
            percent = Math.min(Math.max(percent, 0), 100);
            barEl.style.width = percent + "%";
            percentEl.innerText = percent + "%";
        }

        // append a log line (does NOT change processedClients)
        function updateProgress(msg) {
            logEl.innerHTML += msg + "<br>";
            logEl.scrollTop = logEl.scrollHeight;

            // check for error keywords
            if (/fatal|error/i.test(msg)) {
                hasError = true;
            }
        }

        // Apply polled JSON progress to UI (authoritative)
        function applyProgressFromPoll(data) {
            if (!data) return;
            if (typeof data.total_clients !== 'undefined') {
                totalClients = parseInt(data.total_clients) || 0;
            }
            processedClients = parseInt(data.processedClients) || 0;
            renderPercent();

            if (data && data.page > 0) {
                const progressMsg = `📊 Page ${data.page}: ${data.processedClients} clients, ${data.insertedClients} inserted, ${data.insertedPlans} plans`;
                if (progressMsg !== lastProgressMsg) {
                    logEl.innerHTML += progressMsg + "<br>";
                    logEl.scrollTop = logEl.scrollHeight;
                    lastProgressMsg = progressMsg;
                }
            }
        }

        // Poll setup progress to show real database import stats
        function pollSetupProgress() {
            fetch("setup_progress.php")
                .then(r => r.json())
                .then(data => {
                    applyProgressFromPoll(data);
                })
                .catch(() => {
                    // ignore poll errors
                });
        }

        function onFinish() {
            clearInterval(progressPollInterval);
            // fetch final progress to ensure UI shows final numbers
            fetch("setup_progress.php").then(r => r.json()).then(data => {
                applyProgressFromPoll(data);
                if (hasError) {
                    updateProgress("❌ Error occurred!");
                } else {
                    updateProgress("✅ Done!");
                }
                document.querySelector(".finish").style.display = "block";
            }).catch(() => {
                if (hasError) {
                    updateProgress("❌ Error occurred!");
                } else {
                    updateProgress("✅ Done!");
                }
                document.querySelector(".finish").style.display = "block";
            });
        }

        updateProgress("Starting...");
        progressPollInterval = setInterval(pollSetupProgress, 2000); // Poll every 2 seconds

        // Use fetch stream reader for real-time server logs
        fetch("setup.php", {
            method: "POST",
            body: formData
        }).then(response => {
            const reader = response.body.getReader();
            const decoder = new TextDecoder("utf-8");
            let buffer = "";

            function read() {
                reader.read().then(({ done, value }) => {
                    if (done) {
                        // When stream finished show final data
                        onFinish();
                        return;
                    }
                    buffer += decoder.decode(value, { stream: true });
                    let parts = buffer.split("\n");
                    buffer = parts.pop(); // keep last partial line
                    parts.forEach(line => {
                        if (line.trim() !== "") {
                            // append server log line (do not treat as authoritative counter)
                            updateProgress(line.trim());
                        }
                    });
                    read();
                });
            }

            read();
        }).catch(err => {
            clearInterval(progressPollInterval);
            updateProgress("❌ Error in AJAX: " + err);
        });
    });
    </script>


</body>

</html>