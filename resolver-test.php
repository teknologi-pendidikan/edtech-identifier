<?php
/**
 * Resolver Test Page
 * Test the identifier resolution system
 */

require_once 'includes/config.php';

echo "<!DOCTYPE html><html><head><title>Resolver Test - EdTech Identifier</title>";
echo '<link rel="stylesheet" href="assets/style.css">';
echo "</head><body>";
echo '<div class="container" style="max-width: 800px;">';
echo '<h1>🔗 Resolver System Test</h1>';

echo '<div class="code-block">';

try {
    $conn = db_connect();
    echo '<p style="color: var(--cds-support-success);">✅ Database connection successful</p>';

    // Check required tables
    echo '<h3>Database Tables Check:</h3>';
    $required_tables = ['identifiers', 'namespace_mappings', 'identifier_logs'];

    foreach ($required_tables as $table) {
        $result = $conn->query("SHOW TABLES LIKE '$table'");
        if ($result->num_rows > 0) {
            echo "<p style='color: green;'>✅ Table '$table' exists</p>";
        } else {
            echo "<p style='color: red;'>❌ Table '$table' missing</p>";
        }
    }

    // Get sample identifiers for testing
    echo '<h3>Available Test Identifiers:</h3>';
    $result = $conn->query("
        SELECT i.doi, i.target_url, i.title, nm.long_form, nm.short_form
        FROM identifiers i
        JOIN namespace_mappings nm ON i.namespace_id = nm.id
        WHERE i.STATUS = 'active'
        LIMIT 10
    ");

    if ($result && $result->num_rows > 0) {
        echo '<table style="border-collapse: collapse; width: 100%;">';
        echo '<tr style="border-bottom: 1px solid #ccc;">';
        echo '<th style="text-align: left; padding: 8px;">DOI</th>';
        echo '<th style="text-align: left; padding: 8px;">Title</th>';
        echo '<th style="text-align: left; padding: 8px;">Target</th>';
        echo '<th style="text-align: left; padding: 8px;">Test Links</th>';
        echo '</tr>';

        while ($row = $result->fetch_assoc()) {
            $doi = htmlspecialchars($row['doi']);
            $title = htmlspecialchars($row['title'] ?: 'No title');
            $target = htmlspecialchars($row['target_url']);

            echo '<tr style="border-bottom: 1px solid #eee;">';
            echo '<td style="padding: 8px; font-family: monospace;">' . $doi . '</td>';
            echo '<td style="padding: 8px;">' . substr($title, 0, 30) . (strlen($title) > 30 ? '...' : '') . '</td>';
            echo '<td style="padding: 8px;"><a href="' . $target . '" target="_blank" rel="noopener">🔗</a></td>';
            echo '<td style="padding: 8px;">';

            // Test links
            $encoded_doi = urlencode($row['doi']);
            echo '<a href="resolve.php?id=' . $encoded_doi . '" target="_blank" style="margin-right: 10px;">Query</a>';
            echo '<a href="' . $row['doi'] . '" target="_blank">Direct</a>';

            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';

        echo '<h3>URL Patterns to Test:</h3>';
        echo '<ul>';
        echo '<li><strong>Query Method:</strong> <code>/resolve.php?id=edtech.journal/2025.001</code></li>';
        echo '<li><strong>Path Method:</strong> <code>/resolve/edtech.journal/2025.001</code> (needs URL rewriting)</li>';
        echo '<li><strong>Direct Method:</strong> <code>/edtech.journal/2025.001</code> (needs URL rewriting)</li>';
        echo '</ul>';

    } else {
        echo '<p style="color: orange;">⚠️ No identifiers found. Create some identifiers first.</p>';
        echo '<p><a href="admin/identifiers.php?action=add">Create Test Identifier</a></p>';
    }

    // Test resolution functionality
    if (isset($_GET['test_id'])) {
        echo '<h3>Resolution Test Result:</h3>';

        $test_id = $_GET['test_id'];
        echo '<p><strong>Testing identifier:</strong> <code>' . htmlspecialchars($test_id) . '</code></p>';

        // Simulate resolution process
        if (preg_match('#^([a-zA-Z0-9\.]+)/(.+)$#', $test_id, $matches)) {
            $prefix = $matches[1];
            $suffix = $matches[2];

            $stmt = $conn->prepare("
                SELECT i.*, nm.long_form, nm.short_form
                FROM identifiers i
                JOIN namespace_mappings nm ON i.namespace_id = nm.id
                WHERE (nm.long_form = ? OR nm.short_form = ?)
                  AND i.suffix = ?
                  AND i.STATUS = 'active'
            ");
            $stmt->bind_param("sss", $prefix, $prefix, $suffix);
            $stmt->execute();
            $test_result = $stmt->get_result()->fetch_assoc();

            if ($test_result) {
                echo '<p style="color: green;">✅ Resolution successful!</p>';
                echo '<p><strong>Target URL:</strong> <a href="' . htmlspecialchars($test_result['target_url']) . '" target="_blank">' . htmlspecialchars($test_result['target_url']) . '</a></p>';
                echo '<p><strong>Title:</strong> ' . htmlspecialchars($test_result['title'] ?: 'No title') . '</p>';
            } else {
                echo '<p style="color: red;">❌ Identifier not found</p>';
            }
        } else {
            echo '<p style="color: red;">❌ Invalid identifier format</p>';
        }
    }

    // Show recent resolution logs
    echo '<h3>Recent Resolution Logs:</h3>';
    $logs = $conn->query("
        SELECT doi, action, details, ip_address, created_at
        FROM identifier_logs
        WHERE action = 'resolve'
        ORDER BY created_at DESC
        LIMIT 5
    ");

    if ($logs && $logs->num_rows > 0) {
        echo '<table style="border-collapse: collapse; width: 100%;">';
        echo '<tr style="border-bottom: 1px solid #ccc;">';
        echo '<th style="text-align: left; padding: 8px;">DOI</th>';
        echo '<th style="text-align: left; padding: 8px;">Details</th>';
        echo '<th style="text-align: left; padding: 8px;">IP</th>';
        echo '<th style="text-align: left; padding: 8px;">Time</th>';
        echo '</tr>';

        while ($log = $logs->fetch_assoc()) {
            echo '<tr style="border-bottom: 1px solid #eee;">';
            echo '<td style="padding: 8px; font-family: monospace;">' . htmlspecialchars($log['doi']) . '</td>';
            echo '<td style="padding: 8px;">' . htmlspecialchars($log['details']) . '</td>';
            echo '<td style="padding: 8px;">' . htmlspecialchars($log['ip_address']) . '</td>';
            echo '<td style="padding: 8px;">' . date('M j, H:i', strtotime($log['created_at'])) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p>No resolution logs yet.</p>';
    }

} catch (Exception $e) {
    echo '<p style="color: red;">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

echo '</div>';

echo '<p style="margin-top: var(--cds-spacing-07);">';
echo '<a href="resolve.php" class="button">Test Resolver Interface</a> ';
echo '<a href="admin/identifiers.php" class="button">Manage Identifiers</a> ';
echo '<a href="index.php" class="button">← Back to Main Site</a>';
echo '</p>';

echo '</div></body></html>';
?>
