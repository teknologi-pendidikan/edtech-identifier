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

$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add_prefix') {
        // Add a new prefix
        $prefix = trim($_POST['prefix']);
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Validate prefix format
        if (!preg_match('/^edtechid\.[0-9]+$/', $prefix)) {
            $error_message = "Invalid prefix format. It must be in the format 'edtechid.NUMBER'.";
        } else {
            // Check if prefix already exists
            $check_stmt = $conn->prepare("SELECT 1 FROM prefixes WHERE prefix = ?");
            $check_stmt->bind_param("s", $prefix);
            $check_stmt->execute();
            $check_stmt->store_result();

            if ($check_stmt->num_rows > 0) {
                $error_message = "This prefix already exists.";
                $check_stmt->close();
            } else {
                $check_stmt->close();

                // Insert new prefix
                $stmt = $conn->prepare("INSERT INTO prefixes (prefix, name, description, is_active) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("sssi", $prefix, $name, $description, $is_active);

                if ($stmt->execute()) {
                    $success_message = "New category added successfully.";
                } else {
                    $error_message = "Failed to add new category: " . $conn->error;
                }
                $stmt->close();
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'update_prefix') {
        // Update existing prefix
        $prefix = $_POST['prefix'];
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        $stmt = $conn->prepare("UPDATE prefixes SET name = ?, description = ?, is_active = ? WHERE prefix = ?");
        $stmt->bind_param("ssis", $name, $description, $is_active, $prefix);

        if ($stmt->execute()) {
            $success_message = "Category updated successfully.";
        } else {
            $error_message = "Failed to update category: " . $conn->error;
        }
        $stmt->close();
    }
}

// Get all prefixes
$prefixes = [];
$result = $conn->query("SELECT prefix, name, description, is_active, created_at FROM prefixes ORDER BY prefix");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Get count of identifiers using this prefix
        $count_stmt = $conn->prepare("SELECT COUNT(*) FROM identifiers WHERE prefix = ?");
        $count_stmt->bind_param("s", $row['prefix']);
        $count_stmt->execute();
        $count_stmt->bind_result($usage_count);
        $count_stmt->fetch();
        $count_stmt->close();

        $row['usage_count'] = $usage_count;
        $prefixes[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Categories - EdTech UniverseID</title>
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --success: #4caf50;
            --danger: #f44336;
            --warning: #ff9800;
            --text: #333;
            --text-light: #666;
            --bg: #f5f7fa;
            --card-bg: #fff;
            --border: #e1e4e8;
        }

        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg);
            margin: 0;
            padding: 0;
            line-height: 1.6;
            color: var(--text);
        }

        .wrapper {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border);
        }

        .header h1 {
            color: var(--primary);
            margin: 0;
            font-size: 24px;
        }

        .container {
            background-color: var(--card-bg);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        h2 {
            color: var(--text);
            font-size: 20px;
            margin-top: 0;
            margin-bottom: 20px;
        }

        .notification {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .notification-success {
            background-color: rgba(76, 175, 80, 0.1);
            border: 1px solid rgba(76, 175, 80, 0.3);
            color: #2e7d32;
        }

        .notification-error {
            background-color: rgba(244, 67, 54, 0.1);
            border: 1px solid rgba(244, 67, 54, 0.3);
            color: #c62828;
        }

        .btn {
            display: inline-block;
            padding: 8px 16px;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .btn:hover {
            background-color: var(--primary-dark);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 12px;
            background-color: rgba(67, 97, 238, 0.05);
            color: var(--primary);
            font-size: 14px;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
        }

        tr:hover {
            background-color: rgba(67, 97, 238, 0.03);
        }

        .form-section {
            margin-top: 40px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            font-size: 14px;
        }

        input,
        textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid var(--border);
            border-radius: 4px;
            font-family: inherit;
            box-sizing: border-box;
            font-size: 14px;
        }

        textarea {
            height: 80px;
            resize: vertical;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
        }

        .checkbox-group input {
            width: auto;
            margin-right: 8px;
        }

        .checkbox-group label {
            display: inline;
            margin: 0;
        }

        .form-tip {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 3px;
        }

        .prefix-id {
            font-family: monospace;
            font-weight: 600;
        }

        .description {
            color: var(--text-light);
        }

        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-active {
            background-color: rgba(76, 175, 80, 0.1);
            color: #2e7d32;
        }

        .status-inactive {
            background-color: rgba(158, 158, 158, 0.1);
            color: #616161;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 100;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: white;
            border-radius: 8px;
            width: 500px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        }

        .modal-content h3 {
            margin-top: 0;
            color: var(--primary);
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }

        .close {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 22px;
            font-weight: bold;
            cursor: pointer;
            color: var(--text-light);
        }

        .footer {
            text-align: center;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
            font-size: 13px;
            color: var(--text-light);
        }
    </style>
</head>

<body>
    <div class="wrapper">
        <div class="header">
            <h1>Manage Categories</h1>
            <a href="index.php" class="btn">Back to Dashboard</a>
        </div>

        <div class="container">
            <?php if ($success_message): ?>
                <div class="notification notification-success">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="notification notification-error">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <h2>Available Categories</h2>

            <?php if (count($prefixes) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Prefix</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Usage</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($prefixes as $prefix): ?>
                            <tr>
                                <td class="prefix-id"><?php echo htmlspecialchars($prefix['prefix']); ?></td>
                                <td><?php echo htmlspecialchars($prefix['name']); ?></td>
                                <td class="description">
                                    <?php echo htmlspecialchars(substr($prefix['description'] ?? '', 0, 50)); ?>
                                    <?php echo strlen($prefix['description'] ?? '') > 50 ? '...' : ''; ?>
                                </td>
                                <td>
                                    <span
                                        class="status-badge <?php echo $prefix['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo $prefix['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td><?php echo $prefix['usage_count']; ?> identifiers</td>
                                <td>
                                    <button class="btn"
                                        onclick="editPrefix('<?php echo htmlspecialchars($prefix['prefix']); ?>', '<?php echo htmlspecialchars(addslashes($prefix['name'])); ?>', '<?php echo htmlspecialchars(addslashes($prefix['description'] ?? '')); ?>', <?php echo $prefix['is_active'] ? 'true' : 'false'; ?>)">
                                        Edit
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No categories defined yet.</p>
            <?php endif; ?>

            <div class="form-section">
                <h2>Add New Category</h2>

                <form method="post">
                    <input type="hidden" name="action" value="add_prefix">

                    <div class="form-group">
                        <label for="prefix">Prefix:</label>
                        <input type="text" id="prefix" name="prefix" placeholder="edtechid.XXX" required>
                        <div class="form-tip">Must be in the format: edtechid.NUMBER (e.g., edtechid.100)</div>
                    </div>

                    <div class="form-group">
                        <label for="name">Name:</label>
                        <input type="text" id="name" name="name" placeholder="e.g., Research Papers" required>
                    </div>

                    <div class="form-group">
                        <label for="description">Description:</label>
                        <textarea id="description" name="description"
                            placeholder="Brief description of this category"></textarea>
                    </div>

                    <div class="form-group checkbox-group">
                        <input type="checkbox" id="is_active" name="is_active" checked>
                        <label for="is_active">Active</label>
                    </div>

                    <button type="submit" class="btn">Add Category</button>
                </form>
            </div>
        </div>

        <div class="footer">
            <p>EdTech UniverseID Admin | &copy; <?php echo date('Y'); ?> Teknologi Pendidikan ID</p>
        </div>
    </div>

    <!-- Edit Category Modal -->
    <div id="edit-modal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h3>Edit Category</h3>

            <form method="post">
                <input type="hidden" name="action" value="update_prefix">
                <input type="hidden" id="edit-prefix" name="prefix" value="">

                <div class="form-group">
                    <label for="edit-name">Name:</label>
                    <input type="text" id="edit-name" name="name" required>
                </div>

                <div class="form-group">
                    <label for="edit-description">Description:</label>
                    <textarea id="edit-description" name="description"></textarea>
                </div>

                <div class="form-group checkbox-group">
                    <input type="checkbox" id="edit-is-active" name="is_active">
                    <label for="edit-is-active">Active</label>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn" style="background-color: #9e9e9e;"
                        onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn">Update Category</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const modal = document.getElementById('edit-modal');

        function editPrefix(prefix, name, description, isActive) {
            document.getElementById('edit-prefix').value = prefix;
            document.getElementById('edit-name').value = name;
            document.getElementById('edit-description').value = description;
            document.getElementById('edit-is-active').checked = isActive;

            modal.style.display = 'flex';
        }

        function closeModal() {
            modal.style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function (event) {
            if (event.target === modal) {
                closeModal();
            }
        }
    </script>
</body>

</html>
