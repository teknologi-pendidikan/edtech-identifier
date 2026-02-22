<?php
/**
 * Auth Test - Check if login page can read your credentials
 */

echo "=== Authentication Test ===\n\n";

echo "1. Testing direct environment access...\n";
$direct_username = getenv('ADMIN_USERNAME');
$direct_hash = getenv('ADMIN_PASSWORD_HASH');

echo "   Username from getenv(): " . ($direct_username ?: '[EMPTY]') . "\n";
echo "   Hash from getenv(): " . ($direct_hash ? '[SET - ' . strlen($direct_hash) . ' chars]' : '[EMPTY]') . "\n\n";

echo "2. Testing config.php loading...\n";
require_once __DIR__ . '/includes/config.php';

$config_username = getenv('ADMIN_USERNAME');
$config_hash = getenv('ADMIN_PASSWORD_HASH');

echo "   Username after config.php: " . ($config_username ?: '[EMPTY]') . "\n";
echo "   Hash after config.php: " . ($config_hash ? '[SET - ' . strlen($config_hash) . ' chars]' : '[EMPTY]') . "\n\n";

echo "3. Testing auth.php loading (same as login page)...\n";
// Start session like login page does
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

try {
    require_once __DIR__ . '/includes/auth.php';

    echo "   Auth config loaded successfully\n";
    echo "   \$auth_config['username']: " . ($auth_config['username'] ?? '[EMPTY]') . "\n";
    echo "   \$auth_config['password_hash']: " . (!empty($auth_config['password_hash']) ? '[SET - ' . strlen($auth_config['password_hash']) . ' chars]' : '[EMPTY]') . "\n\n";

    if (empty($auth_config['username']) || empty($auth_config['password_hash'])) {
        echo "❌ THIS IS THE PROBLEM!\n";
        echo "The auth config is not loading your credentials properly.\n\n";

        echo "4. Debugging auth_config values...\n";
        echo "   Raw auth_config array:\n";
        foreach ($auth_config as $key => $value) {
            if (strpos($key, 'password') !== false) {
                echo "   $key = " . (empty($value) ? '[EMPTY]' : '[SET]') . "\n";
            } else {
                echo "   $key = " . var_export($value, true) . "\n";
            }
        }

        echo "\n5. Checking what getenv() returns inside auth.php context...\n";
        echo "   getenv('ADMIN_USERNAME'): " . (getenv('ADMIN_USERNAME') ?: '[EMPTY]') . "\n";
        echo "   getenv('ADMIN_PASSWORD_HASH'): " . (getenv('ADMIN_PASSWORD_HASH') ? '[SET]' : '[EMPTY]') . "\n";

    } else {
        echo "✅ Auth config is working correctly!\n";
        echo "The login issue might be somewhere else.\n";
    }

} catch (Exception $e) {
    echo "❌ Error loading auth.php: " . $e->getMessage() . "\n";
}

echo "\n=== END TEST ===\n";
?>
