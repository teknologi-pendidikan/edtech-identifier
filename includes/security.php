<?php
/**
 * Security functions for EdTech Identifier System
 * Provides CSRF protection, input validation, and security utilities
 */

// Generate CSRF token
function generate_csrf_token()
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Verify CSRF token
function verify_csrf_token($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Enhanced XSS protection with context awareness
function escape_html($string)
{
    return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// Escape for JavaScript context
function escape_js($string)
{
    return json_encode($string, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

// Validate URL and check for malicious schemes
function validate_url($url)
{
    // Check basic URL format
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    // Parse URL and check scheme
    $parsed = parse_url($url);
    if (!$parsed || !isset($parsed['scheme'])) {
        return false;
    }

    // Only allow safe schemes
    $allowed_schemes = ['http', 'https', 'ftp', 'ftps'];
    if (!in_array(strtolower($parsed['scheme']), $allowed_schemes)) {
        return false;
    }

    // Prevent localhost/internal network access in production
    if (isset($parsed['host'])) {
        $host = strtolower($parsed['host']);
        $blocked_hosts = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];

        // Block private IP ranges
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        } else {
            // Block known local hostnames
            foreach ($blocked_hosts as $blocked) {
                if (strpos($host, $blocked) !== false) {
                    return false;
                }
            }
        }
    }

    return true;
}

// Validate identifier format with strict patterns
function validate_identifier_format($identifier)
{
    // Allow both full format (edtechid.PREFIX/SUFFIX) and short format (PREFIX/SUFFIX)
    $patterns = [
        '/^edtechid\.[a-zA-Z0-9]+\/[a-zA-Z0-9\-_]+$/',  // Full format
        '/^[a-zA-Z0-9]+\/[a-zA-Z0-9\-_]+$/'            // Short format
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $identifier)) {
            return true;
        }
    }

    return false;
}

// Validate prefix format
function validate_prefix_format($prefix)
{
    return preg_match('/^edtechid\.[a-zA-Z0-9]+$/', $prefix);
}

// Validate suffix format
function validate_suffix_format($suffix)
{
    return preg_match('/^[a-zA-Z0-9\-_]+$/', $suffix) && strlen($suffix) >= 1 && strlen($suffix) <= 100;
}

// Validate CSV file content and structure
function validate_csv_file($file_path)
{
    $errors = [];

    // Check file exists and is readable
    if (!file_exists($file_path) || !is_readable($file_path)) {
        return ['File is not readable or does not exist'];
    }

    // Check file size (max 10MB)
    if (filesize($file_path) > 10 * 1024 * 1024) {
        return ['File size exceeds 10MB limit'];
    }

    // Read and validate file content
    $handle = fopen($file_path, 'r');
    if (!$handle) {
        return ['Could not open file for reading'];
    }

    $line_number = 0;
    $max_lines = 1000; // Limit number of lines

    while (($line = fgets($handle)) !== false && $line_number < $max_lines) {
        $line_number++;
        $line = trim($line);

        if (empty($line))
            continue;

        // Check for potential malicious content
        if (preg_match('/<\?php|<script|javascript:|data:|vbscript:/i', $line)) {
            $errors[] = "Line $line_number: Potentially malicious content detected";
            continue;
        }

        // Parse CSV line
        $data = str_getcsv($line);

        if (count($data) !== 5) {
            $errors[] = "Line $line_number: Expected 5 columns, got " . count($data);
            continue;
        }

        // Validate each field
        list($prefix, $suffix, $url, $title, $description) = $data;

        if (!validate_prefix_format($prefix)) {
            $errors[] = "Line $line_number: Invalid prefix format";
        }

        if (!validate_suffix_format($suffix)) {
            $errors[] = "Line $line_number: Invalid suffix format";
        }

        if (!validate_url($url)) {
            $errors[] = "Line $line_number: Invalid URL";
        }

        if (strlen($title) > 255) {
            $errors[] = "Line $line_number: Title too long (max 255 characters)";
        }

        if (strlen($description) > 1000) {
            $errors[] = "Line $line_number: Description too long (max 1000 characters)";
        }
    }

    fclose($handle);

    if ($line_number >= $max_lines) {
        $errors[] = "File contains more than $max_lines lines (limit exceeded)";
    }

    return $errors;
}

