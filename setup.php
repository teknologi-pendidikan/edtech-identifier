<?php
/**
 * Secure Admin Setup Script for EdTech Identifier System
 *
 * This script helps set up the initial admin credentials securely.
 * Run this ONCE during initial setup, then delete or secure this file.
 */

// Prevent running in production if already configured
if (getenv('ADMIN_PASSWORD_HASH') && !empty(getenv('ADMIN_PASSWORD_HASH'))) {
    die("System appears to be already configured. If you need to reset credentials, use reset_credentials.php instead.\n");
}

// CLI only for security
if (php_sapi_name() !== 'cli') {
    if (!isset($_SESSION)) session_start();

    // Allow web access ONLY if no admin exists and from localhost
    $is_localhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost']);
    if (!$is_localhost) {
        http_response_code(403);
        die("Setup can only be run from localhost or command line for security.");
    }

    // Web interface for initial setup
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';

        $errors = [];

        // Validation
        if (empty($username)) {
            $errors[] = "Username is required";
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
            $errors[] = "Username must be 3-50 characters, alphanumeric and underscore only";
        }

        if (empty($password)) {
            $errors[] = "Password is required";
        } elseif (strlen($password) < 12) {
            $errors[] = "Password must be at least 12 characters";
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter, lowercase letter, number, and special character";
        }

        if ($password !== $password_confirm) {
            $errors[] = "Password confirmation does not match";
        }

        if (empty($errors)) {
            $success = setup_admin_credentials($username, $password);
            if ($success) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                echo "<div style='color: green; margin: 20px 0; padding: 20px; background: #f0f8f0; border: 1px solid #4CAF50; border-radius: 5px;'>";
                echo "<h3>✅ Setup Complete!</h3>";
                echo "<p><strong>Username:</strong> " . htmlspecialchars($username) . "</p>";
                echo "<p><strong>Add these to your .env file or environment variables:</strong></p>";
                echo "<pre style='background: #f5f5f5; padding: 10px; border-radius: 3px;'>";
                echo "ADMIN_USERNAME=" . htmlspecialchars($username) . "\n";
                echo "ADMIN_PASSWORD_HASH=" . htmlspecialchars($hash) . "\n";
                echo "SESSION_TIMEOUT=3600\n";
                echo "IP_BYPASS_ENABLED=false\n";
                echo "STRICT_IP_VALIDATION=true";
                echo "</pre>";
                echo "<p style='color: #d32f2f; font-weight: bold;'>⚠️ IMPORTANT: Delete or secure this setup.php file immediately!</p>";
                echo "</div>";
            } else {
                $errors[] = "Failed to setup credentials. Check file permissions.";
            }
        }

        if (!empty($errors)) {
            echo "<div style='color: red; margin: 20px 0; padding: 20px; background: #fff0f0; border: 1px solid #f44336; border-radius: 5px;'>";
            foreach ($errors as $error) {
                echo "<p>❌ " . htmlspecialchars($error) . "</p>";
            }
            echo "</div>";
        }
    }

    // Show setup form if not completed
    if (empty($hash)) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>EdTech Identifier - Admin Setup</title>
            <style>
                body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
                .form-group { margin: 15px 0; }
                label { display: block; margin-bottom: 5px; font-weight: bold; }
                input[type="text"], input[type="password"] { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
                button { background: #4CAF50; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; }
                button:hover { background: #45a049; }
                .warning { background: #fff3cd; border: 1px solid #ffc107; color: #664d03; padding: 15px; border-radius: 5px; margin: 20px 0; }
                .requirements { background: #e7f3ff; border: 1px solid #2196F3; color: #0c5460; padding: 15px; border-radius: 5px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <h1>🔐 EdTech Identifier - Admin Setup</h1>

            <div class="warning">
                <strong>⚠️ Security Notice:</strong> This setup should only be run once during initial installation. The setup file should be deleted after completion.
            </div>

            <div class="requirements">
                <strong>Password Requirements:</strong>
                <ul>
                    <li>Minimum 12 characters</li>
                    <li>At least one uppercase letter</li>
                    <li>At least one lowercase letter</li>
                    <li>At least one number</li>
                    <li>At least one special character (@$!%*?&)</li>
                </ul>
            </div>

            <form method="POST">
                <div class="form-group">
                    <label>Admin Username:</label>
                    <input type="text" name="username" value="<?= htmlspecialchars($_POST['username'] ?? 'admin') ?>" required
                           pattern="[a-zA-Z0-9_]{3,50}" title="3-50 characters, alphanumeric and underscore only">
                </div>

                <div class="form-group">
                    <label>Admin Password:</label>
                    <input type="password" name="password" required minlength="12">
                </div>

                <div class="form-group">
                    <label>Confirm Password:</label>
                    <input type="password" name="password_confirm" required minlength="12">
                </div>

                <button type="submit">🚀 Setup Admin Account</button>
            </form>
        </body>
        </html>
        <?php
        exit;
    }
    exit;
}

// CLI Interface
function setup_admin_credentials($username, $password) {
    // Validate inputs
    if (empty($username) || empty($password)) {
        return false;
    }

    if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
        echo "Invalid username format.\n";
        return false;
    }

    if (strlen($password) < 12) {
        echo "Password must be at least 12 characters.\n";
        return false;
    }

    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/', $password)) {
        echo "Password must contain at least one uppercase letter, lowercase letter, number, and special character.\n";
        return false;
    }

    // Generate secure hash
    $hash = password_hash($password, PASSWORD_DEFAULT);

    // Try to create/update .env file
    $env_file = __DIR__ . '/../.env';
    $env_content = [];

    if (file_exists($env_file)) {
        $env_content = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    }

    // Update or add environment variables
    $updated = false;
    foreach ($env_content as $key => $line) {
        if (str_starts_with($line, 'ADMIN_USERNAME=')) {
            $env_content[$key] = "ADMIN_USERNAME={$username}";
            $updated = true;
        } elseif (str_starts_with($line, 'ADMIN_PASSWORD_HASH=')) {
            $env_content[$key] = "ADMIN_PASSWORD_HASH={$hash}";
            $updated = true;
        }
    }

    // Add if not found
    if (!$updated) {
        $env_content[] = "ADMIN_USERNAME={$username}";
        $env_content[] = "ADMIN_PASSWORD_HASH={$hash}";
        $env_content[] = "SESSION_TIMEOUT=3600";
        $env_content[] = "IP_BYPASS_ENABLED=false";
        $env_content[] = "STRICT_IP_VALIDATION=true";
    }

    // Write .env file
    $success = file_put_contents($env_file, implode("\n", $env_content) . "\n");

    if ($success) {
        // Set proper permissions
        chmod($env_file, 0600);
        return true;
    }

    return false;
}

// Clear output buffer for clean CLI output
while (ob_get_level()) {
    ob_end_clean();
}

echo "=== EdTech Identifier Admin Setup ===\n\n";

// Check if already configured
if (file_exists(__DIR__ . '/../.env')) {
    $env_content = file_get_contents(__DIR__ . '/../.env');
    if (strpos($env_content, 'ADMIN_PASSWORD_HASH=') !== false && strpos($env_content, 'ADMIN_USERNAME=') !== false) {
        echo "❌ System appears to be already configured.\n";
        echo "If you need to reset credentials, delete the .env file first or use reset_credentials.php\n";
        exit(1);
    }
}

echo "This script will help you set up the initial admin credentials securely.\n\n";

// Get username
do {
    echo "Enter admin username (3-50 characters, alphanumeric + underscore): ";
    $username = trim(fgets(STDIN));

    if (empty($username)) {
        echo "❌ Username cannot be empty.\n";
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
        echo "❌ Username must be 3-50 characters, alphanumeric and underscore only.\n";
    } else {
        break;
    }
} while (true);

// Get password
do {
    echo "Enter admin password (minimum 12 characters with mixed case, numbers, and symbols): ";
    system('stty -echo'); // Hide password input
    $password = trim(fgets(STDIN));
    system('stty echo');
    echo "\n";

    echo "Confirm password: ";
    system('stty -echo');
    $password_confirm = trim(fgets(STDIN));
    system('stty echo');
    echo "\n";

    if (empty($password)) {
        echo "❌ Password cannot be empty.\n";
    } elseif (strlen($password) < 12) {
        echo "❌ Password must be at least 12 characters.\n";
    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/', $password)) {
        echo "❌ Password must contain at least one uppercase letter, lowercase letter, number, and special character (@$!%*?&).\n";
    } elseif ($password !== $password_confirm) {
        echo "❌ Password confirmation does not match.\n";
    } else {
        break;
    }
} while (true);

// Setup credentials
echo "\nSetting up admin credentials...\n";

if (setup_admin_credentials($username, $password)) {
    echo "✅ Admin credentials set up successfully!\n\n";
    echo "Credentials stored in .env file with secure permissions.\n";
    echo "Username: {$username}\n";
    echo "Password hash: " . password_hash($password, PASSWORD_DEFAULT) . "\n\n";
    echo "⚠️  IMPORTANT SECURITY NOTES:\n";
    echo "1. Delete this setup.php file immediately\n";
    echo "2. Ensure .env file has proper permissions (600)\n";
    echo "3. Never store passwords in plain text\n";
    echo "4. Consider enabling 2FA and IP restrictions\n\n";
    echo "🚀 You can now log in to the admin panel!\n";
} else {
    echo "❌ Failed to set up credentials. Check file permissions and try again.\n";
    exit(1);
}
?>
