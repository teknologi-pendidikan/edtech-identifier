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

// Handle logout
if (isset($_GET['logout'])) {
    logout();
    header('Location: login.php');
    exit;
}

// Handle delete action
$delete_success = false;
$delete_error = '';

if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    // Check CSRF token
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        log_security_event('csrf_token_invalid', ['action' => 'delete_identifier']);
        $delete_error = 'Invalid security token. Please refresh the page and try again.';
    } else {
        // Check rate limiting
        if (!check_rate_limit('admin_delete', 10, 300)) { // 10 deletes per 5 minutes
            log_security_event('rate_limit_exceeded', ['action' => 'admin_delete']);
            $delete_error = 'Too many delete operations. Please wait before trying again.';
        } else {
            $prefix = validate_text_input($_POST['prefix'] ?? '', 50);
            $suffix = validate_text_input($_POST['suffix'] ?? '', 100);

            if (!empty($prefix) && !empty($suffix)) {
                $stmt = $conn->prepare("DELETE FROM identifiers WHERE prefix = ? AND suffix = ?");
                $stmt->bind_param("ss", $prefix, $suffix);

                if ($stmt->execute()) {
                    $delete_success = true;

                    // Log the deletion
                    $log_stmt = $conn->prepare("INSERT INTO identifier_logs (identifier_id, action, changed_by, details) VALUES (UUID(), 'delete', ?, ?)");
                    $username = $_SESSION['username'] ?? 'admin';
                    $details = json_encode(['prefix' => $prefix, 'suffix' => $suffix]);
                    $log_stmt->bind_param("ss", $username, $details);
                    $log_stmt->execute();
                    $log_stmt->close();

                    log_security_event('identifier_deleted', ['prefix' => $prefix, 'suffix' => $suffix]);
                } else {
                    $delete_error = "Failed to delete the identifier: " . $conn->error;
                    log_security_event('database_error', ['error' => $conn->error, 'action' => 'delete']);
                }
                $stmt->close();
            } else {
                $delete_error = "Invalid identifier data provided.";
            }
        }
    }
}

// Get pagination parameters
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get filter parameters
$prefix_filter = isset($_GET['prefix']) ? $_GET['prefix'] : '';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

// Prepare the base query for counting total records
$count_query = "SELECT COUNT(*) FROM identifiers i";

// Prepare the base query for fetching records
$query = "SELECT i.id, i.prefix, i.suffix, i.title, i.target_url, i.created_at,
                 p.name as prefix_name
          FROM identifiers i
          LEFT JOIN prefixes p ON i.prefix = p.prefix";

// Add filters if provided
$where_conditions = [];
$params = [];
$types = "";

if (!empty($prefix_filter)) {
    $where_conditions[] = "i.prefix = ?";
    $params[] = $prefix_filter;
    $types .= "s";
}

if (!empty($search_term)) {
    $where_conditions[] = "(i.title LIKE ? OR i.suffix LIKE ?)";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
    $types .= "ss";
}

// Combine WHERE conditions if any
if (!empty($where_conditions)) {
    $where_clause = " WHERE " . implode(" AND ", $where_conditions);
    $count_query .= $where_clause;
    $query .= $where_clause;
}

// Add sorting and pagination
$query .= " ORDER BY i.created_at DESC LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

// Execute count query to get total records
$count_stmt = $conn->prepare($count_query);
if (!empty($types) && count($params) > 0) {
    // Only bind parameters if there are conditions
    $count_param_types = substr($types, 0, -2); // Remove 'ii' for LIMIT and OFFSET
    $count_params = array_slice($params, 0, -2);
    if (!empty($count_param_types)) {
        $count_stmt->bind_param($count_param_types, ...$count_params);
    }
}
$count_stmt->execute();
$count_stmt->bind_result($total_records);
$count_stmt->fetch();
$count_stmt->close();

$total_pages = ceil($total_records / $per_page);

