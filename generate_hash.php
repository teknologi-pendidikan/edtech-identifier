<?php
// Password hash generator
// Run this script to generate a bcrypt hash for your password

if ($argc < 2) {
    echo "Usage: php generate_hash.php [your_password]\n";
    echo "Example: php generate_hash.php mySecurePassword123\n";
    exit(1);
}

$password = $argv[1];
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "Password: " . $password . "\n";
echo "Hash: " . $hash . "\n";
echo "\nAdd this to your .env file:\n";
echo "ADMIN_PASSWORD_HASH=" . $hash . "\n";
?>

