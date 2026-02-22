<?php
/**
 * Credential Reset Script for EdTech Identifier System
 * 
 * This script allows you to reset admin credentials securely.
 * Run this ONLY when you need to reset forgotten or compromised credentials.
 */

// CLI only for security - no web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("This script can only be run from command line for security reasons.\n");
}

function reset_credentials() {
    echo "=== EdTech Identifier Credential Reset ===\n\n";
    
    echo "⚠️  WARNING: This will reset your admin credentials!\n";
    echo "This should only be used if:\n";
    echo "- You have forgotten your admin password\n";
    echo "- You suspect credentials have been compromised\n";
    echo "- You need to change the admin username\n\n";
    
    echo "Are you sure you want to continue? (type 'YES' to confirm): ";
    $confirmation = trim(fgets(STDIN));
    
    if ($confirmation !== 'YES') {
        echo "Operation cancelled.\n";
        return false;
    }
    
    // Get new username
    do {
        echo "\nEnter new admin username (3-50 characters, alphanumeric + underscore): ";
        $username = trim(fgets(STDIN));
        
        if (empty($username)) {
            echo "❌ Username cannot be empty.\n";
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
            echo "❌ Username must be 3-50 characters, alphanumeric and underscore only.\n";
        } else {
            break;
        }
    } while (true);
    
    // Get new password
    do {
        echo "Enter new admin password (minimum 12 characters with mixed case, numbers, and symbols): ";
        system('stty -echo'); // Hide password input
        $password = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
        
        echo "Confirm password: ";
        system('stty -echo');
        $password_confirm = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
        
        if (empty($password)) {
            echo "❌ Password cannot be empty.\n";
        } elseif (strlen($password) < 12) {
            echo "❌ Password must be at least 12 characters.\n";
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/', $password)) {
            echo "❌ Password must contain at least one uppercase letter, lowercase letter, number, and special character (@$!%*?&).\n";
        } elseif ($password !== $password_confirm) {
            echo "❌ Password confirmation does not match.\n";
        } else {
            break;
        }
    } while (true);
    
    // Reset credentials
    echo "\nResetting credentials...\n";
    
    return update_env_credentials($username, $password);
}

function update_env_credentials($username, $password) {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $env_file = __DIR__ . '/.env';
    
    $env_content = [];
    if (file_exists($env_file)) {
        $env_content = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    }
    
    $updated_username = false;
    $updated_password = false;
    
    // Update existing entries
    foreach ($env_content as $key => $line) {
        if (str_starts_with($line, 'ADMIN_USERNAME=')) {
            $env_content[$key] = "ADMIN_USERNAME={$username}";
            $updated_username = true;
        } elseif (str_starts_with($line, 'ADMIN_PASSWORD_HASH=')) {
            $env_content[$key] = "ADMIN_PASSWORD_HASH={$hash}";
            $updated_password = true;
        }
    }
    
    // Add if not found
    if (!$updated_username) {
        $env_content[] = "ADMIN_USERNAME={$username}";
    }
    if (!$updated_password) {
        $env_content[] = "ADMIN_PASSWORD_HASH={$hash}";
    }
    
    // Add security settings if not present
    $has_session_timeout = false;
    $has_ip_bypass = false;
    
    foreach ($env_content as $line) {
        if (str_starts_with($line, 'SESSION_TIMEOUT=')) $has_session_timeout = true;
        if (str_starts_with($line, 'IP_BYPASS_ENABLED=')) $has_ip_bypass = true;
    }
    
    if (!$has_session_timeout) {
        $env_content[] = "SESSION_TIMEOUT=3600";
    }
    if (!$has_ip_bypass) {
        $env_content[] = "IP_BYPASS_ENABLED=false";
    }
    
    // Write updated .env file
    $success = file_put_contents($env_file, implode("\n", $env_content) . "\n");
    
    if ($success) {
        // Set proper permissions
        chmod($env_file, 0600);
        
        // Log the reset event
        $log_entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => 'credential_reset',
            'username' => $username,
            'script' => __FILE__
        ];
        error_log('SECURITY: ' . json_encode($log_entry));
        
        echo "✅ Credentials reset successfully!\n";
        echo "Username: {$username}\n";
        echo "Password hash: " . substr($hash, 0, 20) . "...\n\n";
        echo "✅ .env file updated with secure permissions\n";
        echo "\n⚠️  SECURITY RECOMMENDATIONS:\n";
        echo "1. Test login immediately to ensure it works\n";
        echo "2. Clear any active admin sessions\n";
        echo "3. Review security logs for any suspicious activity\n";
        echo "4. Consider updating IP bypass settings if used\n";
        echo "5. Inform other administrators about the reset\n\n";
        return true;
    }
    
    echo "❌ Failed to update .env file. Check file permissions.\n";
    return false;
}

