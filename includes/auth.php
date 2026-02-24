<?php
/**
 * Simple Authentication System
 * EdTech Identifier System - Fresh & Simple Version
 */

// Session configuration for hosting compatibility
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

// Cloudflare Zero Trust configuration
define('CF_TEAM_DOMAIN', 'teknologipendidikan.cloudflareaccess.com'); // Configure your team domain
define('CF_AUD_TAG', 'edtech-identifier'); // Configure your application audience tag

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Create admin user table (run this once)
 */
function create_admin_table() {
    try {
        $conn = db_connect();

        $sql = "CREATE TABLE IF NOT EXISTS admin_users (
            id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(50) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            email VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_login TIMESTAMP NULL,
            INDEX idx_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($sql)) {
            return true;
        } else {
            error_log("Failed to create admin_users table: " . $conn->error);
            return false;
        }
    } catch (Exception $e) {
        error_log("Exception creating admin_users table: " . $e->getMessage());
        return false;
    }
}

/**
 * Create default admin user (run this once)
 */
function create_default_admin($username = 'admin', $password = 'admin123') {
    $conn = db_connect();

    // Check if admin exists
    $stmt = $conn->prepare("SELECT id FROM admin_users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        return "Admin user already exists";
    }

    // Create admin user
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO admin_users (username, password_hash) VALUES (?, ?)");
    $stmt->bind_param("ss", $username, $password_hash);

    if ($stmt->execute()) {
        return "Admin user created successfully";
    } else {
        return "Error creating admin user";
    }
}

/**
 * Login function
 */
function login($username, $password) {
    try {
        $conn = db_connect();

        $stmt = $conn->prepare("SELECT id, password_hash FROM admin_users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password_hash'])) {
                // Set session
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = $user['id'];
                $_SESSION['admin_username'] = $username;

                // Update last login
                $stmt = $conn->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
                $stmt->bind_param("i", $user['id']);
                $stmt->execute();

                return true;
            }
        }

        return false;
    } catch (Exception $e) {
        error_log("Login error: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if user is accessing through Cloudflare Zero Trust
 */
function is_cloudflare_authenticated() {
    // Check for Cloudflare Access headers
    $cf_email = $_SERVER['HTTP_CF_ACCESS_AUTHENTICATED_USER_EMAIL'] ?? null;
    $cf_jwt = $_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] ?? null;

    // Basic validation - email exists and JWT is present
    if ($cf_email && $cf_jwt) {
        // Optional: Add JWT validation here for extra security
        // For now, we'll trust Cloudflare's authentication
        return [
            'authenticated' => true,
            'email' => $cf_email,
            'method' => 'cloudflare_zero_trust'
        ];
    }

    return ['authenticated' => false];
}

/**
 * Validate Cloudflare JWT (optional enhanced security)
 */
function validate_cloudflare_jwt($jwt) {
    // This is a basic implementation
    // For production, implement proper JWT validation with Cloudflare's public keys
    try {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return false;
        }

        $payload = json_decode(base64_decode($parts[1]), true);

        // Check if token is expired
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return false;
        }

        // Check audience (your app identifier)
        if (isset($payload['aud']) && !empty(CF_AUD_TAG)) {
            if (!in_array(CF_AUD_TAG, (array)$payload['aud'])) {
                return false;
            }
        }

        return true;
    } catch (Exception $e) {
        error_log("Cloudflare JWT validation error: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if user is logged in (traditional or Cloudflare)
 */
function is_logged_in() {
    // Check traditional session login
    if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
        return true;
    }

    // Check Cloudflare Zero Trust authentication
    $cf_auth = is_cloudflare_authenticated();
    if ($cf_auth['authenticated']) {
        // Set session variables for Cloudflare authenticated users
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $cf_auth['email'];
        $_SESSION['admin_auth_method'] = 'cloudflare_zero_trust';
        $_SESSION['cf_email'] = $cf_auth['email'];
        return true;
    }

    return false;
}

/**
 * Require login (redirect if not logged in)
 */
function require_login() {
    if (!is_logged_in()) {
        // Check if this is a Cloudflare Zero Trust request
        $cf_auth = is_cloudflare_authenticated();
        if (!$cf_auth['authenticated']) {
            // Log the access attempt for security monitoring
            error_log("Unauthorized access attempt from IP: " . ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown'));

            header('Location: login.php');
            exit;
        }
    }
}

/**
 * Enhanced require login with Cloudflare bypass logging
 */
function require_login_with_audit() {
    $cf_auth = is_cloudflare_authenticated();

    if ($cf_auth['authenticated']) {
        // Log Cloudflare Zero Trust access
        error_log("Cloudflare Zero Trust access: " . $cf_auth['email'] . " from IP: " . ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        return;
    }

    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Logout function
 */
function logout() {
    session_destroy();
    header('Location: login.php');
    exit;
}

/**
 * Get current admin username
 */
function get_admin_username() {
    return $_SESSION['admin_username'] ?? 'Unknown';
}

/**
 * Get authentication method
 */
function get_auth_method() {
    return $_SESSION['admin_auth_method'] ?? 'traditional';
}

/**
 * Get user info with authentication details
 */
function get_user_info() {
    $auth_method = get_auth_method();

    return [
        'username' => get_admin_username(),
        'auth_method' => $auth_method,
        'is_cloudflare' => ($auth_method === 'cloudflare_zero_trust'),
        'cf_email' => $_SESSION['cf_email'] ?? null
    ];
}

/**
 * Check if current session is from Cloudflare Zero Trust
 */
function is_cloudflare_session() {
    return get_auth_method() === 'cloudflare_zero_trust';
}
?>