// Enhanced persistent rate limiting using file system
function check_persistent_rate_limit($action, $identifier, $max_attempts = 5, $time_window = 300) {
    $hash = md5($action . '_' . $identifier);
    $file_path = sys_get_temp_dir() . '/edtech_rate_' . $hash;

    $current_time = time();

    if (!file_exists($file_path)) {
        $rate_data = ['count' => 1, 'reset_time' => $current_time + $time_window];
        file_put_contents($file_path, json_encode($rate_data), LOCK_EX);
        return true;
    }

    $rate_data = json_decode(file_get_contents($file_path), true);
    if (!$rate_data) {
        $rate_data = ['count' => 1, 'reset_time' => $current_time + $time_window];
        file_put_contents($file_path, json_encode($rate_data), LOCK_EX);
        return true;
    }

    // Reset if time window has passed
    if ($current_time > $rate_data['reset_time']) {
        $rate_data = ['count' => 1, 'reset_time' => $current_time + $time_window];
        file_put_contents($file_path, json_encode($rate_data), LOCK_EX);
        return true;
    }

    // Check if limit exceeded
    if ($rate_data['count'] >= $max_attempts) {
        return false;
    }

    // Increment counter
    $rate_data['count']++;
    file_put_contents($file_path, json_encode($rate_data), LOCK_EX);
    return true;
}

function record_persistent_failed_attempt($action, $identifier) {
    $hash = md5($action . '_failed_' . $identifier);
    $file_path = sys_get_temp_dir() . '/edtech_failed_' . $hash;

    $data = [];
    if (file_exists($file_path)) {
        $data = json_decode(file_get_contents($file_path), true) ?: [];
    }

    $data[] = [
        'timestamp' => time(),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];

    // Keep only last 100 attempts
    $data = array_slice($data, -100);

    file_put_contents($file_path, json_encode($data), LOCK_EX);
}

function clear_persistent_rate_limit($action, $identifier) {
    $hash = md5($action . '_' . $identifier);
    $file_path = sys_get_temp_dir() . '/edtech_rate_' . $hash;

    if (file_exists($file_path)) {
        unlink($file_path);
    }
}

// Fallback session-based rate limiting if file system fails
function check_rate_limit($action, $max_attempts = 5, $time_window = 300)
{
    // Try persistent rate limiting first
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (check_persistent_rate_limit($action, $ip, $max_attempts, $time_window)) {
        return true;
    }

    // Fallback to session-based rate limiting
    $key = 'rate_limit_' . $action . '_' . $ip;

    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'reset_time' => time() + $time_window];
    }

    $rate_data = $_SESSION[$key];

    // Reset if time window has passed
    if (time() > $rate_data['reset_time']) {
        $_SESSION[$key] = ['count' => 1, 'reset_time' => time() + $time_window];
        return true;
    }

    // Check if limit exceeded
    if ($rate_data['count'] >= $max_attempts) {
        return false;
    }

    // Increment counter
    $_SESSION[$key]['count']++;
    return true;
}

// Sanitize filename for uploads
function sanitize_filename($filename)
{
    // Remove path information
    $filename = basename($filename);

    // Remove or replace dangerous characters
    $filename = preg_replace('/[^a-zA-Z0-9\-_\.]/', '_', $filename);

    // Prevent double extensions and common dangerous extensions
    $dangerous_extensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'pl', 'py', 'jsp', 'asp', 'sh', 'cgi'];
    $filename_parts = explode('.', $filename);

    if (count($filename_parts) > 1) {
        $extension = strtolower(end($filename_parts));
        if (in_array($extension, $dangerous_extensions)) {
            $filename .= '.txt'; // Append safe extension
        }
    }

    return $filename;
}

// Generate secure headers
function set_security_headers()
{
    // Prevent XSS
    header('X-XSS-Protection: 1; mode=block');

    // Prevent content type sniffing
    header('X-Content-Type-Options: nosniff');

    // Prevent clickjacking
    header('X-Frame-Options: DENY');

    // Content Security Policy - Updated to allow Cloudflare Turnstile
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data:; font-src 'self' data:; connect-src 'self' https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com;");

    // Hide server information
    header('X-Powered-By: EdTech-ID');
}

