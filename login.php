<?php
if (file_exists('config.php')) {
    require_once 'functions.php';
} else {
    header('Location: setup/index.php');
    exit();
}
session_start();
// If already logged in, redirect to index.php
if (isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit();
}

// Check Cookie for auto-login
if (isset($_COOKIE['token'])) {
    $token = $_COOKIE['token'];

    // Check if token is valid from main panel API
    $endpoint = 'https://api.connectix.vip/v1/seller/seller-data';
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    $sellerId = $data['seller']['id'] ?? null;
    if (empty($sellerId) || $sellerId == null) {
        // Invalid token, clear cookie
        setcookie('token', '', time() - 3600, "/");
        header('Location: setup/index.php');
        exit();
    }

    // Create database connection
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

    // Query the database to get the user by token
    $stmt = $conn->prepare("SELECT * FROM admins WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        // Set Session
        $_SESSION['admin_id'] = $row['id'];
        header('Location: index.php');
        exit();
    }
}

// Get Bot Profile
$botAvatar = getBotProfiePhoto();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Process login form submission
    $email = $_POST['email'];
    $password = $_POST['password'];

    // Create database connection
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

    // Query the database to get the user by email
    $stmt = $conn->prepare("SELECT * FROM admins WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        // Debug: Check what we got from the database
        $stored_hash = $row['password'];
        $verify_result = password_verify($password, $stored_hash);
        
        if ($verify_result) {
            // Login successful, set session variable and redirect to index.php
            // Set Session
            $_SESSION['admin_id'] = $row['id'];

            // Set Cookies for 30 days
            setcookie('token', $row['token'], time() + (30 * 24 * 60 * 60), "/");
            header('Location: index.php');
            exit();
        } else {
            // Password incorrect
            $error = 'Invalid email or password';
        }
    } else {
        // Email not found, display error message
        $error = 'Invalid email or password';
    }
}

//get app name from bot_config.json if exists
$data = file_get_contents('setup/bot_config.json');
$config = json_decode($data, true);
$appName = $config['app_name'] ?? 'Connectix Bot';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <title><?= $appName ?> | Login</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .main {
            background: #fff;
            width: 100%;
            max-width: 420px;
            border-radius: 16px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            padding: 40px 30px;
        }

        .bot-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            margin: 0 auto 20px auto;
            display: block;
        }

        .bot-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        h2 {
            text-align: center;
            color: #333;
            margin: 0 0 30px 0;
            font-size: 28px;
            font-weight: 600;
        }

        .input-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #444;
            font-size: 14px;
        }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e1e1e1;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        input[type="email"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #95009f;
            box-shadow: 0 0 0 4px rgba(149, 0, 159, 0.15);
        }

        input[type="submit"] {
            width: 100%;
            padding: 14px;
            background: #95009f;
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.4s ease;
            margin-top: 10px;
        }

        input[type="submit"]:hover {
            background: #78008c;
        }

        .error {
            color: #e74c3c;
            text-align: center;
            margin-top: 15px;
            font-size: 14px;
        }

        .copyright {
            position: fixed;
            bottom: 15px;
            left: 50%;
            transform: translateX(-50%);
            color: rgba(255, 255, 255, 0.8);
            font-size: 13px;
            text-align: center;
            z-index: 10;
        }

        .copyright a {
            color: #ffd0ff;
            text-decoration: none;
        }

        .copyright a:hover {
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .main {
                padding: 30px 20px;
                margin: 10px;
                border-radius: 14px;
            }

            h2 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>

    <div class="main">
        <div class="bot-avatar">
            <img src="<?=$botAvatar?>" alt="">
        </div>
        <h2><?= $appName ?> Login</h2>
        
        <form action="#" method="post">
            <div class="input-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" placeholder="example@domain.com" required>
            </div>

            <div class="input-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="••••••••" required>
            </div>

            <input type="submit" value="Login">
            
            <?php if (isset($error)): ?>
                <p class="error"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>
        </form>
    </div>

    <div class="copyright">
        &copy; 2024 - <?= date('Y') ?> Connectix Bot designed by 
        <a href="https://github.com/MehdiSalari" target="_blank">Mehdi Salari</a>. All rights reserved.
    </div>

</body>
</html>