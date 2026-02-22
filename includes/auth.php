<?php
// Initialize secure session first
if (session_status() === PHP_SESSION_NONE) {
    // Configure secure session settings before starting
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_maxlifetime', 3600);
    session_start();
}

require_once __DIR__ . '/security.php';

// Enhanced authentication configuration with better security
$auth_config = [
    'username' => getenv('ADMIN_USERNAME') ?: 'admin',
    'password_hash' => getenv('ADMIN_PASSWORD_HASH') ?: '',
    'session_timeout' => (int)(getenv('SESSION_TIMEOUT') ?: 3600), // 1 hour default
    'max_login_attempts' => 5,
    'lockout_duration' => 900, // 15 minutes
    'require_2fa' => getenv('REQUIRE_2FA') === 'true',

    // Enhanced IP whitelist with validation
    'trusted_ip_ranges' => array_filter([
        getenv('TRUSTED_IP_1'),
        getenv('TRUSTED_IP_2'),
        getenv('TRUSTED_IP_3')
        // Add more IPs via environment variables
    ]),
    'ip_bypass_enabled' => getenv('IP_BYPASS_ENABLED') === 'true', // Disabled by default
    'strict_ip_validation' => getenv('STRICT_IP_VALIDATION') !== 'false'
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

// Enhanced IP validation with security checks
function is_trusted_ip($ip = null)
{
    global $auth_config;

    if (!$auth_config['ip_bypass_enabled'] || empty($auth_config['trusted_ip_ranges'])) {
        return false;
    }

    $ip = get_real_ip_address($ip);
    if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }

    // Block private/reserved IPs if strict validation is enabled
    if ($auth_config['strict_ip_validation']) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            log_security_event('blocked_private_ip_bypass', ['ip' => $ip]);
            return false;
        }
    }

    foreach ($auth_config['trusted_ip_ranges'] as $cidr) {
        if (!empty($cidr) && ip_in_cidr($ip, $cidr)) {
            // Additional validation: check reverse DNS if configured
            if (getenv('VALIDATE_REVERSE_DNS') === 'true') {
                $hostname = gethostbyaddr($ip);
                $allowed_domains = explode(',', getenv('TRUSTED_DOMAINS') ?: '');
                $is_valid_domain = false;

                foreach ($allowed_domains as $domain) {
                    if (!empty($domain) && str_ends_with($hostname, trim($domain))) {
                        $is_valid_domain = true;
                        break;
                    }
                }

                if (!$is_valid_domain && !empty($allowed_domains[0])) {
                    log_security_event('failed_reverse_dns_validation', [
                        'ip' => $ip,
                        'hostname' => $hostname
                    ]);
                    return false;
                }
            }

            return true;
        }
    }

    return false;
}

// Get real IP address with security considerations
function get_real_ip_address($fallback_ip = null) {
    $ip = $fallback_ip ?: $_SERVER['REMOTE_ADDR'] ?? '';

    // Only trust proxy headers if explicitly configured
    if (getenv('TRUST_PROXY_HEADERS') === 'true') {
        $trusted_proxies = array_filter(explode(',', getenv('TRUSTED_PROXIES') ?: ''));

        // Only check proxy headers if current IP is a trusted proxy
        if (!empty($trusted_proxies)) {
            foreach ($trusted_proxies as $proxy) {
                if (ip_in_cidr($ip, trim($proxy))) {
                    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                        $forwarded_ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                        $real_ip = trim($forwarded_ips[0]);
                        if (filter_var($real_ip, FILTER_VALIDATE_IP)) {
                            return $real_ip;
                        }
                    }
                    break;
                }
            }
        }
    }

    return $ip;
}

// Secure IP-based authentication with enhanced validation
function auto_authenticate_trusted_ip()
{
    if (!is_trusted_ip()) {
        return false;
    }

    $real_ip = get_real_ip_address();

    // Additional security checks for IP bypass
    if (check_ip_bypass_abuse($real_ip)) {
        log_security_event('ip_bypass_abuse_detected', ['ip' => $real_ip]);
        return false;
    }

    // Create secure session for IP-based access
    session_regenerate_id(true);

    $_SESSION['auth'] = true;
    $_SESSION['auth_time'] = time();
    $_SESSION['username'] = 'ip_user_' . substr(md5($real_ip), 0, 8);
    $_SESSION['auth_method'] = 'ip_bypass';
    $_SESSION['fingerprint'] = generate_session_fingerprint();
    $_SESSION['login_ip'] = $real_ip;
    $_SESSION['ip_bypass_start'] = time();

    // Limit IP bypass session duration
    $_SESSION['ip_bypass_expires'] = time() + (int)(getenv('IP_BYPASS_DURATION') ?: 7200); // 2 hours default

    log_security_event('ip_bypass_login', [
        'ip' => $real_ip,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'expires_at' => date('Y-m-d H:i:s', $_SESSION['ip_bypass_expires'])
    ]);

    return true;
}

