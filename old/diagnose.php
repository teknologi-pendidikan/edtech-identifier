<?php
/**
 * Diagnostic Script for EdTech Identifier Login Issues
 * Run this to check what's preventing login
 */

echo "=== EdTech Identifier Login Diagnostic ===\n\n";

// Check if .env file exists
echo "1. Checking .env file...\n";
if (file_exists(__DIR__ . '/.env')) {
    echo "   ✅ .env file exists\n";
    $env_content = file_get_contents(__DIR__ . '/.env');
    echo "   📋 Content preview:\n";
    echo "   " . str_replace(["\n", "\r"], ["\n   ", ""], substr($env_content, 0, 200)) . "...\n\n";
} else {
    echo "   ❌ .env file is MISSING - This is the problem!\n";
    echo "   📝 You need to create .env file with your setup credentials\n\n";
}

// Load config to check environment variables
require_once __DIR__ . '/includes/config.php';

echo "2. Checking environment variables...\n";
$required_vars = [
    'DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME',
    'ADMIN_USERNAME', 'ADMIN_PASSWORD_HASH', 'BASE_URL'
];

foreach ($required_vars as $var) {
    $value = getenv($var);
    if (!empty($value)) {
        if (strpos($var, 'PASS') !== false) {
            echo "   ✅ $var = [SET]\n";
        } else {
            echo "   ✅ $var = " . substr($value, 0, 20) . (strlen($value) > 20 ? '...' : '') . "\n";
        }
    } else {
        echo "   ❌ $var = [NOT SET]\n";
    }
}

// Check database connection
echo "\n3. Checking database connection...\n";

// Set connection timeout to prevent hanging
ini_set('default_socket_timeout', 5);
ini_set('mysql.connect_timeout', 5);

echo "   🔍 Trying to connect to: {$db_config['host']}\n";
echo "   👤 Using username: {$db_config['user']}\n";
echo "   🗄️  Database name: {$db_config['name']}\n";

// Try manual connection with error handling
$conn = null;
try {
    // Suppress warnings and capture them
    $conn = @new mysqli($db_config['host'], $db_config['user'], $db_config['pass'], $db_config['name']);

    if ($conn && $conn->connect_error) {
        echo "   ❌ MySQL Connection Error: " . $conn->connect_error . "\n";
        $conn = null;
    } elseif ($conn) {
        echo "   ✅ Database connection successful\n";

        // Quick ping test
        if ($conn->ping()) {
            echo "   ✅ Database server is responsive\n";
        } else {
            echo "   ⚠️  Database connected but not responding to ping\n";
        }
    }
} catch (Exception $e) {
    echo "   ❌ Connection Exception: " . $e->getMessage() . "\n";
    $conn = null;
}

if ($conn) {
    echo "   ✅ Database connection successful\n";

    // Check if required tables exist
    echo "\n4. Checking database schema...\n";
    $required_tables = ['namespace_mappings', 'identifiers', 'identifier_logs'];
    $missing_tables = [];

    foreach ($required_tables as $table) {
        $result = $conn->query("SHOW TABLES LIKE '$table'");
        if ($result && $result->num_rows > 0) {
            echo "   ✅ Table '$table' exists\n";
        } else {
            echo "   ❌ Table '$table' is missing\n";
            $missing_tables[] = $table;
        }
    }

    if (!empty($missing_tables)) {
        echo "\n   🔧 SCHEMA ISSUE FOUND!\n";
        echo "   📝 Missing tables: " . implode(', ', $missing_tables) . "\n";
        echo "   💡 You need to import schema.sql into your database\n";
        echo "   💡 Run: mysql -u {$db_config['user']} -p {$db_config['name']} < schema.sql\n\n";
    } else {
        echo "\n   ✅ All required tables exist\n";
    }

    $conn->close();
} else {
    echo "   ❌ Database connection failed\n";
    echo "\n   🔧 TROUBLESHOOTING TIPS:\n";
    echo "   1. Check if database server is running\n";
    echo "   2. Verify database name exists: {$db_config['name']}\n";
    echo "   3. Check username/password in cPanel > MySQL Databases\n";
    echo "   4. Ensure user has permissions on this database\n";
    echo "   5. Try connecting via phpMyAdmin to test credentials\n";
    echo "   6. Check if host should be 'localhost' or an IP address\n";

    // Common cPanel database troubleshooting
    if (strpos($db_config['name'], '_') !== false) {
        $prefix = explode('_', $db_config['name'])[0];
        echo "   7. cPanel detected - ensure username is: {$prefix}_username (not just 'username')\n";
    }
}

