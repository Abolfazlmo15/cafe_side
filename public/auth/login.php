<?php
// public/auth/login.php – Admin login page (password from database)
// ====================================================================

session_start();

// If already logged in, redirect to admin orders
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: ' . URL_ADMIN_ORDERS);
    exit;
}

require_once __DIR__ . '/../../bootstrap.php';

$pdo = getDbConnection();

// --- Fetch admin password from settings table ---
$adminPass = ADMIN_PASS_FALLBACK; // fallback from config.php
try {
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'admin_password'");
    $row = $stmt->fetch();
    if ($row) {
        $adminPass = $row['setting_value'];
    }
} catch (PDOException $e) {
    // Table doesn't exist yet – use fallback
}

$error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entered = $_POST['password'] ?? '';
    if ($entered === $adminPass) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: ' . URL_ADMIN_ORDERS);
        exit;
    } else {
        $error = true;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login – QR Café</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- External CSS -->
    <link rel="stylesheet" href="../../assets/css/auth/login.css">
</head>
<body>
    <div class="login-box">
        <h1><i class="fas fa-lock"></i> Admin Login</h1>
        <p style="color: #475569; margin-bottom: 1.5rem;">Enter the password to access the dashboard.</p>

        <?php if ($error): ?>
            <div class="error"><i class="fas fa-exclamation-circle"></i> Incorrect password. Try again.</div>
        <?php endif; ?>

        <form method="POST">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" placeholder="Enter admin password" autofocus required>
            <button type="submit"><i class="fas fa-sign-in-alt"></i> Login</button>
        </form>
        <div class="hint">Change the password in <strong>Settings</strong> (once logged in).</div>
    </div>
</body>
</html>