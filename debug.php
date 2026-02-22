<?php
/**
 * Debug Database Connection
 * Check if everything is properly configured
 */

require_once 'includes/config.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>Debug - EdTech Identifier</title>";
echo '<link rel="stylesheet" href="assets/style.css">';
echo "</head><body>";
echo '<div class="container" style="max-width: 800px;">';
echo '<h1>🔧 System Debug</h1>';

echo '<div class="code-block">';
echo '<h3>Database Connection Test</h3>';

try {
    $conn = db_connect();
    echo '<p style="color: var(--cds-support-success);">✅ Database connection successful!</p>';

    // Test basic tables
    $tables = ['namespace_mappings', 'identifiers', 'identifier_logs'];
    echo '<h4>Required Tables Check:</h4>';
    foreach ($tables as $table) {
        $result = $conn->query("SHOW TABLES LIKE '$table'");
        if ($result->num_rows > 0) {
            echo "<p style='color: var(--cds-support-success);'>✅ Table '$table' exists</p>";
        } else {
            echo "<p style='color: var(--cds-support-error);'>❌ Table '$table' missing</p>";
        }
    }

    // Check admin_users table
    echo '<h4>Admin Users Table:</h4>';
    try {
        require_once 'includes/auth.php';
        create_admin_table();

        $result = $conn->query("SELECT COUNT(*) as count FROM admin_users");
        $count = $result->fetch_assoc()['count'];
        echo "<p style='color: var(--cds-support-success);'>✅ Admin users table exists with $count users</p>";

    } catch (Exception $e) {
        echo "<p style='color: var(--cds-support-error);'>❌ Admin table error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }

} catch (Exception $e) {
    echo '<p style="color: var(--cds-support-error);">❌ Database connection failed!</p>';
    echo '<p>Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

echo '<h3>Instructions:</h3>';
echo '<ol>';
echo '<li>If database connection failed, update password in <code>includes/config.php</code></li>';
echo '<li>If tables are missing, import the <code>schema.sql</code> file</li>';
echo '<li>If admin table error, check database permissions</li>';
echo '<li>Once everything shows ✅, visit <a href="admin/login.php">Admin Login</a></li>';
echo '</ol>';

echo '<p style="margin-top: var(--cds-spacing-07);"><a href="index.php" class="button">← Back to Main Site</a></p>';
echo '</div></div></body></html>';
?>
