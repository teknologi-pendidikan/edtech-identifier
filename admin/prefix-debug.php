<?php
/**
 * Simple Prefix Page Debug
 * Test basic prefix page loading
 */

// Error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    echo "Step 1: Including config...<br>";
    require_once '../includes/config.php';

    echo "Step 2: Including auth...<br>";
    require_once '../includes/auth.php';

    echo "Step 3: Starting session and checking login...<br>";
    if (!is_logged_in()) {
        echo "Not logged in, would redirect to login.php<br>";
        // Don't actually redirect for debug
    } else {
        echo "User is logged in<br>";
    }

    echo "Step 4: Connecting to database...<br>";
    $conn = db_connect();
    echo "Database connection successful<br>";

    echo "Step 5: Testing simple query...<br>";
    $result = $conn->query("SELECT COUNT(*) as count FROM namespace_mappings");
    if ($result) {
        $count = $result->fetch_assoc()['count'];
        echo "Found {$count} namespace mappings<br>";
    } else {
        echo "Query failed: " . $conn->error . "<br>";
    }

    echo "Step 6: Testing complex query...<br>";
    $prefixes_query = "
        SELECT nm.*, COUNT(i.id) as identifier_count
        FROM namespace_mappings nm
        LEFT JOIN identifiers i ON nm.id = i.namespace_id
        GROUP BY nm.id
        ORDER BY nm.created_at DESC
    ";

    $result = $conn->query($prefixes_query);
    if ($result) {
        $prefixes = $result->fetch_all(MYSQLI_ASSOC);
        echo "Complex query successful, got " . count($prefixes) . " results<br>";

        echo "Step 7: Testing array access...<br>";
        foreach ($prefixes as $index => $prefix) {
            echo "Prefix {$index}: ";
            echo "id=" . ($prefix['id'] ?? 'MISSING') . ", ";
            echo "long_form=" . ($prefix['long_form'] ?? 'MISSING') . ", ";
            echo "identifier_count=" . ($prefix['identifier_count'] ?? 'MISSING') . "<br>";
        }

        echo "<br><strong>✅ All steps completed successfully!</strong><br>";
        echo "The prefix page should work. The issue might be in the HTML rendering part.<br>";

    } else {
        echo "❌ Complex query failed: " . $conn->error . "<br>";
    }

} catch (Exception $e) {
    echo "❌ Exception caught: " . $e->getMessage() . "<br>";
    echo "Stack trace:<br><pre>" . $e->getTraceAsString() . "</pre>";
}

echo "<br><a href='../admin/prefixes.php'>← Try Real Prefix Page</a>";
echo " | <a href='../admin/dashboard.php'>Dashboard</a>";
?>
