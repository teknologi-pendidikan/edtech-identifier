<?php
require_once __DIR__ . '/includes/config.php';

// Add tracking variables
$submission_success = false;
$created_prefix = '';
$created_suffix = '';

$conn = create_db_connection($db_config);
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Function to generate a random suffix
function generate_random_suffix()
{
    return bin2hex(random_bytes(3)); // 6-character random string
}

// Function to check if a suffix exists
function is_suffix_unique($prefix, $suffix, $conn)
{
    $stmt = $conn->prepare("SELECT 1 FROM identifiers WHERE prefix = ? AND suffix = ?");
    $stmt->bind_param("ss", $prefix, $suffix);
    $stmt->execute();
    $stmt->store_result();
    $is_unique = $stmt->num_rows === 0;
    $stmt->close();
    return $is_unique;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $prefix = $_POST['prefix'];

    // If auto-generate is checked, generate a random suffix
    $suffix = ($_POST['auto_generate'] === "on") ? generate_random_suffix() : $_POST['suffix'];

    // Check if manually entered suffix is unique
    if ($_POST['auto_generate'] !== "on" && !is_suffix_unique($prefix, $suffix, $conn)) {
        // Error will be displayed via the conditional in the HTML
    } else {
        $url = $_POST['target_url'];
        $title = $_POST['title'];
        $desc = $_POST['description'];

        $stmt = $conn->prepare("INSERT INTO identifiers (prefix, suffix, target_url, title, description) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $prefix, $suffix, $url, $title, $desc);

        if ($stmt->execute()) {
            // Set success variables
            $submission_success = true;
            $created_prefix = $prefix;
            $created_suffix = $suffix;

            // Log the creation of a new identifier
            $id = $stmt->insert_id;
            $log_stmt = $conn->prepare("INSERT INTO identifier_logs (identifier_id, action, changed_by, details) VALUES (?, 'create', ?, ?)");
            $user = $_SESSION['username'] ?? 'anonymous'; // Assuming you might add authentication later
            $details = json_encode(['url' => $url, 'title' => $title]);
            $log_stmt->bind_param("sss", $id, $user, $details);
            $log_stmt->execute();
            $log_stmt->close();
        } else {
            // Error will be displayed via direct echo
            echo "<p style='color:red;'>Error: " . $conn->error . "</p>";
        }

        $stmt->close();
    }
}

// Get record count for each prefix to show stats
function get_prefix_counts($conn)
{
    $counts = [];
    $result = $conn->query("SELECT prefix, COUNT(*) as count FROM identifiers GROUP BY prefix");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $counts[$row['prefix']] = $row['count'];
        }
    }
    return $counts;
}

// Get available prefixes from the database
function get_prefixes($conn)
{
    $prefixes = [];
    $result = $conn->query("SELECT prefix, name, description FROM prefixes WHERE is_active = TRUE ORDER BY prefix");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $prefixes[] = $row;
        }
    }
    return $prefixes;
}