function clear_failed_attempts() {
    echo "\nClearing failed login attempts and rate limits...\n";
    
    // Clear temporary files
    $temp_dir = sys_get_temp_dir();
    $pattern = $temp_dir . '/edtech_*';
    
    $files = glob($pattern);
    $cleared_count = 0;
    
    foreach ($files as $file) {
        if (is_file($file)) {
            if (unlink($file)) {
                $cleared_count++;
            }
        }
    }
    
    echo "✅ Cleared {$cleared_count} temporary security files\n";
    echo "All failed login attempts and rate limits have been reset\n";
}

function show_current_config() {
    echo "=== Current Configuration ===\n";
    
    $env_file = __DIR__ . '/.env';
    if (file_exists($env_file)) {
        $env_content = file_get_contents($env_file);
        
        // Extract and show relevant config (without sensitive data)
        if (preg_match('/ADMIN_USERNAME=(.+)/', $env_content, $matches)) {
            echo "Current Username: " . trim($matches[1]) . "\n";
        } else {
            echo "Username: Not configured\n";
        }
        
        if (preg_match('/ADMIN_PASSWORD_HASH=(.+)/', $env_content, $matches)) {
            $hash = trim($matches[1]);
            echo "Password Hash: " . (empty($hash) ? "Not configured" : "Configured (" . substr($hash, 0, 10) . "...)") . "\n";
        } else {
            echo "Password Hash: Not configured\n";
        }
        
        if (preg_match('/IP_BYPASS_ENABLED=(.+)/', $env_content, $matches)) {
            echo "IP Bypass: " . trim($matches[1]) . "\n";
        }
        
        if (preg_match('/SESSION_TIMEOUT=(.+)/', $env_content, $matches)) {
            $timeout = trim($matches[1]);
            echo "Session Timeout: {$timeout} seconds (" . ($timeout / 60) . " minutes)\n";
        }
    } else {
        echo ".env file not found - system not configured\n";
    }
    
    echo "\n";
}

// Main execution
echo "EdTech Identifier Credential Reset Tool\n";
echo "=====================================\n\n";

if ($argc > 1) {
    switch ($argv[1]) {
        case '--show-config':
            show_current_config();
            break;
            
        case '--clear-lockouts':
            clear_failed_attempts();
            break;
            
        case '--reset':
            show_current_config();
            if (reset_credentials()) {
                clear_failed_attempts();
                echo "\n🎉 Reset completed successfully!\n";
            } else {
                echo "\n❌ Reset failed. Please check file permissions and try again.\n";
                exit(1);
            }
            break;
            
        case '--help':
        default:
            echo "Usage: php reset_credentials.php [option]\n";
            echo "\nOptions:\n";
            echo "  --reset           Reset admin username and password\n";
            echo "  --show-config     Show current configuration\n";
            echo "  --clear-lockouts  Clear failed login attempts and rate limits\n";
            echo "  --help            Show this help message\n";
            echo "\nExamples:\n";
            echo "  php reset_credentials.php --reset\n";
            echo "  php reset_credentials.php --clear-lockouts\n";
            echo "  php reset_credentials.php --show-config\n";
            break;
    }
} else {
    echo "Use --help to see available options or --reset to reset credentials.\n";
}
?>