<?php
// Database configuration
$db_config = [
    'host' => getenv('DB_HOST') ?: '',
    'user' => getenv('DB_USER') ?: '',
    'pass' => getenv('DB_PASS') ?: '',
    'name' => getenv('DB_NAME') ?: ''
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
?>

