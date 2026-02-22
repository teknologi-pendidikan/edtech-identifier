<?php
// EdTech Identifier System Configuration
// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    $env_lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($env_lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue; // Skip comments
        }
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            put_env_var($_SERVER, $key, $value);
            putenv("$key=$value");
        }
    }
}

// Helper function to safely set environment variables
function put_env_var(&$array, $key, $value) {
    if (!isset($array[$key])) {
        $array[$key] = $value;
    }
}

// Database configuration
$db_config = [
    'host' => getenv('DB_HOST') ?: 'localhost',
    'user' => getenv('DB_USER') ?: '',
    'pass' => getenv('DB_PASS') ?: '',
    'name' => getenv('DB_NAME') ?: 'edtech_identifier'
];

// Create connection function to avoid repeating code
function create_db_connection($config)
{
    $conn = new mysqli($config['host'], $config['user'], $config['pass'], $config['name']);
    if ($conn->connect_error) {
        return false;
    }
    return $conn;
}

// Base URL configuration
$base_url = getenv('BASE_URL') ?: 'http://localhost';

// Security functions
function escape_html($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

function validate_identifier($identifier) {
    return preg_match('/^[a-zA-Z0-9\.\-_\/]+$/', $identifier);
}
?>