$prefix_counts = get_prefix_counts($conn);
$prefixes = get_prefixes($conn);
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EdTech UniverseID - Link Manager</title>
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --success: #4caf50;
            --error: #f44336;
            --text: #333;
            --text-light: #666;
            --bg: #f5f7fa;
            --card-bg: #fff;
            --border: #e1e4e8;
        }

        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg);
            max-width: 700px;
            margin: 40px auto;
            padding: 0 20px;
            line-height: 1.6;
            color: var(--text);
        }

        .container {
            background-color: var(--card-bg);
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        }

        h1 {
            color: var(--primary);
            margin-top: 0;
            margin-bottom: 30px;
            font-size: 26px;
            text-align: center;
        }

        h2 {
            font-size: 20px;
            margin-top: 10px;
            margin-bottom: 20px;
            color: var(--text);
        }

        label {
            font-weight: 500;
            display: block;
            margin-bottom: 8px;
            color: var(--text);
        }

        select,
        input,
        textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            box-sizing: border-box;
            font-family: inherit;
            font-size: 15px;
            transition: border 0.2s;
        }

        select:focus,
        input:focus,
        textarea:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
        }

        .input-group {
            margin-bottom: 24px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            margin-bottom: 24px;
        }

        .checkbox-group input {
            width: auto;
            margin-right: 10px;
        }

        .checkbox-group label {
            display: inline;
            font-weight: normal;
        }

        .form-tip {
            font-size: 13px;
            color: var(--text-light);
            margin-top: 6px;
        }

        button {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: all 0.2s;
            width: 100%;
        }

        button:hover {
            background-color: var(--primary-dark);
            transform: translateY(-1px);
        }

        .success-message {
            background-color: var(--success);
            color: white;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 25px;
            text-align: center;
        }

        .success-message a {
            color: white;
            text-decoration: underline;
        }

        .error-message {
            background-color: var(--error);
            color: white;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 25px;
            text-align: center;
        }

        .preview-box {
            background: rgba(67, 97, 238, 0.1);
            padding: 18px;
            border-radius: 6px;
            margin: 25px 0;
            display: none;
            border-left: 3px solid var(--primary);
        }

        .preview-box h3 {
            margin-top: 0;
            font-size: 16px;
            color: var(--primary);
        }

        footer {
            text-align: center;
            margin-top: 40px;
            font-size: 13px;
            color: var(--text-light);
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>Teknologi Pendidikan Resource Identifier</h1>

        <?php if ($submission_success): ?>
            <div class="success-message">
                <p>✓ Link created successfully!</p>
                <p><strong><?php echo htmlspecialchars($created_prefix) . '/' . htmlspecialchars($created_suffix); ?></strong>
                </p>
                <p><a href="https://<?php echo $_SERVER['HTTP_HOST']; ?>/<?php echo htmlspecialchars($created_prefix) . '/' . htmlspecialchars($created_suffix); ?>"
                        target="_blank">
                        Open link in new tab
                    </a></p>
            </div>
        <?php elseif ($_SERVER["REQUEST_METHOD"] === "POST" && isset($suffix) && $_POST['auto_generate'] !== "on" && !is_suffix_unique($prefix, $suffix, $conn)): ?>
            <div class="error-message">
                <p>This identifier already exists. Please choose another suffix.</p>
            </div>
        <?php endif; ?>

        <h2>Create New Link</h2>

        <form method="POST">
            <div class="input-group">
                <label for="prefix">Category</label>
                <select name="prefix" id="prefix" required>
                    <option value="">-- Select a category --</option>
                    <?php foreach ($prefixes as $prefix): ?>
                        <option value="<?php echo htmlspecialchars($prefix['prefix']); ?>">
                            <?php echo htmlspecialchars($prefix['name']); ?>
                            <?php echo isset($prefix_counts[$prefix['prefix']]) ? "({$prefix_counts[$prefix['prefix']]})" : "(0)"; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="checkbox-group">
                <input type="checkbox" name="auto_generate" id="auto_generate" checked>
                <label for="auto_generate">Auto-generate link ID (recommended)</label>
            </div>

            <div class="input-group" id="suffix-group">
                <label for="suffix">Custom ID (optional)</label>
                <input type="text" name="suffix" id="suffix" placeholder="e.g., my-resource-2025"
                    pattern="[a-zA-Z0-9\-_]+" title="Only letters, numbers, hyphens and underscores allowed" disabled>
                <div class="form-tip">Letters, numbers, hyphens and underscores only</div>
            </div>

            <div class="input-group">
                <label for="target_url">Target URL</label>
                <input type="url" name="target_url" id="target_url" required
                    placeholder="https://example.com/your-page">
            </div>

            <div class="input-group">
                <label for="title">Title</label>
                <input type="text" name="title" id="title" placeholder="Enter a title for this resource">
            </div>

            <div class="input-group">
                <label for="description">Description (optional)</label>
                <textarea name="description" id="description" rows="3"
                    placeholder="Brief description of this resource"></textarea>
            </div>

            <div class="preview-box" id="preview">
                <h3>Link Preview</h3>
                <p><strong><?php echo $_SERVER['HTTP_HOST']; ?>/<span id="preview-prefix">category</span>/<span
                            id="preview-suffix">id</span></strong></p>
                <p id="preview-title">Resource title will appear here</p>
            </div>

            <button type="submit">Create Identifier </button>
        </form>
    </div>

    <footer>
        <p>DPTSI | &copy; <?php echo date('Y'); ?> Teknologi Pendidikan ID</p>
        <p><a href="list" style="color: var(--primary);">View all resource links</a></p>
    </footer>

    <script>
        // Toggle suffix field based on auto-generate checkbox
        document.getElementById('auto_generate').addEventListener('change', function () {
            const suffixField = document.getElementById('suffix');
            const suffixGroup = document.getElementById('suffix-group');

            suffixField.disabled = this.checked;

            if (this.checked) {
                suffixGroup.style.opacity = '0.7';
                suffixField.setAttribute('placeholder', 'Auto-generated ID will be used');
            } else {
                suffixGroup.style.opacity = '1';
                suffixField.setAttribute('placeholder', 'e.g., my-resource-2025');
                suffixField.focus();
            }

            updatePreview();
        });

        // Live preview
        const previewBox = document.getElementById('preview');
        const previewPrefix = document.getElementById('preview-prefix');
        const previewSuffix = document.getElementById('preview-suffix');
        const previewTitle = document.getElementById('preview-title');
        const prefixSelect = document.getElementById('prefix');
        const suffixInput = document.getElementById('suffix');
        const titleInput = document.getElementById('title');
        const autoGenerate = document.getElementById('auto_generate');

        function updatePreview() {
            const selectedOption = prefixSelect.options[prefixSelect.selectedIndex];
            const prefixName = selectedOption.text.split(' - ')[0] || 'category';
            let prefix = prefixSelect.value || 'category';
            let suffix = suffixInput.value || 'id';

            if (autoGenerate.checked) {
                suffix = 'auto-generated';
            }

            const title = titleInput.value || 'Resource title will appear here';

            previewPrefix.textContent = prefix;
            previewSuffix.textContent = suffix;
            previewTitle.textContent = title;

            if (prefixSelect.value || suffixInput.value || titleInput.value) {
                previewBox.style.display = 'block';
            } else {
                previewBox.style.display = 'none';
            }
        }

        prefixSelect.addEventListener('change', updatePreview);
        suffixInput.addEventListener('input', updatePreview);
        titleInput.addEventListener('input', updatePreview);

        // Initialize state
        document.getElementById('auto_generate').dispatchEvent(new Event('change'));
    </script>
</body>

</html>
