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
 * Check if user is logged in
 */
function is_logged_in() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/**
 * Require login (redirect if not logged in)
 */
function require_login() {
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
?>
