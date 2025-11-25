<?php
if (file_exists('config.php')) {
    require_once 'config.php';
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
            $_SESSION['admin_id'] = $row['id'];
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connectix Bot | Login</title>
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
    }
    input[type="submit"]:hover {
        background-color: #480056ff;
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
            <h2>Connectix Bot Login</h2>
            <form action="#" method="post">
                <div class="form-group">
                    <div class="input-group">
                        <label for="panelToken">Email:</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    <div class="input-group">
                        <label for="botToken">Password:</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                </div>
                <input type="submit" value="Login">
                <?php if (isset($error)) { echo '<p style="color: red;">' . $error . '</p>'; } ?>
            </form>
        </div>
    </div>
    <div class="copyright">
        <p style="text-align: center; color: #777; font-size: 12px;">&copy; 2024 - <?= date('Y'); ?> Connectix Bot designed by <a href="https://github.com/MehdiSalari" target="_blank">Mehdi Salari</a>. All rights reserved.</p>
    </div>
</body>
</html>