<?php
session_start();

// Simple authentication configuration
$auth_config = [
    'username' => '',
    'password' => '', // You should change this to a secure password
    'session_timeout' => 3600 // 1 hour in seconds
];

// Check if user is logged in
function is_authenticated()
{
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

    // Renew session time
    $_SESSION['auth_time'] = time();
    return true;
}

// Authenticate user
function authenticate($username, $password)
{
    global $auth_config;

    if ($username === $auth_config['username'] && $password === $auth_config['password']) {
        $_SESSION['auth'] = true;
        $_SESSION['auth_time'] = time();
        $_SESSION['username'] = $username;
        return true;
    }

    return false;
}

// Log out user
function logout()
{
    unset($_SESSION['auth']);
    unset($_SESSION['auth_time']);
    unset($_SESSION['username']);
    session_destroy();
}
?>

