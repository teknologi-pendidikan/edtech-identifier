<?php
session_start();
require_once __DIR__ . '/security.php';

// Enhanced authentication configuration
$auth_config = [
    'username' => getenv('ADMIN_USERNAME') ?: '',
    'password_hash' => getenv('ADMIN_PASSWORD_HASH') ?: '', // Store bcrypt hash
    'session_timeout' => 3600, // 1 hour in seconds
    'max_login_attempts' => 5,
    'lockout_duration' => 900, // 15 minutes
    // Institutional IP whitelist - IPs that bypass authentication
    'trusted_ip_ranges' => [
        '103.208.94.0/23', // Your organization's IP block
        // Add more IP ranges as needed
    ],
    'ip_bypass_enabled' => getenv('IP_BYPASS_ENABLED') !== 'false' // Can be disabled via environment variable
];

// Check if IP is in CIDR range
function ip_in_cidr($ip, $cidr)
{
    list($subnet, $bits) = explode('/', $cidr);

    // Convert IP addresses to long integers
    $ip = ip2long($ip);
    $subnet = ip2long($subnet);

    if ($ip === false || $subnet === false) {
        return false;
    }

    // Create netmask
    $mask = -1 << (32 - $bits);
    $subnet &= $mask; // Apply mask to subnet

    return ($ip & $mask) == $subnet;
}

// Check if current IP is in trusted range
function is_trusted_ip($ip = null)
{
    global $auth_config;

    if (!$auth_config['ip_bypass_enabled']) {
        return false;
    }

    if ($ip === null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    }

    // Handle proxy headers if needed (be careful with these in production)
    if (empty($ip) && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded_ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($forwarded_ips[0]);
    }

    foreach ($auth_config['trusted_ip_ranges'] as $cidr) {
        if (ip_in_cidr($ip, $cidr)) {
            return true;
        }
    }

    return false;
}

// Auto-authenticate trusted IPs
function auto_authenticate_trusted_ip()
{
    if (is_trusted_ip()) {
        // Create a temporary session for IP-based access
        session_regenerate_id(true);

        $_SESSION['auth'] = true;
        $_SESSION['auth_time'] = time();
        $_SESSION['username'] = 'institutional_user';
        $_SESSION['auth_method'] = 'ip_bypass';
        $_SESSION['fingerprint'] = generate_session_fingerprint();
        $_SESSION['login_ip'] = $_SERVER['REMOTE_ADDR'];

        log_security_event('ip_bypass_login', [
            'ip' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);

        return true;
    }

    return false;
}

// Check if user is logged in
function is_authenticated()
{
    // Check for IP bypass first
    if (is_trusted_ip() && auto_authenticate_trusted_ip()) {
        return true;
    }

    if (!isset($_SESSION['auth']) || !isset($_SESSION['auth_time'])) {
        return false;
    }

    global $auth_config;

    // Check if session has expired
    if (time() - $_SESSION['auth_time'] > $auth_config['session_timeout']) {
        // Session expired, log out
        logout();
        return false;
    }

    // For IP-bypassed sessions, just renew time and continue
    if (isset($_SESSION['auth_method']) && $_SESSION['auth_method'] === 'ip_bypass') {
        if (is_trusted_ip()) {
            $_SESSION['auth_time'] = time();
            return true;
        } else {
            // IP changed, logout
            log_security_event('ip_bypass_session_invalid', [
                'original_ip' => $_SESSION['login_ip'] ?? 'unknown',
                'current_ip' => $_SERVER['REMOTE_ADDR']
            ]);
            logout();
            return false;
        }
    }

    // Check for session hijacking (only for password-based auth)
    $expected_fingerprint = generate_session_fingerprint();
    if (!isset($_SESSION['fingerprint']) || $_SESSION['fingerprint'] !== $expected_fingerprint) {
        log_security_event('session_hijack_attempt', ['expected' => $expected_fingerprint, 'actual' => $_SESSION['fingerprint'] ?? 'none']);
        logout();
        return false;
    }

    // Renew session time
    $_SESSION['auth_time'] = time();
    return true;
}

// Generate session fingerprint for security
function generate_session_fingerprint()
{
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $accept_language = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    $accept_encoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';

    return hash('sha256', $user_agent . $accept_language . $accept_encoding);
}

// Authenticate user with enhanced security
function authenticate($username, $password)
{
    global $auth_config;

    // Check rate limiting
    if (!check_rate_limit('login', 10, 300)) { // 10 attempts per 5 minutes
        log_security_event('rate_limit_exceeded', ['action' => 'login', 'username' => $username]);
        return false;
    }

    // Check if account is locked out
    if (is_locked_out($username)) {
        log_security_event('login_attempt_while_locked', ['username' => $username]);
        return false;
    }

    // Validate credentials
    if (
        $username === $auth_config['username'] &&
        !empty($auth_config['password_hash']) &&
        password_verify($password, $auth_config['password_hash'])
    ) {

        // Clear any failed attempts
        clear_failed_attempts($username);

        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);

        // Set session data
        $_SESSION['auth'] = true;
        $_SESSION['auth_time'] = time();
        $_SESSION['username'] = $username;
        $_SESSION['fingerprint'] = generate_session_fingerprint();
        $_SESSION['login_ip'] = $_SERVER['REMOTE_ADDR'];

        log_security_event('successful_login', ['username' => $username]);
        return true;
    }

    // Record failed attempt
    record_failed_login($username);
    return false;
}

// Generate password hash (for setup)
function generate_password_hash($password)
{
    return password_hash($password, PASSWORD_DEFAULT);
}

// Log out user
function logout()
{
    $username = $_SESSION['username'] ?? 'unknown';
    log_security_event('logout', ['username' => $username]);

    // Clear all session data
    $_SESSION = array();

    // Destroy session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();
}

// Require authentication for admin pages
function require_auth()
{
    if (!is_authenticated()) {
        header('Location: login.php');
        exit;
    }
}

// Get current authentication method
function get_auth_method()
{
    return $_SESSION['auth_method'] ?? 'password';
}

// Check if current user has IP bypass access
function has_ip_bypass()
{
    return isset($_SESSION['auth_method']) && $_SESSION['auth_method'] === 'ip_bypass';
}

// Set secure session configuration
function configure_secure_session()
{
    // Secure session settings
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Strict');

    // Regenerate session ID periodically
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 300) { // Every 5 minutes
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

// Initialize secure session
configure_secure_session();
?>

