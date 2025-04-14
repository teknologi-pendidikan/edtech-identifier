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
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            line-height: 1.6;
            color: #333;
        }

        .container {
            background: #f9f9f9;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        h1,
        h2 {
            color: #2c3e50;
            margin-top: 0;
        }

        label {
            font-weight: 600;
            display: block;
            margin-bottom: 5px;
        }

        select,
        input,
        textarea {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
            font-family: inherit;
        }

        .input-group {
            margin-bottom: 20px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .checkbox-group input {
            width: auto;
            margin-right: 10px;
        }

        .checkbox-group label {
            display: inline;
            font-weight: normal;
        }

        .prefix-stats {
            font-size: 12px;
            color: #666;
            margin-left: 5px;
        }

        .form-tip {
            font-size: 13px;
            color: #666;
            margin-top: 5px;
        }

        button {
            background-color: #3498db;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: background 0.3s;
        }

        button:hover {
            background-color: #2980b9;
        }

        .success-message {
            background-color: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            text-align: center;
        }

        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            text-align: center;
        }

        .preview-box {
            background: #e9f7fe;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
            display: none;
        }

        .preview-box h3 {
            margin-top: 0;
            font-size: 16px;
        }

        footer {
            text-align: center;
            margin-top: 30px;
            font-size: 13px;
            color: #666;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>EdTech UniverseID Link Manager</h1>

        <?php if ($submission_success): ?>
            <div class="success-message">
                <p>✅ DOI
                    <strong><?php echo htmlspecialchars($created_prefix) . '/' . htmlspecialchars($created_suffix); ?></strong>
                    added
                    successfully!
                </p>
                <p>Link: <a
                        href="https://<?php echo $_SERVER['HTTP_HOST']; ?>/<?php echo htmlspecialchars($created_prefix) . '/' . htmlspecialchars($created_suffix); ?>"
                        target="_blank">
                        https://<?php echo $_SERVER['HTTP_HOST']; ?>/<?php echo htmlspecialchars($created_prefix) . '/' . htmlspecialchars($created_suffix); ?>
                    </a></p>
            </div>
        <?php elseif ($_SERVER["REQUEST_METHOD"] === "POST" && isset($suffix) && $_POST['auto_generate'] !== "on" && !is_suffix_unique($prefix, $suffix, $conn)): ?>
            <div class="error-message">
                <p>❌ Suffix <strong><?php echo htmlspecialchars($prefix) . '/' . htmlspecialchars($suffix); ?></strong>
                    already exists. Please choose another suffix.</p>
            </div>
        <?php endif; ?>

        <h2>Create New Link</h2>

        <form method="POST">
            <div class="input-group">
                <label for="prefix">Prefix:</label>
                <select name="prefix" id="prefix" required>
                    <option value="">-- Select a prefix --</option>
                    <?php foreach ($prefixes as $prefix): ?>
                        <option value="<?php echo htmlspecialchars($prefix['prefix']); ?>">
                            <?php echo htmlspecialchars($prefix['prefix']) . ' - ' . htmlspecialchars($prefix['name']); ?>
                            <?php echo isset($prefix_counts[$prefix['prefix']]) ? "({$prefix_counts[$prefix['prefix']]} links)" : "(0 links)"; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-tip">Choose a category for your link</div>
            </div>

            <div class="input-group">
                <label for="suffix">Suffix:</label>
                <input type="text" name="suffix" id="suffix"
                    placeholder="E.g., journal-2025 (leave empty to auto-generate)" style="width: 100%;"
                    pattern="[a-zA-Z0-9\-_]+" title="Only letters, numbers, hyphens and underscores allowed">
                <div class="form-tip">Use a meaningful name or leave empty for auto-generation</div>
            </div>

            <div class="checkbox-group">
                <input type="checkbox" name="auto_generate" id="auto_generate">
                <label for="auto_generate">Auto-generate suffix (recommended for most users)</label>
            </div>

            <div class="input-group">
                <label for="target_url">Target URL:</label>
                <input type="url" name="target_url" id="target_url" required
                    placeholder="https://example.com/your-page">
                <div class="form-tip">The destination URL where users will be redirected</div>
            </div>

            <div class="input-group">
                <label for="title">Title:</label>
                <input type="text" name="title" id="title" placeholder="Enter a descriptive title">
                <div class="form-tip">A short, descriptive title for this resource</div>
            </div>

            <div class="input-group">
                <label for="description">Description:</label>
                <textarea name="description" id="description" rows="3"
                    placeholder="Enter a brief description"></textarea>
                <div class="form-tip">Optional description for your own reference</div>
            </div>

            <div class="preview-box" id="preview">
                <h3>Preview</h3>
                <p><strong>URL:</strong> <span
                        id="preview-url">https://<?php echo $_SERVER['HTTP_HOST']; ?>/prefix/suffix</span></p>
                <p><strong>Title:</strong> <span id="preview-title">Your resource title</span></p>
            </div>

            <button type="submit">Create Link</button>
        </form>
    </div>

    <footer>
        <p>EdTech UniverseID Backoffice | &copy; <?php echo date('Y'); ?> Teknologi Pendidikan</p>
    </footer>

    <script>
        // Toggle suffix field based on auto-generate checkbox
        document.getElementById('auto_generate').addEventListener('change', function () {
            const suffixField = document.getElementById('suffix');
            suffixField.disabled = this.checked;
            suffixField.required = !this.checked;
            if (this.checked) {
                suffixField.setAttribute('placeholder', 'Will be auto-generated');
            } else {
                suffixField.setAttribute('placeholder', 'E.g., journal-2025');
            }
        });

        // Live preview
        const previewBox = document.getElementById('preview');
        const previewUrl = document.getElementById('preview-url');
        const previewTitle = document.getElementById('preview-title');
        const prefixSelect = document.getElementById('prefix');
        const suffixInput = document.getElementById('suffix');
        const titleInput = document.getElementById('title');
        const autoGenerate = document.getElementById('auto_generate');

        function updatePreview() {
            const prefix = prefixSelect.value || 'prefix';
            let suffix = suffixInput.value || 'suffix';
            if (autoGenerate.checked) {
                suffix = 'auto-generated';
            }
            const title = titleInput.value || 'Your resource title';

            previewUrl.textContent = 'https://' + window.location.host + '/' + prefix + '/' + suffix;
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
        autoGenerate.addEventListener('change', updatePreview);
    </script>
</body>

</html>
