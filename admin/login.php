<?php
require_once '../includes/auth.php';

$error = '';

// If already logged in, redirect to admin page
if (is_authenticated()) {
    header('Location: index.php');
    exit;
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (authenticate($username, $password)) {
        // Redirect to admin page after successful login
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid username or password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - EdTech UniverseID</title>
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

        button:hover {
            background-color: var(--primary-dark);
            transform: translateY(-1px);
        }

        .error {
            background-color: var(--error);
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
    </style>
</head>

<body>
    <div class="container">
        <h1>EdTech UniverseID Admin</h1>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="input-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus>
            </div>

            <div class="input-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit">Log In</button>
        </form>

        <a href="../" class="back-link">← Return to Homepage</a>
    </div>
</body>

</html>
