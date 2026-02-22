<?php
/**
 * Direct Prefix Creation Test
 * Test prefix management functionality
 */

require_once 'includes/config.php';

echo "<!DOCTYPE html><html><head><title>Prefix Test - EdTech Identifier</title>";
echo '<link rel="stylesheet" href="assets/style.css">';
echo "</head><body>";
echo '<div class="container" style="max-width: 800px;">';
echo '<h1>🧪 Prefix Management Test</h1>';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo '<h2>Processing Prefix Data:</h2>';
    echo '<div class="code-block">';

    try {
        $conn = db_connect();

        $long_form = trim($_POST['long_form']);
        $short_form = trim($_POST['short_form']);
        $category = trim($_POST['category']);
        $description = trim($_POST['description']);

        echo "<p><strong>Data received:</strong></p>";
        echo "<ul>";
        echo "<li>Long Form: " . htmlspecialchars($long_form) . "</li>";
        echo "<li>Short Form: " . htmlspecialchars($short_form) . "</li>";
        echo "<li>Category: " . htmlspecialchars($category) . "</li>";
        echo "<li>Description: " . htmlspecialchars($description) . "</li>";
        echo "</ul>";

        if (!empty($long_form) && !empty($short_form) && !empty($category)) {
            echo "<p><strong>Step 1:</strong> Checking for duplicates...</p>";

            // Check for duplicates
            $stmt = $conn->prepare("SELECT id FROM namespace_mappings WHERE long_form = ? OR short_form = ?");
            $stmt->bind_param("ss", $long_form, $short_form);
            $stmt->execute();

            if ($stmt->get_result()->num_rows === 0) {
                echo "<p style='color: green;'>✅ No duplicate found</p>";

                echo "<p><strong>Step 2:</strong> Inserting prefix...</p>";

                $sql = "INSERT INTO namespace_mappings (long_form, short_form, category, description) VALUES (?, ?, ?, ?)";
                echo "<p><strong>SQL:</strong> " . htmlspecialchars($sql) . "</p>";

                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssss", $long_form, $short_form, $category, $description);

                if ($stmt->execute()) {
                    echo "<p style='color: green; font-weight: bold;'>✅ SUCCESS! Prefix created</p>";

                    // Show the created record
                    echo "<p><strong>Created Record:</strong></p>";
                    $stmt = $conn->prepare("SELECT * FROM namespace_mappings WHERE long_form = ? AND short_form = ?");
                    $stmt->bind_param("ss", $long_form, $short_form);
                    $stmt->execute();
                    $result = $stmt->get_result()->fetch_assoc();

                    echo "<ul>";
                    foreach($result as $key => $value) {
                        echo "<li><strong>$key:</strong> " . htmlspecialchars($value ?? 'NULL') . "</li>";
                    }
                    echo "</ul>";

                } else {
                    echo "<p style='color: red; font-weight: bold;'>❌ INSERT FAILED!</p>";
                    echo "<p><strong>Error:</strong> " . htmlspecialchars($stmt->error) . "</p>";
                    echo "<p><strong>Error Code:</strong> " . htmlspecialchars($stmt->errno) . "</p>";
                    echo "<p><strong>Connection Error:</strong> " . htmlspecialchars($conn->error) . "</p>";
                }
            } else {
                echo "<p style='color: red;'>❌ Duplicate prefix exists</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ Missing required fields</p>";
        }

    } catch (Exception $e) {
        echo "<p style='color: red; font-weight: bold;'>❌ EXCEPTION: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }

    echo '</div>';
    echo '<p><a href="prefix-test.php" class="button">← Try Again</a></p>';

} else {
    // Show current prefixes
    echo '<div class="code-block">';

    try {
        $conn = db_connect();
        echo '<p style="color: var(--cds-support-success);">✅ Database connection successful</p>';

        // Show existing prefixes
        echo '<h3>Current Prefixes:</h3>';
        $result = $conn->query("SELECT * FROM namespace_mappings ORDER BY created_at DESC");

        if ($result && $result->num_rows > 0) {
            echo '<table style="border-collapse: collapse; width: 100%;">';
            echo '<tr style="border-bottom: 1px solid #ccc;">';
            echo '<th style="text-align: left; padding: 8px;">Long Form</th>';
            echo '<th style="text-align: left; padding: 8px;">Short</th>';
            echo '<th style="text-align: left; padding: 8px;">Category</th>';
            echo '<th style="text-align: left; padding: 8px;">Active</th>';
            echo '</tr>';

            while ($prefix = $result->fetch_assoc()) {
                echo '<tr style="border-bottom: 1px solid #eee;">';
                echo '<td style="padding: 8px;">' . htmlspecialchars($prefix['long_form']) . '</td>';
                echo '<td style="padding: 8px;">' . htmlspecialchars($prefix['short_form']) . '</td>';
                echo '<td style="padding: 8px;">' . htmlspecialchars($prefix['category']) . '</td>';
                echo '<td style="padding: 8px;">' . ($prefix['is_active'] ? '✅ Active' : '❌ Inactive') . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        } else {
            echo '<p style="color: var(--cds-support-warning);">⚠️ No prefixes found</p>';
        }

        // Test form
        echo '<h3>Add New Prefix:</h3>';
        echo '<form method="POST" style="max-width: 500px;">';

        echo '<div class="form-group">';
        echo '<label class="form-label">Long Form:</label>';
        echo '<input type="text" name="long_form" class="form-input" placeholder="e.g., edtechid.test" required>';
        echo '<p class="text-muted">Must start with "edtechid."</p>';
        echo '</div>';

        echo '<div class="form-group">';
        echo '<label class="form-label">Short Form:</label>';
        echo '<input type="text" name="short_form" class="form-input" placeholder="e.g., et" maxlength="4" required>';
        echo '<p class="text-muted">2-4 lowercase letters</p>';
        echo '</div>';

        echo '<div class="form-group">';
        echo '<label class="form-label">Category:</label>';
        echo '<input type="text" name="category" class="form-input" placeholder="e.g., Test Materials" required>';
        echo '</div>';

        echo '<div class="form-group">';
        echo '<label class="form-label">Description:</label>';
        echo '<textarea name="description" class="form-input" placeholder="Optional description"></textarea>';
        echo '</div>';

        echo '<button type="submit" class="button" style="margin-top: 20px;">Create Prefix</button>';
        echo '</form>';

    } catch (Exception $e) {
        echo '<p style="color: red;">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }

    echo '</div>';
}

echo '<p style="margin-top: var(--cds-spacing-07);">';
echo '<a href="admin/prefixes.php" class="button">← Back to Prefix Management</a> ';
echo '<a href="admin/identifiers.php" class="button">Identifiers</a>';
echo '</p>';

echo '</div></body></html>';
?>
