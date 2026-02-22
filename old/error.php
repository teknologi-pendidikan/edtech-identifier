<?php
// Get the error code from query string or default to 404
$error_code = isset($_GET['code']) ? intval($_GET['code']) : 404;

// Set appropriate HTTP status header
http_response_code($error_code);

// Define error messages and titles
$error_types = [
    400 => ['title' => 'Bad Request', 'message' => 'The request could not be understood by the server.'],
    401 => ['title' => 'Unauthorized', 'message' => 'Authentication is required to access this resource.'],
    403 => ['title' => 'Forbidden', 'message' => 'You don\'t have permission to access this resource.'],
    404 => ['title' => 'Not Found', 'message' => 'The resource you\'re looking for doesn\'t exist or has been moved.'],
    500 => ['title' => 'Server Error', 'message' => 'Something went wrong on our end. Please try again later.'],
    503 => ['title' => 'Service Unavailable', 'message' => 'This service is temporarily unavailable. Please try again later.']
];

// Set default if error code is not defined
if (!isset($error_types[$error_code])) {
    $error_code = 500;
}

$title = $error_types[$error_code]['title'];
$message = $error_types[$error_code]['message'];

// Get the requested URL that caused the error
$requested_url = isset($_SERVER['REQUEST_URI']) ? htmlspecialchars($_SERVER['REQUEST_URI']) : 'unknown';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error <?php echo $error_code; ?> - <?php echo $title; ?></title>
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --text: #333;
            --text-light: #666;
            --bg: #f5f7fa;
            --card-bg: #fff;
            --border: #e1e4e8;
            --error: #ff5252;
            --muted: #8b949e;
        }

        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg);
            max-width: 700px;
            margin: 60px auto;
            padding: 0 20px;
            line-height: 1.6;
            color: var(--text);
        }

        .container {
            background-color: var(--card-bg);
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            text-align: center;
        }

        .error-code {
            font-size: 72px;
            font-weight: 700;
            color: var(--error);
            margin: 0;
            line-height: 1;
        }

        .error-title {
            font-size: 24px;
            margin-top: 10px;
            margin-bottom: 20px;
            color: var(--text);
        }

        .error-message {
            font-size: 16px;
            color: var(--text-light);
            margin-bottom: 30px;
        }

        .requested-url {
            font-family: monospace;
            background-color: rgba(0, 0, 0, 0.05);
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 14px;
            color: var(--muted);
            margin-bottom: 30px;
            word-break: break-all;
        }

        .btn {
            display: inline-block;
            background-color: var(--primary);
            color: white;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-1px);
        }

        .link-home {
            margin-top: 20px;
            display: inline-block;
            color: var(--primary);
        }

        footer {
            text-align: center;
            margin-top: 40px;
            font-size: 13px;
            color: var(--text-light);
        }

        @media (max-width: 480px) {
            .container {
                padding: 30px 20px;
            }

            .error-code {
                font-size: 60px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <h1 class="error-code"><?php echo $error_code; ?></h1>
        <h2 class="error-title"><?php echo $title; ?></h2>
        <p class="error-message"><?php echo $message; ?></p>

        <?php if ($error_code == 404): ?>
            <div class="requested-url">
                <?php echo $requested_url; ?>
            </div>
        <?php endif; ?>

        <a href="/" class="btn">Go to Homepage</a>
        <br>
        <a href="javascript:history.back()" class="link-home">Go Back</a>
    </div>

    <footer>
        <p>EdTech UniverseID | &copy; <?php echo date('Y'); ?> Teknologi Pendidikan ID</p>
    </footer>
</body>

</html>
