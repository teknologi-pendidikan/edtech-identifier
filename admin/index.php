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
    $prefix = $_POST['prefix'] ?? '';
    $suffix = $_POST['suffix'] ?? '';

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
        } else {
            $delete_error = "Failed to delete the identifier: " . $conn->error;
        }
        $stmt->close();
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
    <title>Identifier Management - EdTech UniverseID</title>
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
            max-width: 1200px;
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

        .user-controls {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-info {
            font-size: 14px;
            color: var(--text-light);
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

        .btn-small {
            padding: 5px 10px;
            font-size: 13px;
        }

        .btn-danger {
            background-color: var(--danger);
        }

        .btn-danger:hover {
            background-color: #d32f2f;
        }

        .container {
            background-color: var(--card-bg);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
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

        .filters {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 14px;
        }

        select, input {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 4px;
            font-family: inherit;
            font-size: 14px;
        }

        select:focus, input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 2px rgba(67, 97, 238, 0.1);
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

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 30px;
            gap: 5px;
        }

        .pagination a, .pagination span {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 4px;
            text-decoration: none;
            color: var(--text);
            background-color: var(--card-bg);
            border: 1px solid var(--border);
        }

        .pagination a:hover {
            background-color: rgba(67, 97, 238, 0.05);
            border-color: var(--primary);
        }

        .pagination .active {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .no-results {
            text-align: center;
            padding: 30px;
            color: var(--text-light);
        }

        .actions {
            display: flex;
            gap: 5px;
        }

        .confirm-modal {
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

        .confirm-dialog {
            background-color: white;
            border-radius: 8px;
            width: 400px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        }

        .confirm-dialog h3 {
            margin-top: 0;
            color: var(--danger);
        }

        .confirm-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }

        .id-display {
            font-family: monospace;
            font-weight: 600;
        }

        .truncate {
            max-width: 250px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .footer {
            text-align: center;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
            font-size: 13px;
            color: var(--text-light);
        }

        .quick-links {
            margin-top: 10px;
        }

        .quick-links a {
            color: var(--primary);
            margin: 0 10px;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <h1>EdTech UniverseID Manager</h1>
            <div class="user-controls">
                <div class="user-info">
                    Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
                </div>
                <a href="?logout=1" class="btn btn-small">Log Out</a>
            </div>
        </div>

        <div class="container">
            <?php if ($delete_success): ?>
                <div class="notification notification-success">
                    <strong>Success!</strong> The identifier has been deleted.
                </div>
            <?php endif; ?>

            <?php if ($delete_error): ?>
                <div class="notification notification-error">
                    <?php echo htmlspecialchars($delete_error); ?>
                </div>
            <?php endif; ?>

            <h2>Manage Identifiers</h2>

            <form method="GET">
                <div class="filters">
                    <div class="filter-group">
                        <label for="prefix">Filter by Category:</label>
                        <select name="prefix" id="prefix">
                            <option value="">All Categories</option>
                            <?php foreach ($prefixes as $prefix): ?>
                                <option value="<?php echo htmlspecialchars($prefix['prefix']); ?>"
                                    <?php echo $prefix_filter === $prefix['prefix'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($prefix['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="search">Search:</label>
                        <input type="text" name="search" id="search"
                            value="<?php echo htmlspecialchars($search_term); ?>"
                            placeholder="Search by title or suffix">
                    </div>

                    <button type="submit" class="btn">Apply Filters</button>
                </div>
            </form>

            <?php if ($result->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Identifier</th>
                            <th>Title</th>
                            <th>Target URL</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td class="id-display">
                                    <?php echo htmlspecialchars($row['prefix']); ?>/<?php echo htmlspecialchars($row['suffix']); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($row['title'] ?: 'Untitled'); ?>
                                </td>
                                <td class="truncate">
                                    <a href="<?php echo htmlspecialchars($row['target_url']); ?>" target="_blank">
                                        <?php echo htmlspecialchars($row['target_url']); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php echo date('M j, Y', strtotime($row['created_at'])); ?>
                                </td>
                                <td class="actions">
                                    <a href="edit.php?id=<?php echo htmlspecialchars($row['id']); ?>" class="btn btn-small">Edit</a>
                                    <button type="button" class="btn btn-small btn-danger"
                                        onclick="confirmDelete('<?php echo htmlspecialchars($row['prefix']); ?>', '<?php echo htmlspecialchars($row['suffix']); ?>', '<?php echo htmlspecialchars($row['title'] ?: 'this resource'); ?>')">
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&prefix=<?php echo urlencode($prefix_filter); ?>&search=<?php echo urlencode($search_term); ?>">&laquo; Previous</a>
                        <?php endif; ?>

                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);

                        if ($start_page > 1) {
                            echo '<a href="?page=1&prefix=' . urlencode($prefix_filter) . '&search=' . urlencode($search_term) . '">1</a>';
                            if ($start_page > 2) {
                                echo '<span>...</span>';
                            }
                        }

                        for ($i = $start_page; $i <= $end_page; $i++) {
                            if ($i == $page) {
                                echo '<span class="active">' . $i . '</span>';
                            } else {
                                echo '<a href="?page=' . $i . '&prefix=' . urlencode($prefix_filter) . '&search=' . urlencode($search_term) . '">' . $i . '</a>';
                            }
                        }

                        if ($end_page < $total_pages) {
                            if ($end_page < $total_pages - 1) {
                                echo '<span>...</span>';
                            }
                            echo '<a href="?page=' . $total_pages . '&prefix=' . urlencode($prefix_filter) . '&search=' . urlencode($search_term) . '">' . $total_pages . '</a>';
                        }
                        ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&prefix=<?php echo urlencode($prefix_filter); ?>&search=<?php echo urlencode($search_term); ?>">Next &raquo;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="no-results">
                    <p>No identifiers found matching your criteria.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="footer">
            <p>EdTech UniverseID Admin | &copy; <?php echo date('Y'); ?> Teknologi Pendidikan ID</p>
            <div class="quick-links">
                <a href="../deposit">Create New Link</a> |
                <a href="../list">Public Directory</a> |
                <a href="prefixes.php">Manage Categories</a>
            </div>
        </div>
    </div>

    <!-- Delete confirmation modal -->
    <div id="confirm-modal" class="confirm-modal">
        <div class="confirm-dialog">
            <h3>Confirm Deletion</h3>
            <p>Are you sure you want to delete <strong id="delete-title"></strong>?</p>
            <p>Identifier: <span id="delete-id"></span></p>
            <p>This action cannot be undone.</p>

            <form method="post" id="delete-form">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="prefix" id="delete-prefix">
                <input type="hidden" name="suffix" id="delete-suffix">

                <div class="confirm-actions">
                    <button type="button" onclick="closeModal()" class="btn">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete</button>
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
    </script>
</body>
</html>
