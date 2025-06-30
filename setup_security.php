#!/usr/bin/env php
<?php
/**
 * Security Setup Script for EdTech Identifier System
 * Generates secure admin credentials and provides setup instructions
 */

echo "=== EdTech Identifier Security Setup ===\n\n";

// Generate secure admin credentials
echo "1. GENERATING SECURE ADMIN CREDENTIALS:\n";
echo "   Enter a secure password for the admin account:\n";
echo "   Password: ";
$password = trim(fgets(STDIN));

if (strlen($password) < 8) {
    echo "   ERROR: Password must be at least 8 characters long.\n";
    exit(1);
}

$password_hash = password_hash($password, PASSWORD_DEFAULT);
$username = 'admin';

echo "\n2. ENVIRONMENT VARIABLES TO SET:\n";
echo "   Add these to your .env file or server environment:\n\n";
echo "   ADMIN_USERNAME=" . $username . "\n";
echo "   ADMIN_PASSWORD_HASH=" . $password_hash . "\n\n";

echo "3. DATABASE SECURITY RECOMMENDATIONS:\n";
echo "   - Use a dedicated database user with minimal privileges\n";
echo "   - Enable SSL/TLS for database connections\n";
echo "   - Regularly backup your database\n";
echo "   - Monitor for unusual activity\n\n";

echo "4. SERVER SECURITY CHECKLIST:\n";
echo "   □ Enable HTTPS with valid SSL certificate\n";
echo "   □ Set secure file permissions (644 for files, 755 for directories)\n";
echo "   □ Disable directory listing in web server configuration\n";
echo "   □ Set up proper error logging\n";
echo "   □ Configure firewall to block unnecessary ports\n";
echo "   □ Keep PHP and web server updated\n";
echo "   □ Set session.cookie_secure=1 in php.ini for HTTPS\n";
echo "   □ Set session.cookie_httponly=1 in php.ini\n\n";

echo "5. RECOMMENDED PHP.INI SETTINGS:\n";
echo "   expose_php = Off\n";
echo "   display_errors = Off\n";
echo "   log_errors = On\n";
echo "   session.cookie_httponly = 1\n";
echo "   session.cookie_secure = 1 (for HTTPS)\n";
echo "   session.use_strict_mode = 1\n";
echo "   upload_max_filesize = 10M\n";
echo "   post_max_size = 12M\n";
echo "   max_execution_time = 30\n\n";

echo "6. FILE PERMISSIONS:\n";
echo "   Run these commands to set secure permissions:\n";
echo "   chmod 644 *.php\n";
echo "   chmod 644 includes/*.php\n";
echo "   chmod 644 admin/*.php\n";
echo "   chmod 755 temp/ (create if doesn't exist)\n";
echo "   chown www-data:www-data temp/ (or your web server user)\n\n";

echo "7. MONITORING SETUP:\n";
echo "   - Monitor error logs for security events\n";
echo "   - Set up alerts for failed login attempts\n";
echo "   - Regularly review identifier creation logs\n";
echo "   - Monitor file system for unauthorized changes\n\n";

echo "Setup complete! Save the environment variables securely.\n";
echo "Your admin credentials are ready for use.\n\n";

echo "=== SECURITY REMINDERS ===\n";
echo "• Never commit credentials to version control\n";
echo "• Use environment variables for all sensitive data\n";
echo "• Regularly update dependencies and system packages\n";
echo "• Test backup and recovery procedures\n";
echo "• Implement monitoring and alerting\n";
echo "• Conduct regular security audits\n\n";
?>

