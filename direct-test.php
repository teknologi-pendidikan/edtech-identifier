<?php
/**
 * Direct Identifier Creation Test
 * Test the exact same process as the admin form
 */

require_once 'includes/config.php';

echo "<!DOCTYPE html><html><head><title>Direct Test - EdTech Identifier</title>";
echo '<link rel="stylesheet" href="assets/style.css">';
echo "</head><body>";
echo '<div class="container" style="max-width: 800px;">';
echo '<h1>🧪 Direct Identifier Creation Test</h1>';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo '<h2>Processing Form Data:</h2>';
    echo '<div class="code-block">';

    try {
        $conn = db_connect();

        $namespace_id = (int)$_POST['namespace_id'];
        $suffix = trim($_POST['suffix']);
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $target_url = trim($_POST['target_url']);
        $resource_type = $_POST['resource_type'];

        echo "<p><strong>Data received:</strong></p>";
        echo "<ul>";
        echo "<li>Namespace ID: " . htmlspecialchars($namespace_id) . "</li>";
        echo "<li>Suffix: " . htmlspecialchars($suffix) . "</li>";
        echo "<li>Title: " . htmlspecialchars($title) . "</li>";
        echo "<li>Target URL: " . htmlspecialchars($target_url) . "</li>";
        echo "<li>Resource Type: " . htmlspecialchars($resource_type) . "</li>";
        echo "</ul>";

        if (!empty($namespace_id) && !empty($suffix) && !empty($target_url)) {
            // Get namespace info
            echo "<p><strong>Step 1:</strong> Getting namespace info...</p>";
            $stmt = $conn->prepare("SELECT long_form FROM namespace_mappings WHERE id = ?");
            $stmt->bind_param("i", $namespace_id);
            $stmt->execute();
            $namespace = $stmt->get_result()->fetch_assoc();

            if ($namespace) {
                $doi = $namespace['long_form'] . '/' . $suffix;
                echo "<p style='color: green;'>✅ Namespace found: " . htmlspecialchars($namespace['long_form']) . "</p>";
                echo "<p><strong>Generated DOI:</strong> " . htmlspecialchars($doi) . "</p>";

                // Check for duplicate
                echo "<p><strong>Step 2:</strong> Checking for duplicates...</p>";
                $stmt = $conn->prepare("SELECT doi FROM identifiers WHERE doi = ?");
                $stmt->bind_param("s", $doi);
                $stmt->execute();

                if ($stmt->get_result()->num_rows === 0) {
                    echo "<p style='color: green;'>✅ No duplicate found</p>";

                    // Insert identifier
                    echo "<p><strong>Step 3:</strong> Inserting identifier...</p>";

                    $sql = "INSERT INTO identifiers (doi, namespace_id, suffix, target_url, title, description, resource_type, STATUS, registered_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())";
                    echo "<p><strong>SQL:</strong> " . htmlspecialchars($sql) . "</p>";

                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("sisssss", $doi, $namespace_id, $suffix, $target_url, $title, $description, $resource_type);

                    if ($stmt->execute()) {
                        echo "<p style='color: green; font-weight: bold;'>✅ SUCCESS! Identifier created: " . htmlspecialchars($doi) . "</p>";

                        // Show the created record
                        echo "<p><strong>Created Record:</strong></p>";
                        $stmt = $conn->prepare("SELECT * FROM identifiers WHERE doi = ?");
                        $stmt->bind_param("s", $doi);
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
                    echo "<p style='color: red;'>❌ Duplicate DOI exists: " . htmlspecialchars($doi) . "</p>";
                }
            } else {
                echo "<p style='color: red;'>❌ Invalid namespace selected</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ Missing required fields</p>";
        }

    } catch (Exception $e) {
        echo "<p style='color: red; font-weight: bold;'>❌ EXCEPTION: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }

    echo '</div>';
    echo '<p><a href="direct-test.php" class="button">← Try Again</a></p>';

} else {
    // Show form
    echo '<p>This form mimics exactly what the admin form does:</p>';
    echo '<div class="code-block">';

    try {
        $conn = db_connect();
        $namespaces = $conn->query("SELECT * FROM namespace_mappings WHERE is_active = 1 ORDER BY category")->fetch_all(MYSQLI_ASSOC);

        echo '<form method="POST" style="max-width: 500px;">';

        echo '<div class="form-group">';
        echo '<label class="form-label">Namespace:</label>';
        echo '<select name="namespace_id" class="form-input" required>';
        echo '<option value="">Select namespace...</option>';
        foreach ($namespaces as $ns) {
            echo '<option value="' . $ns['id'] . '">' . htmlspecialchars($ns['category']) . ' (' . htmlspecialchars($ns['short_form']) . ')</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="form-group">';
        echo '<label class="form-label">Suffix:</label>';
        echo '<input type="text" name="suffix" class="form-input" placeholder="e.g., 2025.001" required>';
        echo '</div>';

        echo '<div class="form-group">';
        echo '<label class="form-label">Title:</label>';
        echo '<input type="text" name="title" class="form-input" placeholder="Resource title">';
        echo '</div>';

        echo '<div class="form-group">';
        echo '<label class="form-label">Description:</label>';
        echo '<textarea name="description" class="form-input" placeholder="Resource description"></textarea>';
        echo '</div>';

        echo '<div class="form-group">';
        echo '<label class="form-label">Target URL:</label>';
        echo '<input type="url" name="target_url" class="form-input" placeholder="https://example.com/resource" required>';
        echo '</div>';

        echo '<div class="form-group">';
        echo '<label class="form-label">Resource Type:</label>';
        echo '<select name="resource_type" class="form-input">';
        echo '<option value="other">Other</option>';
        echo '<option value="journal_article">Journal Article</option>';
        echo '<option value="dataset">Dataset</option>';
        echo '<option value="course_module">Course Module</option>';
        echo '<option value="educational_material">Educational Material</option>';
        echo '<option value="person">Person</option>';
        echo '</select>';
        echo '</div>';

        echo '<button type="submit" class="button" style="margin-top: 20px;">Create Identifier</button>';
        echo '</form>';

    } catch (Exception $e) {
        echo '<p style="color: red;">Error loading form: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }

    echo '</div>';
}

echo '<p style="margin-top: var(--cds-spacing-07);">';
echo '<a href="admin/identifiers.php" class="button">← Back to Admin</a> ';
echo '<a href="test-identifiers.php" class="button">Table Test</a>';
echo '</p>';

echo '</div></body></html>';
?>