// Check for IP bypass abuse patterns
function check_ip_bypass_abuse($ip) {
    $file_path = sys_get_temp_dir() . '/edtech_ip_bypass_' . md5($ip);

    if (!file_exists($file_path)) {
        file_put_contents($file_path, json_encode([
            'count' => 1,
            'first_access' => time(),
            'last_access' => time()
        ]));
        return false;
    }

    $data = json_decode(file_get_contents($file_path), true);
    if (!$data) return false;

    // Reset counter if 24 hours have passed
    if (time() - $data['first_access'] > 86400) {
        $data = ['count' => 1, 'first_access' => time(), 'last_access' => time()];
        file_put_contents($file_path, json_encode($data));
        return false;
    }

    $data['count']++;
    $data['last_access'] = time();
    file_put_contents($file_path, json_encode($data));

    // Block if more than 50 IP bypass attempts in 24 hours
    return $data['count'] > 50;
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

    // Enhanced validation for IP-bypassed sessions
    if (isset($_SESSION['auth_method']) && $_SESSION['auth_method'] === 'ip_bypass') {
        $current_ip = get_real_ip_address();

        // Check if IP bypass session has expired
        if (isset($_SESSION['ip_bypass_expires']) && time() > $_SESSION['ip_bypass_expires']) {
            log_security_event('ip_bypass_session_expired', [
                'original_ip' => $_SESSION['login_ip'] ?? 'unknown',
                'expired_at' => date('Y-m-d H:i:s', $_SESSION['ip_bypass_expires'])
            ]);
            logout();
            return false;
        }

        if (is_trusted_ip($current_ip)) {
            // Verify IP hasn't changed (prevents session hijacking)
            if ($current_ip !== $_SESSION['login_ip']) {
                log_security_event('ip_bypass_session_ip_mismatch', [
                    'original_ip' => $_SESSION['login_ip'] ?? 'unknown',
                    'current_ip' => $current_ip
                ]);
                logout();
                return false;
            }

            $_SESSION['auth_time'] = time();
            return true;
        } else {
            log_security_event('ip_bypass_session_invalid', [
                'original_ip' => $_SESSION['login_ip'] ?? 'unknown',
                'current_ip' => $current_ip
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

// Enhanced authentication with better security
function authenticate($username, $password)
{
    global $auth_config;

    // Input validation
    if (empty($username) || empty($password)) {
        log_security_event('login_attempt_empty_credentials');
        return false;
    }

    // Check if credentials are properly configured
    if (empty($auth_config['username']) || empty($auth_config['password_hash'])) {
        log_security_event('login_attempt_unconfigured_system', ['username' => $username]);
        return ['error' => 'system_not_configured'];
    }

    // Check persistent rate limiting (file-based)
    if (!check_persistent_rate_limit('login', get_real_ip_address(), 10, 300)) {
        log_security_event('rate_limit_exceeded', ['action' => 'login', 'username' => $username, 'ip' => get_real_ip_address()]);
        return false;
    }

    // Check if account is locked out
    if (is_locked_out($username)) {
        $remaining = get_lockout_time_remaining($username);
        log_security_event('login_attempt_while_locked', ['username' => $username, 'remaining_seconds' => $remaining]);
        return false;
    }

    // Validate credentials with timing attack protection
    $valid_username = hash_equals($auth_config['username'], $username);
    $valid_password = false;

    if (!empty($auth_config['password_hash'])) {
        $valid_password = password_verify($password, $auth_config['password_hash']);
    }

    if ($valid_username && $valid_password) {
        // Clear any failed attempts
        clear_failed_attempts($username);
        clear_persistent_rate_limit('login', get_real_ip_address());

        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);

        // Set secure session data
        $_SESSION['auth'] = true;
        $_SESSION['auth_time'] = time();
        $_SESSION['username'] = $username;
        $_SESSION['auth_method'] = 'password';
        $_SESSION['fingerprint'] = generate_session_fingerprint();
        $_SESSION['login_ip'] = get_real_ip_address();
        $_SESSION['last_activity'] = time();

        // Set session expiry
        $_SESSION['session_expires'] = time() + $auth_config['session_timeout'];

        log_security_event('successful_login', [
            'username' => $username,
            'ip' => get_real_ip_address(),
            'session_expires' => date('Y-m-d H:i:s', $_SESSION['session_expires'])
        ]);

        return true;
    }

    // Record failed attempt with detailed info
    record_failed_login($username);
    record_persistent_failed_attempt('login', get_real_ip_address());

    log_security_event('failed_login_attempt', [
        'username' => $username,
        'ip' => get_real_ip_address(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);

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
    // Only set session ini settings if session is not already active
    if (session_status() === PHP_SESSION_NONE) {
        // Secure session settings
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
        ini_set('session.use_strict_mode', 1);
        ini_set('session.cookie_samesite', 'Strict');
    }

    // Regenerate session ID periodically (this can be done when session is active)
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