// Validate and clean text input
function validate_text_input($input, $max_length = 255, $allow_html = false)
{
    if (!is_string($input)) {
        return false;
    }

    // Check length
    if (strlen($input) > $max_length) {
        return false;
    }

    // If HTML is not allowed, check for HTML tags
    if (!$allow_html && $input !== strip_tags($input)) {
        return false;
    }

    return trim($input);
}

// Log security events
function log_security_event($event, $details = [])
{
    $log_entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'event' => $event,
        'details' => $details
    ];

    // In production, this should write to a proper log file
    error_log('SECURITY: ' . json_encode($log_entry));
}

// Enhanced account lockout functionality with file-based persistence
function record_failed_login($username)
{
    // Session-based tracking (for current session)
    $key = 'failed_login_' . $username;
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [
            'count' => 0,
            'first_attempt' => time(),
            'last_attempt' => time()
        ];
    }
    $_SESSION[$key]['count']++;
    $_SESSION[$key]['last_attempt'] = time();

    // File-based tracking (persistent across sessions)
    $file_path = sys_get_temp_dir() . '/edtech_failed_login_' . md5($username);
    $data = [
        'count' => 1,
        'first_attempt' => time(),
        'last_attempt' => time()
    ];

    if (file_exists($file_path)) {
        $existing_data = json_decode(file_get_contents($file_path), true);
        if ($existing_data && time() - $existing_data['first_attempt'] < 3600) { // Within 1 hour
            $data = $existing_data;
            $data['count']++;
            $data['last_attempt'] = time();
        }
    }

    file_put_contents($file_path, json_encode($data), LOCK_EX);

    log_security_event('failed_login_attempt', [
        'username' => $username,
        'attempt_count' => $data['count'],
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
}

function clear_failed_attempts($username)
{
    // Clear session data
    $key = 'failed_login_' . $username;
    if (isset($_SESSION[$key])) {
        unset($_SESSION[$key]);
    }

    // Clear file-based data
    $file_path = sys_get_temp_dir() . '/edtech_failed_login_' . md5($username);
    if (file_exists($file_path)) {
        unlink($file_path);
    }

    log_security_event('failed_attempts_cleared', ['username' => $username]);
}

function is_locked_out($username)
{
    $max_attempts = 5;
    $lockout_duration = 900; // 15 minutes

    // Check file-based tracking first (more persistent)
    $file_path = sys_get_temp_dir() . '/edtech_failed_login_' . md5($username);
    if (file_exists($file_path)) {
        $data = json_decode(file_get_contents($file_path), true);
        if ($data && $data['count'] >= $max_attempts) {
            if (time() - $data['last_attempt'] > $lockout_duration) {
                // Lockout expired, clear the file
                unlink($file_path);
                return false;
            }
            return true;
        }
    }

    // Fallback to session-based tracking
    $key = 'failed_login_' . $username;
    if (!isset($_SESSION[$key])) {
        return false;
    }

    $failed_data = $_SESSION[$key];
    if ($failed_data['count'] >= $max_attempts) {
        if (time() - $failed_data['last_attempt'] > $lockout_duration) {
            clear_failed_attempts($username);
            return false;
        }
        return true;
    }

    return false;
}

function get_lockout_time_remaining($username)
{
    $lockout_duration = 900; // 15 minutes

    // Check file-based first
    $file_path = sys_get_temp_dir() . '/edtech_failed_login_' . md5($username);
    if (file_exists($file_path)) {
        $data = json_decode(file_get_contents($file_path), true);
        if ($data && $data['count'] >= 5) {
            $time_remaining = $lockout_duration - (time() - $data['last_attempt']);
            return max(0, $time_remaining);
        }
    }

    // Fallback to session-based
    $key = 'failed_login_' . $username;
    if (!isset($_SESSION[$key])) {
        return 0;
    }

    $failed_data = $_SESSION[$key];
    if ($failed_data['count'] >= 5) {
        $time_remaining = $lockout_duration - (time() - $failed_data['last_attempt']);
        return max(0, $time_remaining);
    }

    return 0;
}
?>

