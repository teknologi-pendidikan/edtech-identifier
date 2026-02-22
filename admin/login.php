<?php
/**
 * Admin Login System
 * EdTech Identifier System - Fresh & Simple Version
 */

require_once '../includes/config.php';
require_once '../includes/auth.php';

$error = '';
$setup_needed = false;

// Check if default admin needs to be created
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (!empty($username) && !empty($password) && strlen($password) >= 6) {
        $result = create_default_admin($username, $password);
        if (strpos($result, 'successfully') !== false) {
            show_success($result);
        } else {
            $error = $result;
        }
    } else {
        $error = 'Username and password (min 6 chars) are required';
    }
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (!empty($username) && !empty($password)) {
        if (login($username, $password)) {
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password';
        }
    } else {
        $error = 'Username and password are required';
    }
}

// Check if admin user exists
try {
    create_admin_table(); // Ensure table exists first
    $conn = db_connect();
    $result = $conn->query("SELECT COUNT(*) as count FROM admin_users");
    $admin_exists = $result->fetch_assoc()['count'] > 0;
} catch (Exception $e) {
    error_log("Login page error: " . $e->getMessage());
    $admin_exists = false; // If we can't check, assume setup needed
}

if (!$admin_exists) {
    $setup_needed = true;
}

// If already logged in, redirect
if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - EdTech Identifier</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <div class="container" style="max-width: 500px; margin-top: var(--cds-spacing-09);">
        <!-- Header -->
        <div style="text-align: center; margin-bottom: var(--cds-spacing-07);">
            <h1 style="color: var(--cds-text-primary); margin-bottom: var(--cds-spacing-03);">
                🔐 Admin Login
            </h1>
            <p class="text-muted">EdTech Identifier System Administration</p>
        </div>

        <!-- Setup Form (if no admin exists) -->
        <?php if ($setup_needed): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">👤 First Time Setup</h2>
            </div>

            <div class="alert alert-info">
                No admin user found. Create your admin account to get started.
            </div>

            <form method="POST">
                <div class="form-group">
                    <label class="form-label" for="username">Admin Username</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="form-input"
                        placeholder="admin"
                        required
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Admin Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-input"
                        placeholder="Minimum 6 characters"
                        minlength="6"
                        required
                    >
                    <p class="text-muted text-small mt-2">Choose a strong password for security</p>
                </div>

                <button type="submit" name="setup" class="btn btn-primary w-full">
                    🚀 Create Admin Account
                </button>
            </form>
        </div>

        <!-- Login Form -->
        <?php else: ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Sign In</h2>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-error">
                <?= h($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="form-input"
                        placeholder="Enter your username"
                        value="<?= h($_POST['username'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-input"
                        placeholder="Enter your password"
                        required
                    >
                </div>

                <button type="submit" name="login" class="btn btn-primary w-full">
                    🔓 Sign In
                </button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Back to Homepage -->
        <div style="text-align: center; margin-top: var(--cds-spacing-06);">
            <a href="../index.php" class="text-muted" style="color: var(--cds-link-primary);">
                ← Back to Homepage
            </a>
        </div>
    </div>

    <!-- Footer -->
    <div style="position: fixed; bottom: 0; left: 0; right: 0; text-align: center; padding: var(--cds-spacing-04); color: var(--cds-text-helper); font-size: 0.75rem;">
        Protected area - All activities are monitored
    </div>
</body>
</html>