// Check auth config (even if DB failed)
echo "\n5. Checking authentication configuration...\n";

// Check auth.php file exists
if (!file_exists(__DIR__ . '/includes/auth.php')) {
    echo "   ❌ auth.php file missing\n";
} else {
    echo "   ✅ auth.php file exists\n";
}

// Check security.php dependency
if (!file_exists(__DIR__ . '/includes/security.php')) {
    echo "   ❌ security.php file missing\n";
} else {
    echo "   ✅ security.php file exists\n";
}

// Manually check auth config without loading full auth system
echo "   🔍 Testing auth variables directly...\n";

$auth_username = getenv('ADMIN_USERNAME');
$auth_hash = getenv('ADMIN_PASSWORD_HASH');

if (!empty($auth_username)) {
    echo "   ✅ Admin username: " . $auth_username . "\n";
} else {
    echo "   ❌ Admin username not set\n";
}

if (!empty($auth_hash) && strlen($auth_hash) > 10) {
    echo "   ✅ Password hash is set (length: " . strlen($auth_hash) . ")\n";
    // Verify it looks like a bcrypt hash
    if (substr($auth_hash, 0, 4) === '$2y$' || substr($auth_hash, 0, 4) === '$2b$') {
        echo "   ✅ Password hash format looks correct (bcrypt)\n";
    } else {
        echo "   ⚠️  Password hash format unusual (should start with $2y$ or $2b$)\n";
    }
} else {
    echo "   ❌ Password hash not set or too short - This prevents login!\n";
}

// Test a simple login simulation
if (!empty($auth_username) && !empty($auth_hash)) {
    echo "\n6. Testing login simulation...\n";

    // Test if we can verify against the hash (using a dummy password)
    if (function_exists('password_verify')) {
        echo "   ✅ password_verify() function available\n";

        // Try to access login page
        echo "   🔍 Checking login page accessibility...\n";
        if (file_exists(__DIR__ . '/admin/login.php')) {
            echo "   ✅ Login page exists at admin/login.php\n";
        } else {
            echo "   ❌ Login page missing at admin/login.php\n";
        }
    } else {
        echo "   ❌ password_verify() function not available\n";
    }
} else {
    echo "\n6. ❌ Cannot test login - missing credentials\n";
}

echo "\n=== SOLUTION ===\n";
if (!isset($conn) || !$conn) {
    echo "🔧 DATABASE CONNECTION ISSUE:\n";
    echo "1. Go to cPanel > MySQL Databases\n";
    echo "2. Verify database '{$db_config['name']}' exists\n";
    echo "3. Check user '{$db_config['user']}' has ALL PRIVILEGES on this database\n";
    echo "4. Test connection via phpMyAdmin\n";
    echo "5. Import schema.sql once connected\n\n";
} elseif (isset($missing_tables) && !empty($missing_tables)) {
    echo "🔧 DATABASE SCHEMA MISSING:\n";
    echo "Import schema.sql using one of these methods:\n";
    echo "• phpMyAdmin: Import tab > Choose schema.sql > Go\n";
    echo "• Command line: mysql -u {$db_config['user']} -p {$db_config['name']} < schema.sql\n\n";
} else {
    echo "✅ CONFIGURATION LOOKS GOOD:\n";
    echo "Try these steps:\n";
    echo "1. Clear browser cookies/cache\n";
    echo "2. Try incognito/private browsing mode\n";
    echo "3. Access login directly: {$base_url}/admin/login.php\n";
    echo "4. Check for any PHP errors on the login page\n";
}

echo "\n💡 CPANEL QUICK FIXES:\n";
echo "• Database issue? cPanel > MySQL Databases > Check user privileges\n";
echo "• Import schema? cPanel > phpMyAdmin > Select DB > Import > schema.sql\n";
echo "• Check errors? cPanel > Error Logs (or check /logs folder)\n";

echo "\n=== END DIAGNOSTIC ===\n";
?>
