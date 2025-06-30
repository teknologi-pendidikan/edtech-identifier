<?php
require_once '../includes/auth.php';
require_once '../includes/security.php';

// Set security headers
set_security_headers();

$error = '';
$lockout_message = '';
$ip_bypass_active = false;

// Check if user is accessing from trusted IP
if (is_trusted_ip()) {
    $ip_bypass_active = true;
    // Auto-redirect to admin if from trusted IP
    if (auto_authenticate_trusted_ip()) {
        header('Location: index.php');
        exit;
    }
}

// If already logged in, redirect to admin page
if (is_authenticated()) {
    header('Location: index.php');
    exit;
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check rate limiting for login attempts
    if (!check_rate_limit('login_page', 5, 300)) { // 5 attempts per 5 minutes
        log_security_event('login_rate_limit_exceeded', ['ip' => $_SERVER['REMOTE_ADDR']]);
        $error = 'Too many login attempts. Please wait 5 minutes before trying again.';
    } else {
        $username = validate_text_input($_POST['username'] ?? '', 50);
        $password = $_POST['password'] ?? '';

        if (!$username || !$password) {
            $error = 'Username and password are required.';
            log_security_event('login_attempt_missing_credentials', ['username' => $username]);
        } else {
            // Check if user is locked out
            if (is_locked_out($username)) {
                $lockout_message = 'Account temporarily locked due to multiple failed login attempts. Please try again later.';
                log_security_event('login_attempt_while_locked', ['username' => $username]);
            } else {
                if (authenticate($username, $password)) {
                    // Redirect to admin page after successful login
                    header('Location: index.php');
                    exit;
                } else {
                    $error = 'Invalid username or password.';
                    // Additional failed attempts are logged in the authenticate function
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - EdTech Identifier System</title>
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --text: #333;
            --text-light: #666;
            --bg: #f5f7fa;
            --card-bg: #fff;
            --border: #e1e4e8;
            --error: #f44336;
            --warning: #ff9800;
        }

        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg);
            max-width: 500px;
            margin: 100px auto;
            padding: 0 20px;
            line-height: 1.6;
            color: var(--text);
        }

        .container {
            background-color: var(--card-bg);
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        h1 {
            color: var(--primary);
            margin-top: 0;
            margin-bottom: 30px;
            font-size: 24px;
            text-align: center;
        }

        .input-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-family: inherit;
            font-size: 15px;
            box-sizing: border-box;
        }

        input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
        }

        button {
            width: 100%;
            padding: 12px;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: all 0.2s;
        }

        button:hover:not(:disabled) {
            background-color: var(--primary-dark);
            transform: translateY(-1px);
        }

        button:disabled {
            background-color: #ccc;
            cursor: not-allowed;
            transform: none;
        }

        .error {
            background-color: var(--error);
            color: white;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            text-align: center;
        }

        .lockout {
            background-color: var(--warning);
            color: white;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            text-align: center;
        }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: var(--text-light);
            text-decoration: none;
        }

        .back-link:hover {
            color: var(--primary);
        }

        .security-notice {
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #6c757d;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>EdTech Identifier Admin</h1>

        <div class="security-notice">
            <strong>Security Notice:</strong> This is a restricted area. All login attempts are monitored and logged.
        </div>

        <?php if ($lockout_message): ?>
            <div class="lockout"><?php echo htmlspecialchars($lockout_message); ?></div>
        <?php elseif ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post" id="login-form">
            <div class="input-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus autocomplete="username"
                    value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" maxlength="50">
            </div>

            <div class="input-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>

            <button type="submit" id="login-btn" <?php echo $lockout_message ? 'disabled' : ''; ?>>
                Log In
            </button>
        </form>

        <a href="../" class="back-link">← Return to Homepage</a>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('login-form');
            const loginBtn = document.getElementById('login-btn');

            form.addEventListener('submit', function () {
                if (!loginBtn.disabled) {
                    loginBtn.textContent = 'Signing In...';
                    loginBtn.disabled = true;
                }
            });

            // Auto-focus on username field if empty
            const usernameField = document.getElementById('username');
            if (usernameField && !usernameField.value) {
                usernameField.focus();
            }
        });
    </script>
</body>

</html>
