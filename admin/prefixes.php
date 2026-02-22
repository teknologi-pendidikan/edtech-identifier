<?php
require_once '../includes/auth.php';
require_once '../includes/config.php';
require_once '../includes/security.php';

// Set security headers
set_security_headers();

// Check if user is authenticated
require_auth();

// Connect to database
$conn = create_db_connection($db_config);
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check CSRF token
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        log_security_event('csrf_token_invalid', ['action' => 'prefix_management']);
        $error_message = 'Invalid security token. Please refresh the page and try again.';
    } else {
        if (isset($_POST['action']) && $_POST['action'] === 'add_prefix') {
            // Check rate limiting
            if (!check_rate_limit('prefix_add', 5, 300)) { // 5 additions per 5 minutes
                log_security_event('rate_limit_exceeded', ['action' => 'prefix_add']);
                $error_message = 'Too many prefix additions. Please wait before trying again.';
            } else {
                // Add a new prefix
                $prefix = validate_text_input($_POST['prefix'] ?? '', 50);
                $name = validate_text_input($_POST['name'] ?? '', 100);
                $description = validate_text_input($_POST['description'] ?? '', 500);
                $is_active = isset($_POST['is_active']) ? 1 : 0;

                // Validate prefix format
                if (!$prefix || !validate_prefix_format($prefix)) {
                    $error_message = "Invalid prefix format. It must be in the format 'edtechid.ALPHANUMERIC'.";
                } elseif (!$name) {
                    $error_message = "Category name is required.";
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
                            log_security_event('prefix_added', ['prefix' => $prefix, 'name' => $name]);
                        } else {
                            $error_message = "Failed to add new category.";
                            log_security_event('database_error', ['error' => $conn->error, 'action' => 'prefix_add']);
                        }
                        $stmt->close();
                    }
                }
            }
        } elseif (isset($_POST['action']) && $_POST['action'] === 'update_prefix') {
            // Check rate limiting
            if (!check_rate_limit('prefix_update', 10, 300)) { // 10 updates per 5 minutes
                log_security_event('rate_limit_exceeded', ['action' => 'prefix_update']);
                $error_message = 'Too many prefix updates. Please wait before trying again.';
            } else {
                // Update existing prefix
                $prefix = validate_text_input($_POST['prefix'] ?? '', 50);
                $name = validate_text_input($_POST['name'] ?? '', 100);
                $description = validate_text_input($_POST['description'] ?? '', 500);
                $is_active = isset($_POST['is_active']) ? 1 : 0;

                if (!$prefix || !$name) {
                    $error_message = "Prefix and name are required.";
                } else {
                    $stmt = $conn->prepare("UPDATE prefixes SET name = ?, description = ?, is_active = ? WHERE prefix = ?");
                    $stmt->bind_param("ssis", $name, $description, $is_active, $prefix);

                    if ($stmt->execute()) {
                        $success_message = "Category updated successfully.";
                        log_security_event('prefix_updated', ['prefix' => $prefix, 'name' => $name]);
                    } else {
                        $error_message = "Failed to update category.";
                        log_security_event('database_error', ['error' => $conn->error, 'action' => 'prefix_update']);
                    }
                    $stmt->close();
                }
            }
        }
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
    <title>Category Management - EdTech Identifier System</title>
    <link rel="stylesheet" href="../styles.css">
    <link rel="icon" type="image/x-icon"
        href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📁</text></svg>">
</head>

