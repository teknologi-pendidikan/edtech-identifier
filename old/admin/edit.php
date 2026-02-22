<?php
require_once '../includes/auth.php';
require_once '../includes/config.php';

// Check if user is authenticated
if (!is_authenticated()) {
    header('Location: login.php');
    exit;
}

// Connect to database
$conn = create_db_connection($db_config);
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

$id = isset($_GET['id']) ? $_GET['id'] : '';
$success = false;
$error = '';

// Get all prefixes
$prefixes = [];
$prefix_result = $conn->query("SELECT prefix, name FROM prefixes WHERE is_active = TRUE ORDER BY name");
if ($prefix_result) {
    while ($row = $prefix_result->fetch_assoc()) {
        $prefixes[] = $row;
    }
}

// Handle form submission for updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $id = $_POST['id'];
    $prefix = $_POST['prefix'];
    $suffix = $_POST['suffix'];
    $title = $_POST['title'];
    $description = $_POST['description'] ?? '';
    $target_url = $_POST['target_url'];

    // Check if we're changing the prefix/suffix
    $original_prefix = $_POST['original_prefix'];
    $original_suffix = $_POST['original_suffix'];

    if ($prefix !== $original_prefix || $suffix !== $original_suffix) {
        // Check if the new identifier already exists
        $check_stmt = $conn->prepare("SELECT 1 FROM identifiers WHERE prefix = ? AND suffix = ? AND id != ?");
        $check_stmt->bind_param("sss", $prefix, $suffix, $id);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            $error = "Cannot update: An identifier with prefix '$prefix' and suffix '$suffix' already exists.";
            $check_stmt->close();
        } else {
            $check_stmt->close();

            // Update the identifier
            $stmt = $conn->prepare("UPDATE identifiers SET prefix = ?, suffix = ?, title = ?, description = ?, target_url = ? WHERE id = ?");
            $stmt->bind_param("ssssss", $prefix, $suffix, $title, $description, $target_url, $id);

            if ($stmt->execute()) {
                $success = true;

                // Log the update
                $log_stmt = $conn->prepare("INSERT INTO identifier_logs (identifier_id, action, changed_by, details) VALUES (?, 'update', ?, ?)");
                $username = $_SESSION['username'] ?? 'admin';
                $details = json_encode([
                    'original_prefix' => $original_prefix,
                    'original_suffix' => $original_suffix,
                    'new_prefix' => $prefix,
                    'new_suffix' => $suffix,
                    'title' => $title,
                    'target_url' => $target_url
                ]);
                $log_stmt->bind_param("sss", $id, $username, $details);
                $log_stmt->execute();
                $log_stmt->close();
            } else {
                $error = "Failed to update the identifier: " . $conn->error;
            }
            $stmt->close();
        }
    } else {
        // Just update the other fields, not changing prefix/suffix
        $stmt = $conn->prepare("UPDATE identifiers SET title = ?, description = ?, target_url = ? WHERE id = ?");
        $stmt->bind_param("ssss", $title, $description, $target_url, $id);

        if ($stmt->execute()) {
            $success = true;

            // Log the update
            $log_stmt = $conn->prepare("INSERT INTO identifier_logs (identifier_id, action, changed_by, details) VALUES (?, 'update', ?, ?)");
            $username = $_SESSION['username'] ?? 'admin';
            $details = json_encode([
                'title' => $title,
                'target_url' => $target_url
            ]);
            $log_stmt->bind_param("sss", $id, $username, $details);
            $log_stmt->execute();
            $log_stmt->close();
        } else {
            $error = "Failed to update the identifier: " . $conn->error;
        }
        $stmt->close();
    }
}

// Get identifier details
if (!empty($id)) {
    $stmt = $conn->prepare("SELECT id, prefix, suffix, title, description, target_url FROM identifiers WHERE id = ?");
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $identifier = $result->fetch_assoc();
    $stmt->close();

    if (!$identifier) {
        die("Identifier not found.");
    }
} else {
    die("No identifier ID provided.");
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Identifier - EdTech UniverseID</title>
    <link rel="stylesheet" href="../styles.css">
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <h1>EdTech UniverseID Manager</h1>
            <a href="index.php" class="btn">Back to Dashboard</a>
        </div>

        <div class="container">
            <h2>Edit Identifier</h2>

            <?php if ($success): ?>
                <div class="notification notification-success">
                    <strong>Success!</strong> The identifier has been updated.
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="notification notification-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?php echo htmlspecialchars($identifier['id']); ?>">
                <input type="hidden" name="original_prefix" value="<?php echo htmlspecialchars($identifier['prefix']); ?>">
                <input type="hidden" name="original_suffix" value="<?php echo htmlspecialchars($identifier['suffix']); ?>">

                <div class="form-group">
                    <label for="prefix">Category:</label>
                    <select name="prefix" id="prefix" required>
                        <?php foreach ($prefixes as $prefix): ?>
                            <option value="<?php echo htmlspecialchars($prefix['prefix']); ?>"
                                <?php echo $identifier['prefix'] === $prefix['prefix'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($prefix['name']); ?> (<?php echo htmlspecialchars($prefix['prefix']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="suffix">Identifier Suffix:</label>
                    <input type="text" name="suffix" id="suffix" value="<?php echo htmlspecialchars($identifier['suffix']); ?>"
                        pattern="[a-zA-Z0-9\-_]+" required>
                    <div class="form-tip">Only letters, numbers, hyphens and underscores allowed.</div>
                    <div class="warning-box">
                        <strong>Warning:</strong> Changing the suffix will create a new URL. Any links to the old URL will no longer work.
                    </div>
                </div>

                <div class="form-group">
                    <label for="target_url">Target URL:</label>
                    <input type="url" name="target_url" id="target_url" value="<?php echo htmlspecialchars($identifier['target_url']); ?>" required>
                </div>

                <div class="form-group">
                    <label for="title">Title:</label>
                    <input type="text" name="title" id="title" value="<?php echo htmlspecialchars($identifier['title'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="description">Description:</label>
                    <textarea name="description" id="description"><?php echo htmlspecialchars($identifier['description'] ?? ''); ?></textarea>
                </div>

                <div class="form-actions">
                    <a href="index.php" class="btn">Cancel</a>
                    <button type="submit" class="btn">Update Identifier</button>
                </div>
            </form>
        </div>

        <div class="footer">
            <p>EdTech UniverseID Admin | &copy; <?php echo date('Y'); ?> Teknologi Pendidikan ID</p>
        </div>
    </div>
</body>
</html>
