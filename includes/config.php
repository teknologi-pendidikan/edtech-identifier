<?php
/**
 * Simple Database Configuration
 * EdTech Identifier System - Fresh & Simple Version
 *
 * 🔧 SETUP REQUIRED:
 * 1. Replace 'ADD_YOUR_PASSWORD_HERE' below with your database password
 * 2. Visit /debug.php to test the connection
 * 3. If connection works, visit /admin/login.php to create admin user
 */

// Database configuration - modify these values for your setup
$db_config = [
    'host' => 'localhost',
    'user' => 'edtechdptsi_tGOH837D',  // Your existing username
    'pass' => 'ADD_YOUR_PASSWORD_HERE',  // ⚠️  ADD YOUR DATABASE PASSWORD HERE
    'name' => 'edtechdptsi_urn625'     // Your existing database
];

// Simple database connection function
function db_connect() {
    global $db_config;

    // Check if password is set
    if ($db_config['pass'] === 'ADD_YOUR_PASSWORD_HERE' || empty($db_config['pass'])) {
        die("
        <div style='background: #161616; color: #f4f4f4; font-family: Arial, sans-serif; padding: 40px; margin: 20px; border-radius: 8px; border: 2px solid #da1e28;'>
            <h1 style='color: #ff8389; margin-bottom: 20px;'>⚠️ Database Configuration Required</h1>
            <p style='margin-bottom: 15px;'>You need to set your database password in the configuration file.</p>
            <p style='margin-bottom: 20px;'><strong>Steps to fix:</strong></p>
            <ol style='margin-left: 20px; margin-bottom: 20px;'>
                <li>Open <code style='background: #393939; padding: 3px 6px; border-radius: 3px;'>includes/config.php</code></li>
                <li>Find the line with <code style='background: #393939; padding: 3px 6px; border-radius: 3px;'>'pass' => 'ADD_YOUR_PASSWORD_HERE'</code></li>
                <li>Replace it with your actual database password</li>
                <li>Save the file and refresh this page</li>
            </ol>
            <p style='color: #8d8d8d; font-size: 0.9rem;'>File location: includes/config.php</p>
        </div>
        ");
    }

    $conn = new mysqli(
        $db_config['host'],
        $db_config['user'],
        $db_config['pass'],
        $db_config['name']
    );

    if ($conn->connect_error) {
        die("
        <div style='background: #161616; color: #f4f4f4; font-family: Arial, sans-serif; padding: 40px; margin: 20px; border-radius: 8px; border: 2px solid #da1e28;'>
            <h1 style='color: #ff8389; margin-bottom: 20px;'>❌ Database Connection Failed</h1>
            <p style='margin-bottom: 15px;'>Could not connect to the database.</p>
            <p style='margin-bottom: 20px;'><strong>Error:</strong> " . htmlspecialchars($conn->connect_error) . "</p>
            <p style='margin-bottom: 20px;'><strong>Check these settings in includes/config.php:</strong></p>
            <ul style='margin-left: 20px; margin-bottom: 20px;'>
                <li>Host: <code style='background: #393939; padding: 3px 6px; border-radius: 3px;'>" . htmlspecialchars($db_config['host']) . "</code></li>
                <li>User: <code style='background: #393939; padding: 3px 6px; border-radius: 3px;'>" . htmlspecialchars($db_config['user']) . "</code></li>
                <li>Database: <code style='background: #393939; padding: 3px 6px; border-radius: 3px;'>" . htmlspecialchars($db_config['name']) . "</code></li>
            </ul>
        </div>");
    }

    return $conn;
}

// Base URL
$base_url = 'https://urn.edtech.or.id';

// Simple error function
function show_error($message) {
    echo "<div style='color: red; padding: 15px; margin: 10px 0; background: #ff00001a; border: 1px solid red; border-radius: 4px;'>";
    echo "❌ " . htmlspecialchars($message);
    echo "</div>";
}

// Simple success function
function show_success($message) {
    echo "<div style='color: green; padding: 15px; margin: 10px 0; background: #00800020; border: 1px solid green; border-radius: 4px;'>";
    echo "✅ " . htmlspecialchars($message);
    echo "</div>";
}

// Simple HTML escape
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}
?>
