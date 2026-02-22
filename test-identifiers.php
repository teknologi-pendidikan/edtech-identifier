<?php
/**
 * Test Identifier Creation
 * Quick test to debug identifier creation issues
 */

require_once 'includes/config.php';

echo "<!DOCTYPE html><html><head><title>Test - EdTech Identifier</title>";
echo '<link rel="stylesheet" href="assets/style.css">';
echo "</head><body>";
echo '<div class="container" style="max-width: 800px;">';
echo '<h1>🔍 Identifier Creation Test</h1>';

echo '<div class="code-block">';

try {
    $conn = db_connect();
    echo '<p style="color: var(--cds-support-success);">✅ Database connection successful</p>';

    // Check namespace mappings
    echo '<h3>Namespace Mappings:</h3>';
    $result = $conn->query("SELECT * FROM namespace_mappings WHERE is_active = 1");

    if ($result->num_rows > 0) {
        echo '<ul>';
        while ($ns = $result->fetch_assoc()) {
            echo '<li><strong>' . htmlspecialchars($ns['category']) . '</strong> (' . htmlspecialchars($ns['short_form']) . ') - ID: ' . $ns['id'] . '</li>';
        }
        echo '</ul>';
    } else {
        echo '<p style="color: var(--cds-support-error);">❌ No active namespace mappings found!</p>';
        echo '<p><strong>Solution:</strong> You need to create namespace mappings first:</p>';
        echo '<ol>';
        echo '<li>Go to <a href="admin/prefixes.php">Prefix Management</a></li>';
        echo '<li>Add some namespace prefixes (e.g., "edtech.journal" with short form "ej")</li>';
        echo '<li>Then return to create identifiers</li>';
        echo '</ol>';
    }

    // Test identifier table structure
    echo '<h3>Identifier Table Structure:</h3>';
    $result = $conn->query("DESCRIBE identifiers");

    if ($result) {
        echo '<table style="border-collapse: collapse; width: 100%;">';
        echo '<tr style="border-bottom: 1px solid #ccc;"><th style="text-align: left; padding: 8px;">Field</th><th style="text-align: left; padding: 8px;">Type</th><th style="text-align: left; padding: 8px;">Null</th></tr>';
        while ($field = $result->fetch_assoc()) {
            echo '<tr style="border-bottom: 1px solid #eee;">';
            echo '<td style="padding: 8px;">' . htmlspecialchars($field['Field']) . '</td>';
            echo '<td style="padding: 8px;">' . htmlspecialchars($field['Type']) . '</td>';
            echo '<td style="padding: 8px;">' . htmlspecialchars($field['Null']) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '<p style="color: var(--cds-support-success);">✅ Table structure looks correct</p>';
    } else {
        echo '<p style="color: var(--cds-support-error);">❌ Could not describe identifiers table</p>';
    }

    // Test simple insert (if we have namespaces)
    $ns_result = $conn->query("SELECT id, long_form FROM namespace_mappings WHERE is_active = 1 LIMIT 1");
    if ($ns_result && $ns_result->num_rows > 0) {
        $ns = $ns_result->fetch_assoc();

        echo '<h3>Test Insert:</h3>';
        $test_doi = $ns['long_form'] . '/test.' . time();

        $stmt = $conn->prepare("
            INSERT INTO identifiers (doi, namespace_id, suffix, target_url, title, description, resource_type, STATUS, registered_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())
        ");

        $test_suffix = 'test.' . time();
        $test_url = 'https://example.com/test';
        $test_title = 'Test Identifier';
        $test_desc = 'Test description';
        $test_type = 'other';

        $stmt->bind_param("sisssss", $test_doi, $ns['id'], $test_suffix, $test_url, $test_title, $test_desc, $test_type);

        if ($stmt->execute()) {
            echo '<p style="color: var(--cds-support-success);">✅ Test insert successful!</p>';
            echo '<p>Created DOI: ' . htmlspecialchars($test_doi) . '</p>';

            // Clean up test record
            $conn->query("DELETE FROM identifiers WHERE doi = '" . $conn->real_escape_string($test_doi) . "'");
            echo '<p style="color: var(--cds-text-secondary);"><em>Test record deleted</em></p>';
        } else {
            echo '<p style="color: var(--cds-support-error);">❌ Test insert failed!</p>';
            echo '<p><strong>Error:</strong> ' . htmlspecialchars($stmt->error) . '</p>';
            echo '<p><strong>Error Code:</strong> ' . htmlspecialchars($stmt->errno) . '</p>';
            echo '<p><strong>SQL Statement:</strong></p>';
            echo '<pre style="background: #f4f4f4; padding: 10px; border-radius: 4px; font-size: 0.9em;">';
            echo 'INSERT INTO identifiers (doi, namespace_id, suffix, target_url, title, description, resource_type, STATUS, registered_at)';
            echo "\nVALUES ('" . htmlspecialchars($test_doi) . "', " . $ns['id'] . ", '" . htmlspecialchars($test_suffix) . "', '" . htmlspecialchars($test_url) . "', '" . htmlspecialchars($test_title) . "', '" . htmlspecialchars($test_desc) . "', '" . htmlspecialchars($test_type) . "', 'active', NOW())";
            echo '</pre>';
        }
    } else {
        echo '<h3>Test Insert:</h3>';
        echo '<p style="color: var(--cds-support-warning);">⚠️ No active namespaces found - cannot test insert</p>';
    }

} catch (Exception $e) {
    echo '<p style="color: var(--cds-support-error);">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

echo '<p style="margin-top: var(--cds-spacing-07);">';
echo '<a href="admin/identifiers.php" class="button">← Back to Identifiers</a> ';
echo '<a href="admin/prefixes.php" class="button">Manage Prefixes</a> ';
echo '<a href="debug.php" class="button">System Debug</a>';
echo '</p>';

echo '</div></div></body></html>';
?>