<body>
    <header class="page-header">
        <div class="header-content">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1 class="page-title">Category Management</h1>
                    <p class="page-subtitle">Manage identifier prefixes and categories for the EdTech system</p>
                </div>
                <div style="display: flex; align-items: center; gap: var(--cds-spacing-04);">
                    <a href="index.php" class="btn btn-secondary">← Back to Dashboard</a>
                    <a href="?logout=1" class="btn btn-secondary">Log Out</a>
                </div>
            </div>
        </div>
    </header>

    <div class="main-container">
        <main class="content-section">
            <?php if ($success_message): ?>
                <div class="notification notification-success">
                    <div class="notification-icon">✅</div>
                    <div class="notification-content">
                        <h3 class="notification-title">Success</h3>
                        <p class="notification-message"><?php echo htmlspecialchars($success_message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="notification notification-error">
                    <div class="notification-icon">⚠️</div>
                    <div class="notification-content">
                        <h3 class="notification-title">Error</h3>
                        <p class="notification-message"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div
                style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--cds-spacing-07);">
                <h2 class="section-title">Available Categories</h2>
                <button type="button" onclick="showAddModal()" class="btn btn-primary">Add New Category</button>
            </div>

            <?php if (count($prefixes) > 0): ?>
                <div class="result-card">
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="border-bottom: 2px solid var(--cds-border-subtle-01);">
                                    <th
                                        style="text-align: left; padding: var(--cds-spacing-05); font-weight: 500; color: var(--cds-text-secondary);">
                                        Prefix</th>
                                    <th
                                        style="text-align: left; padding: var(--cds-spacing-05); font-weight: 500; color: var(--cds-text-secondary);">
                                        Name</th>
                                    <th
                                        style="text-align: left; padding: var(--cds-spacing-05); font-weight: 500; color: var(--cds-text-secondary);">
                                        Description</th>
                                    <th
                                        style="text-align: center; padding: var(--cds-spacing-05); font-weight: 500; color: var(--cds-text-secondary);">
                                        Status</th>
                                    <th
                                        style="text-align: center; padding: var(--cds-spacing-05); font-weight: 500; color: var(--cds-text-secondary);">
                                        Usage</th>
                                    <th
                                        style="text-align: center; padding: var(--cds-spacing-05); font-weight: 500; color: var(--cds-text-secondary);">
                                        Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($prefixes as $prefix): ?>
                                    <tr style="border-bottom: 1px solid var(--cds-border-subtle-01);">
                                        <td style="padding: var(--cds-spacing-05);">
                                            <div
                                                style="font-family: 'IBM Plex Mono', monospace; font-size: 0.875rem; color: var(--cds-text-primary); font-weight: 500;">
                                                <?php echo htmlspecialchars($prefix['prefix']); ?>
                                            </div>
                                            <div
                                                style="font-size: 0.75rem; color: var(--cds-text-secondary); margin-top: var(--cds-spacing-02);">
                                                Created: <?php echo date('M j, Y', strtotime($prefix['created_at'])); ?>
                                            </div>
                                        </td>
                                        <td style="padding: var(--cds-spacing-05);">
                                            <div style="font-weight: 500; color: var(--cds-text-primary);">
                                                <?php echo htmlspecialchars($prefix['name']); ?>
                                            </div>
                                        </td>
                                        <td style="padding: var(--cds-spacing-05); max-width: 250px;">
                                            <div
                                                style="color: var(--cds-text-secondary); font-size: 0.875rem; line-height: 1.4;">
                                                <?php
                                                $description = $prefix['description'] ?? '';
                                                echo htmlspecialchars(strlen($description) > 80 ? substr($description, 0, 80) . '...' : $description);
                                                ?>
                                            </div>
                                        </td>
                                        <td style="padding: var(--cds-spacing-05); text-align: center;">
                                            <span style="
                                                display: inline-block;
                                                padding: var(--cds-spacing-02) var(--cds-spacing-04);
                                                border-radius: 12px;
                                                font-size: 0.75rem;
                                                font-weight: 500;
                                                <?php if ($prefix['is_active']): ?>
                                                    background-color: #d4edda;
                                                    color: #155724;
                                                    border: 1px solid #c3e6cb;
                                                <?php else: ?>
                                                    background-color: #f8d7da;
                                                    color: #721c24;
                                                    border: 1px solid #f5c6cb;
                                                <?php endif; ?>
                                            ">
                                                <?php echo $prefix['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td style="padding: var(--cds-spacing-05); text-align: center;">
                                            <div style="font-weight: 500; color: var(--cds-text-primary);">
                                                <?php echo $prefix['usage_count']; ?>
                                            </div>
                                            <div style="font-size: 0.75rem; color: var(--cds-text-secondary);">
                                                identifiers
                                            </div>
                                        </td>
                                        <td style="padding: var(--cds-spacing-05); text-align: center;">
                                            <button type="button" class="btn btn-secondary"
                                                style="padding: var(--cds-spacing-03) var(--cds-spacing-05); min-width: auto; height: auto; font-size: 0.75rem;"
                                                onclick="editPrefix('<?php echo htmlspecialchars($prefix['prefix']); ?>', '<?php echo htmlspecialchars(addslashes($prefix['name'])); ?>', '<?php echo htmlspecialchars(addslashes($prefix['description'] ?? '')); ?>', <?php echo $prefix['is_active'] ? 'true' : 'false'; ?>)">
                                                Edit
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php else: ?>
                <div class="notification notification-info">
                    <div class="notification-icon">ℹ️</div>
                    <div class="notification-content">
                        <h3 class="notification-title">No Categories Defined</h3>
                        <p class="notification-message">
                            No identifier categories have been created yet. Get started by adding your first category.
                        </p>
                        <p class="notification-message">
                            <button type="button" onclick="showAddModal()" class="result-link">Add your first category
                                →</button>
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </main>

        <aside class="info-panel">
            <h2 class="section-title">Category Guide</h2>

            <div class="info-item">
                <h3 class="info-title">Prefix Format</h3>
                <p class="info-description">
                    All prefixes must follow the format:
                </p>
                <div class="example-code">edtechid.ALPHANUMERIC</div>
                <p class="info-description">
                    Where ALPHANUMERIC can be letters, numbers, or a combination of both.
                </p>
            </div>

            <div class="info-item">
                <h3 class="info-title">Example Prefixes</h3>
                <div style="color: var(--cds-text-secondary); font-size: 0.875rem; margin: var(--cds-spacing-04) 0;">
                    <div style="margin-bottom: var(--cds-spacing-03);">
                        <strong>edtechid.100</strong> - General resources
                    </div>
                    <div style="margin-bottom: var(--cds-spacing-03);">
                        <strong>edtechid.oer</strong> - Open educational resources
                    </div>
                    <div style="margin-bottom: var(--cds-spacing-03);">
                        <strong>edtechid.mit</strong> - MIT course materials
                    </div>
                    <div>
                        <strong>edtechid.research</strong> - Research papers and datasets
                    </div>
                </div>
            </div>

            <div class="info-item">
                <h3 class="info-title">Category Status</h3>
                <p class="info-description">
                    Categories can be active or inactive:
                </p>
                <ul
                    style="color: var(--cds-text-secondary); font-size: 0.875rem; margin: var(--cds-spacing-04) 0; padding-left: var(--cds-spacing-06);">
                    <li><strong>Active:</strong> Available for new identifier creation</li>
                    <li><strong>Inactive:</strong> Hidden from public forms but existing identifiers still work</li>
                </ul>
            </div>

            <div class="info-item">
                <h3 class="info-title">Usage Statistics</h3>
                <p class="info-description">
                    The usage count shows how many identifiers are currently using each prefix. Categories with existing
                    identifiers cannot be deleted.
                </p>
            </div>

            <div class="info-item">
                <h3 class="info-title">Quick Actions</h3>
                <div
                    style="display: flex; flex-direction: column; gap: var(--cds-spacing-04); margin-top: var(--cds-spacing-04);">
                    <a href="index.php" class="result-link">← Back to Dashboard</a>
                    <!-- Create New Identifier functionality will be implemented via API -->
                    <a href="../list.php" class="result-link">Browse All Identifiers</a>
                </div>
            </div>
        </aside>
    </div>

    <!-- Add Category Modal -->
    <div style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 1000; justify-content: center; align-items: center;"
        id="add-modal">
        <div
            style="background-color: var(--cds-background); padding: var(--cds-spacing-08); max-width: 600px; width: 90%; border: 1px solid var(--cds-border-subtle-01); max-height: 90vh; overflow-y: auto;">
            <h3
                style="margin: 0 0 var(--cds-spacing-06) 0; font-size: 1.25rem; font-weight: 500; color: var(--cds-text-primary);">
                Add New Category</h3>

            <form method="post" id="add-form">
                <input type="hidden" name="action" value="add_prefix">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">

                <div class="form-group">
                    <label for="prefix" class="form-label">Prefix *</label>
                    <input type="text" id="prefix" name="prefix" class="text-input" placeholder="edtechid.example"
                        pattern="^edtechid\.[0-9a-zA-Z]+$" title="Must be in format: edtechid.ALPHANUMERIC" required>
                    <div class="helper-text">Must be in the format: edtechid.XXX (e.g., edtechid.100 or
                        edtechid.research)</div>
                </div>

                <div class="form-group">
                    <label for="name" class="form-label">Category Name *</label>
                    <input type="text" id="name" name="name" class="text-input" placeholder="e.g., Research Papers"
                        required>
                    <div class="helper-text">A human-readable name for this category</div>
                </div>

                <div class="form-group">
                    <label for="description" class="form-label">Description</label>
                    <textarea name="description" id="description" class="text-input"
                        style="height: 96px; resize: vertical;"
                        placeholder="Brief description of this category and its intended use"></textarea>
                    <div class="helper-text">Optional description to help users understand when to use this category
                    </div>
                </div>

                <div class="form-group">
                    <div style="display: flex; align-items: center; margin-bottom: var(--cds-spacing-04);">
                        <input type="checkbox" name="is_active" id="is_active"
                            style="width: auto; margin-right: var(--cds-spacing-03);" checked>
                        <label for="is_active" class="form-label" style="margin-bottom: 0; font-weight: 400;">
                            Active (available for new identifiers)
                        </label>
                    </div>
                    <div class="helper-text">Active categories appear in dropdown menus for new identifier creation
                    </div>
                </div>

                <div
                    style="display: flex; justify-content: flex-end; gap: var(--cds-spacing-04); margin-top: var(--cds-spacing-07);">
                    <button type="button" onclick="closeAddModal()" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Category</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Category Modal -->
    <div style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 1000; justify-content: center; align-items: center;"
        id="edit-modal">
        <div
            style="background-color: var(--cds-background); padding: var(--cds-spacing-08); max-width: 600px; width: 90%; border: 1px solid var(--cds-border-subtle-01); max-height: 90vh; overflow-y: auto;">
            <h3
                style="margin: 0 0 var(--cds-spacing-06) 0; font-size: 1.25rem; font-weight: 500; color: var(--cds-text-primary);">
                Edit Category</h3>

            <form method="post" id="edit-form">
                <input type="hidden" name="action" value="update_prefix">
                <input type="hidden" id="edit-prefix" name="prefix" value="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">

                <div class="form-group">
                    <label class="form-label">Prefix</label>
                    <div style="padding: var(--cds-spacing-04); background-color: var(--cds-layer-01); border: 1px solid var(--cds-border-subtle-01); font-family: 'IBM Plex Mono', monospace; color: var(--cds-text-secondary);"
                        id="edit-prefix-display">
                        edtechid.example
                    </div>
                    <div class="helper-text">Prefix cannot be changed after creation</div>
                </div>

                <div class="form-group">
                    <label for="edit-name" class="form-label">Category Name *</label>
                    <input type="text" id="edit-name" name="name" class="text-input" required>
                    <div class="helper-text">A human-readable name for this category</div>
                </div>

                <div class="form-group">
                    <label for="edit-description" class="form-label">Description</label>
                    <textarea name="description" id="edit-description" class="text-input"
                        style="height: 96px; resize: vertical;"></textarea>
                    <div class="helper-text">Optional description to help users understand when to use this category
                    </div>
                </div>

                <div class="form-group">
                    <div style="display: flex; align-items: center; margin-bottom: var(--cds-spacing-04);">
                        <input type="checkbox" name="is_active" id="edit-is-active"
                            style="width: auto; margin-right: var(--cds-spacing-03);">
                        <label for="edit-is-active" class="form-label" style="margin-bottom: 0; font-weight: 400;">
                            Active (available for new identifiers)
                        </label>
                    </div>
                    <div class="helper-text">Active categories appear in dropdown menus for new identifier creation
                    </div>
                </div>

                <div
                    style="display: flex; justify-content: flex-end; gap: var(--cds-spacing-04); margin-top: var(--cds-spacing-07);">
                    <button type="button" onclick="closeEditModal()" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Category</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const addModal = document.getElementById('add-modal');
        const editModal = document.getElementById('edit-modal');

        function showAddModal() {
            // Reset form
            document.getElementById('add-form').reset();
            document.getElementById('is_active').checked = true;
            addModal.style.display = 'flex';

            // Focus first input
            setTimeout(() => {
                document.getElementById('prefix').focus();
            }, 100);
        }

        function closeAddModal() {
            addModal.style.display = 'none';
        }

        function editPrefix(prefix, name, description, isActive) {
            document.getElementById('edit-prefix').value = prefix;
            document.getElementById('edit-prefix-display').textContent = prefix;
            document.getElementById('edit-name').value = name;
            document.getElementById('edit-description').value = description;
            document.getElementById('edit-is-active').checked = isActive;

            editModal.style.display = 'flex';

            // Focus first editable input
            setTimeout(() => {
                document.getElementById('edit-name').focus();
            }, 100);
        }

        function closeEditModal() {
            editModal.style.display = 'none';
        }

        // Close modals when clicking outside
        [addModal, editModal].forEach(modal => {
            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    if (modal === addModal) closeAddModal();
                    if (modal === editModal) closeEditModal();
                }
            });
        });

        // Add keyboard support
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                if (addModal.style.display === 'flex') closeAddModal();
                if (editModal.style.display === 'flex') closeEditModal();
            }
        });

        // Prefix format validation
        document.getElementById('prefix').addEventListener('input', function () {
            const value = this.value;
            const isValid = /^edtechid\.[0-9a-zA-Z]*$/.test(value);

            if (value && !value.startsWith('edtechid.')) {
                this.style.borderColor = 'var(--cds-support-error)';
            } else if (value && !isValid) {
                this.style.borderColor = 'var(--cds-support-error)';
            } else {
                this.style.borderColor = '';
            }
        });
    </script>
</body>

</html>