// Execute main query
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Get all prefixes for the filter dropdown
$prefixes = [];
$prefix_result = $conn->query("SELECT prefix, name FROM prefixes WHERE is_active = TRUE ORDER BY name");
if ($prefix_result) {
    while ($row = $prefix_result->fetch_assoc()) {
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
    <title>Identifier Management - EdTech Identifier System</title>
    <link rel="stylesheet" href="../styles.css">
    <link rel="icon" type="image/x-icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚙️</text></svg>">
</head>
<body>
    <header class="page-header">
        <div class="header-content">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1 class="page-title">Admin Dashboard</h1>
                    <p class="page-subtitle">Manage EdTech identifiers and system configuration</p>
                </div>
                <div style="display: flex; align-items: center; gap: var(--cds-spacing-06);">
                    <?php if (has_ip_bypass()): ?>
                        <div style="background-color: #e3f2fd; border: 1px solid #2196f3; padding: 8px 12px; border-radius: 4px; font-size: 0.875rem;">
                            <span style="color: #1976d2;">🏢 Institutional Access</span>
                        </div>
                    <?php endif; ?>
                    <div style="text-align: right;">
                        <div style="font-size: 0.875rem; color: var(--cds-text-secondary);">Logged in as</div>
                        <div style="font-weight: 500;"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
                        <?php if (has_ip_bypass()): ?>
                            <div style="font-size: 0.75rem; color: #1976d2;">via IP bypass</div>
                        <?php endif; ?>
                    </div>
                    <a href="?logout=1" class="btn btn-secondary">Log Out</a>
                </div>
            </div>
        </div>
    </header>

    <div class="main-container">
        <main class="content-section">
            <?php if ($delete_success): ?>
                <div class="notification notification-success">
                    <div class="notification-icon">✅</div>
                    <div class="notification-content">
                        <h3 class="notification-title">Identifier Deleted</h3>
                        <p class="notification-message">The identifier has been successfully removed from the system.</p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($delete_error): ?>
                <div class="notification notification-error">
                    <div class="notification-icon">⚠️</div>
                    <div class="notification-content">
                        <h3 class="notification-title">Deletion Failed</h3>
                        <p class="notification-message"><?php echo htmlspecialchars($delete_error); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--cds-spacing-07);">
                <h2 class="section-title">Identifier Management</h2>
                <div style="display: flex; gap: var(--cds-spacing-04);">
                    <!-- Create New Identifier functionality will be implemented via API -->
                </div>
            </div>

            <form method="GET" class="result-card">
                <h3 class="result-title">🔍 Search and Filter</h3>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--cds-spacing-06); margin-bottom: var(--cds-spacing-06);">
                    <div class="form-group">
                        <label for="prefix" class="form-label">Filter by Category</label>
                        <select name="prefix" id="prefix" class="text-input">
                            <option value="">All Categories</option>
                            <?php foreach ($prefixes as $prefix): ?>
                                <option value="<?php echo htmlspecialchars($prefix['prefix']); ?>"
                                    <?php echo $prefix_filter === $prefix['prefix'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($prefix['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="search" class="form-label">Search Identifiers</label>
                        <input type="text"
                               name="search"
                               id="search"
                               class="text-input"
                               value="<?php echo htmlspecialchars($search_term); ?>"
                               placeholder="Search by title, suffix, or URL">
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div style="color: var(--cds-text-secondary); font-size: 0.875rem;">
                        Showing <?php echo min($per_page, $total_records); ?> of <?php echo $total_records; ?> identifiers
                    </div>
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                </div>
            </form>

            <?php if ($result->num_rows > 0): ?>
                <div class="result-card">
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="border-bottom: 2px solid var(--cds-border-subtle-01);">
                                    <th style="text-align: left; padding: var(--cds-spacing-05); font-weight: 500; color: var(--cds-text-secondary);">Identifier</th>
                                    <th style="text-align: left; padding: var(--cds-spacing-05); font-weight: 500; color: var(--cds-text-secondary);">Title</th>
                                    <th style="text-align: left; padding: var(--cds-spacing-05); font-weight: 500; color: var(--cds-text-secondary);">Target URL</th>
                                    <th style="text-align: left; padding: var(--cds-spacing-05); font-weight: 500; color: var(--cds-text-secondary);">Created</th>
                                    <th style="text-align: center; padding: var(--cds-spacing-05); font-weight: 500; color: var(--cds-text-secondary);">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr style="border-bottom: 1px solid var(--cds-border-subtle-01);">
                                        <td style="padding: var(--cds-spacing-05);">
                                            <div style="font-family: 'IBM Plex Mono', monospace; font-size: 0.875rem; color: var(--cds-text-primary);">
                                                <?php echo htmlspecialchars($row['prefix']); ?>/<strong><?php echo htmlspecialchars($row['suffix']); ?></strong>
                                            </div>
                                            <?php if ($row['prefix_name']): ?>
                                                <div style="font-size: 0.75rem; color: var(--cds-text-secondary); margin-top: var(--cds-spacing-02);">
                                                    <?php echo htmlspecialchars($row['prefix_name']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: var(--cds-spacing-05);">
                                            <div style="font-weight: 500; color: var(--cds-text-primary);">
                                                <?php echo htmlspecialchars($row['title'] ?: 'Untitled Resource'); ?>
                                            </div>
                                        </td>
                                        <td style="padding: var(--cds-spacing-05); max-width: 200px;">
                                            <a href="<?php echo htmlspecialchars($row['target_url']); ?>"
                                               target="_blank"
                                               rel="noopener noreferrer"
                                               class="result-link"
                                               style="display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <?php echo htmlspecialchars($row['target_url']); ?> ↗
                                            </a>
                                        </td>
                                        <td style="padding: var(--cds-spacing-05);">
                                            <div style="font-size: 0.875rem; color: var(--cds-text-secondary);">
                                                <?php echo date('M j, Y', strtotime($row['created_at'])); ?>
                                            </div>
                                            <div style="font-size: 0.75rem; color: var(--cds-text-placeholder);">
                                                <?php echo date('g:i A', strtotime($row['created_at'])); ?>
                                            </div>
                                        </td>
                                        <td style="padding: var(--cds-spacing-05); text-align: center;">
                                            <div style="display: flex; gap: var(--cds-spacing-03); justify-content: center;">
                                                <a href="edit.php?id=<?php echo htmlspecialchars($row['id']); ?>"
                                                   class="btn btn-secondary"
                                                   style="padding: var(--cds-spacing-03) var(--cds-spacing-05); min-width: auto; height: auto; font-size: 0.75rem;">
                                                    Edit
                                                </a>
                                                <button type="button"
                                                        class="btn"
                                                        style="background-color: var(--cds-support-error); color: var(--cds-text-on-color); padding: var(--cds-spacing-03) var(--cds-spacing-05); min-width: auto; height: auto; font-size: 0.75rem;"
                                                        onclick="confirmDelete('<?php echo htmlspecialchars($row['prefix']); ?>', '<?php echo htmlspecialchars($row['suffix']); ?>', '<?php echo htmlspecialchars($row['title'] ?: 'this resource'); ?>')">
                                                    Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <div style="display: flex; justify-content: center; align-items: center; gap: var(--cds-spacing-04); margin-top: var(--cds-spacing-07); padding-top: var(--cds-spacing-06); border-top: 1px solid var(--cds-border-subtle-01);">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>&prefix=<?php echo urlencode($prefix_filter); ?>&search=<?php echo urlencode($search_term); ?>"
                                   class="btn btn-secondary"
                                   style="padding: var(--cds-spacing-04) var(--cds-spacing-05); min-width: auto; height: auto;">
                                    ← Previous
                                </a>
                            <?php endif; ?>

                            <div style="display: flex; gap: var(--cds-spacing-02);">
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);

                                if ($start_page > 1) {
                                    echo '<a href="?page=1&prefix=' . urlencode($prefix_filter) . '&search=' . urlencode($search_term) . '" style="padding: var(--cds-spacing-03) var(--cds-spacing-04); text-decoration: none; color: var(--cds-link-primary); border: 1px solid var(--cds-border-subtle-01); font-size: 0.875rem;">1</a>';
                                    if ($start_page > 2) {
                                        echo '<span style="padding: var(--cds-spacing-03) var(--cds-spacing-04); color: var(--cds-text-secondary);">...</span>';
                                    }
                                }

                                for ($i = $start_page; $i <= $end_page; $i++) {
                                    if ($i == $page) {
                                        echo '<span style="padding: var(--cds-spacing-03) var(--cds-spacing-04); background-color: var(--cds-button-primary); color: var(--cds-text-on-color); font-weight: 500; font-size: 0.875rem;">' . $i . '</span>';
                                    } else {
                                        echo '<a href="?page=' . $i . '&prefix=' . urlencode($prefix_filter) . '&search=' . urlencode($search_term) . '" style="padding: var(--cds-spacing-03) var(--cds-spacing-04); text-decoration: none; color: var(--cds-link-primary); border: 1px solid var(--cds-border-subtle-01); font-size: 0.875rem;">' . $i . '</a>';
                                    }
                                }

                                if ($end_page < $total_pages) {
                                    if ($end_page < $total_pages - 1) {
                                        echo '<span style="padding: var(--cds-spacing-03) var(--cds-spacing-04); color: var(--cds-text-secondary);">...</span>';
                                    }
                                    echo '<a href="?page=' . $total_pages . '&prefix=' . urlencode($prefix_filter) . '&search=' . urlencode($search_term) . '" style="padding: var(--cds-spacing-03) var(--cds-spacing-04); text-decoration: none; color: var(--cds-link-primary); border: 1px solid var(--cds-border-subtle-01); font-size: 0.875rem;">' . $total_pages . '</a>';
                                }
                                ?>
                            </div>

                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page + 1; ?>&prefix=<?php echo urlencode($prefix_filter); ?>&search=<?php echo urlencode($search_term); ?>"
                                   class="btn btn-secondary"
                                   style="padding: var(--cds-spacing-04) var(--cds-spacing-05); min-width: auto; height: auto;">
                                    Next →
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="notification notification-info">
                    <div class="notification-icon">ℹ️</div>
                    <div class="notification-content">
                        <h3 class="notification-title">No Identifiers Found</h3>
                        <p class="notification-message">
                            <?php if (!empty($search_term) || !empty($prefix_filter)): ?>
                                No identifiers match your current filter criteria. Try adjusting your search terms or clearing the filters.
                            <?php else: ?>
                                No identifiers have been created yet. Get started by creating your first identifier.
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($search_term) || !empty($prefix_filter)): ?>
                            <p class="notification-message">
                                <a href="?" class="result-link">Clear all filters</a>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </main>

        <aside class="info-panel">
            <h2 class="section-title">Quick Actions</h2>

            <div class="info-item">
                <h3 class="info-title">Management Tools</h3>
                <div style="display: flex; flex-direction: column; gap: var(--cds-spacing-04); margin-top: var(--cds-spacing-04);">
                    <!-- Create New Identifier functionality will be implemented via API -->
                    <a href="../bulk_upload.php" class="result-link">Bulk Upload Identifiers</a>
                    <a href="prefixes.php" class="result-link">Manage Categories</a>
                    <a href="../list.php" class="result-link">Public Directory</a>
                </div>
            </div>

            <div class="info-item">
                <h3 class="info-title">System Stats</h3>
                <div style="color: var(--cds-text-secondary); font-size: 0.875rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: var(--cds-spacing-03);">
                        <span>Total Identifiers:</span>
                        <strong><?php echo $total_records; ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: var(--cds-spacing-03);">
                        <span>Active Categories:</span>
                        <strong><?php echo count($prefixes); ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span>Current Page:</span>
                        <strong><?php echo $page; ?> of <?php echo $total_pages; ?></strong>
                    </div>
                </div>
            </div>

            <div class="info-item">
                <h3 class="info-title">Recent Activity</h3>
                <p class="info-description">
                    Monitor recent changes and additions to the identifier system through the activity logs.
                </p>
                <div style="margin-top: var(--cds-spacing-04);">
                    <a href="#" class="result-link">View Activity Log (Coming Soon)</a>
                </div>
            </div>

            <div class="info-item">
                <h3 class="info-title">Help & Documentation</h3>
                <div style="display: flex; flex-direction: column; gap: var(--cds-spacing-03); margin-top: var(--cds-spacing-04);">
                    <a href="../index.php" class="result-link">← Back to Lookup</a>
                    <a href="#" class="result-link">Admin Guide (Coming Soon)</a>
                    <a href="#" class="result-link">API Documentation (Coming Soon)</a>
                </div>
            </div>
        </aside>
    </div>

    <!-- Delete confirmation modal -->
    <div style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 1000; justify-content: center; align-items: center;" id="confirm-modal">
        <div style="background-color: var(--cds-background); padding: var(--cds-spacing-08); max-width: 500px; width: 90%; border: 1px solid var(--cds-border-subtle-01);">
            <h3 style="margin: 0 0 var(--cds-spacing-06) 0; font-size: 1.25rem; font-weight: 500; color: var(--cds-text-primary);">⚠️ Confirm Deletion</h3>

            <div class="notification notification-error" style="margin-bottom: var(--cds-spacing-06);">
                <div class="notification-content">
                    <p class="notification-message" style="margin-bottom: var(--cds-spacing-03);">
                        Are you sure you want to delete <strong id="delete-title"></strong>?
                    </p>
                    <p class="notification-message" style="margin-bottom: var(--cds-spacing-03);">
                        Identifier: <code id="delete-id"></code>
                    </p>
                    <p class="notification-message" style="margin: 0;">
                        This action cannot be undone and will permanently remove the identifier from the system.
                    </p>
                </div>
            </div>

            <form method="post" id="delete-form">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="prefix" id="delete-prefix">
                <input type="hidden" name="suffix" id="delete-suffix">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">

                <div style="display: flex; justify-content: flex-end; gap: var(--cds-spacing-04);">
                    <button type="button" onclick="closeModal()" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn" style="background-color: var(--cds-support-error); color: var(--cds-text-on-color);">
                        Delete Identifier
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const modal = document.getElementById('confirm-modal');

        function confirmDelete(prefix, suffix, title) {
            document.getElementById('delete-title').textContent = title;
            document.getElementById('delete-id').textContent = `${prefix}/${suffix}`;
            document.getElementById('delete-prefix').value = prefix;
            document.getElementById('delete-suffix').value = suffix;

            modal.style.display = 'flex';
        }

        function closeModal() {
            modal.style.display = 'none';
        }

        // Close modal when clicking outside
        modal.addEventListener('click', function(event) {
            if (event.target === modal) {
                closeModal();
            }
        });

        // Add keyboard support
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && modal.style.display === 'flex') {
                closeModal();
            }
        });

        // Auto-focus search field
        document.addEventListener('DOMContentLoaded', function() {
            const searchField = document.getElementById('search');
            if (searchField && !searchField.value) {
                searchField.focus();
            }
        });
    </script>
</body>
</html>
