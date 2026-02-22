<?php
/**
 * ⚠️  DEPRECATED: This file has been replaced with a secure setup system
 *
 * DO NOT USE THIS FILE - It stores passwords in plain text which is insecure!
 *
 * Instead, use the new secure setup system:
 *
 * 1. Run the setup script:
 *    php setup.php
 *
 * 2. Or copy the environment template:
 *    cp .env.template .env
 *    # Then edit .env with your secure settings
 *
 * 3. Set proper file permissions:
 *    chmod 600 .env
 *
 * The new system provides:
 * ✅ Encrypted password storage (bcrypt)
 * ✅ Persistent rate limiting
 * ✅ Enhanced session security
 * ✅ Account lockout protection
 * ✅ IP bypass security (optional)
 * ✅ CSRF protection
 * ✅ Comprehensive logging
 *
 * See AUTH_SECURITY_FIXES.md for complete documentation.
 */

die("❌ This authentication method is deprecated and insecure!\n\n" .
    "Please use the new secure setup system:\n" .
    "1. Run: php setup.php\n" .
    "2. Or configure .env file manually\n" .
    "3. See AUTH_SECURITY_FIXES.md for details\n\n" .
    "The old plain-text password system has been disabled for security.\n");
?>

