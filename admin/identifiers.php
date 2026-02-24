<?php
/**
 * Identifier Management
 * EdTech Identifier System - Fresh & Simple Version
 */

// Error reporting for debugging
ini_set('display_errors', 0);
error_reporting(E_ALL);

try {
    require_once '../includes/config.php';
    require_once '../includes/auth.php';

    // Require login
    require_login();

    $conn = db_connect();

} catch (Exception $e) {
    error_log("Identifiers page error: " . $e->getMessage());
    die('<!DOCTYPE html><html><head><title>Error</title><link rel="stylesheet" href="../assets/style.css"></head><body><div class="container"><h1>System Error</h1><p>Unable to load admin page. Please check <a href="../debug.php">system debug</a> or contact administrator.</p><p><a href="login.php">← Back to Login</a></p></div></body></html>');
}

$success = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                try {
                    $namespace_id = (int)$_POST['namespace_id'];
                    $suffix = trim($_POST['suffix']);
                    $title = trim($_POST['title']);
                    $description = trim($_POST['description']);
                    $target_url = trim($_POST['target_url']);
                    $resource_type = $_POST['resource_type'];

                    if (!empty($namespace_id) && !empty($suffix) && !empty($target_url)) {
                        // Get namespace info
                        $stmt = $conn->prepare("SELECT long_form FROM namespace_mappings WHERE id = ?");
                        $stmt->bind_param("i", $namespace_id);
                        $stmt->execute();
                        $namespace = $stmt->get_result()->fetch_assoc();

                        if ($namespace) {
                            $doi = $namespace['long_form'] . '/' . $suffix;

                            // Check for duplicate
                            $stmt = $conn->prepare("SELECT doi FROM identifiers WHERE doi = ?");
                            $stmt->bind_param("s", $doi);
                            $stmt->execute();

                            if ($stmt->get_result()->num_rows === 0) {
                                // Insert identifier with proper column mapping
                                $stmt = $conn->prepare("
                                    INSERT INTO identifiers (doi, namespace_id, suffix, target_url, title, description, resource_type, STATUS, registered_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())
                                ");
                                    $stmt->bind_param("sisssss", $doi, $namespace_id, $suffix, $target_url, $title, $description, $resource_type);

                                if ($stmt->execute()) {
                                    $success = "Identifier created successfully: " . htmlspecialchars($doi);

                                    // Log the creation
                                    error_log("Identifier created: " . $doi . " by " . get_admin_username());
                                } else {
                                    $error = "Database error creating identifier: " . htmlspecialchars($stmt->error);
                                    error_log("Insert failed: " . $stmt->error . " | DOI: " . $doi);
                                }
                            } else {
                                $error = "Identifier already exists: " . htmlspecialchars($doi);
                            }
                        } else {
                            $error = "Invalid namespace selected";
                        }
                    } else {
                        $error = "Namespace, suffix, and target URL are required";
                    }
                } catch (Exception $e) {
                    $error = "Error processing request: " . htmlspecialchars($e->getMessage());
                    error_log("Add identifier exception: " . $e->getMessage());
                }
                break;

            case 'update':
                $doi = $_POST['original_doi'];
                $title = trim($_POST['title']);
                $description = trim($_POST['description']);
                $target_url = trim($_POST['target_url']);
                $resource_type = $_POST['resource_type'];
                $status = $_POST['status'];

                if (!empty($target_url)) {
                    $stmt = $conn->prepare("
                        UPDATE identifiers
                        SET title = ?, description = ?, target_url = ?, resource_type = ?, STATUS = ?, updated_at = NOW()
                        WHERE doi = ?
                    ");
                    $stmt->bind_param("ssssss", $title, $description, $target_url, $resource_type, $status, $doi);

                    if ($stmt->execute()) {
                        $success = "Identifier updated successfully";
                    } else {
                        $error = "Error updating identifier: " . $conn->error;
                    }
                } else {
                    $error = "Target URL is required";
                }
                break;

            case 'delete':
                $doi = $_POST['doi'];

                $stmt = $conn->prepare("DELETE FROM identifiers WHERE doi = ?");
                $stmt->bind_param("s", $doi);

                if ($stmt->execute()) {
                    $success = "Identifier deleted successfully";
                } else {
                    $error = "Error deleting identifier";
                }
                break;
        }
    }
}

// Get list parameters
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;
$search = trim($_GET['search'] ?? '');

// Build query
$where = '';
$params = [];
if (!empty($search)) {
    $where = "WHERE (i.doi LIKE ? OR i.title LIKE ? OR i.description LIKE ?)";
    $search_param = "%{$search}%";
    $params = [$search_param, $search_param, $search_param];
}

// Get total count
$count_sql = "SELECT COUNT(*) as count FROM identifiers i JOIN namespace_mappings nm ON i.namespace_id = nm.id $where";
$stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $stmt->bind_param("sss", ...$params);
}
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['count'];
$total_pages = ceil($total / $per_page);

// Get identifiers
$sql = "
    SELECT i.*, nm.long_form, nm.short_form, nm.category
    FROM identifiers i
    JOIN namespace_mappings nm ON i.namespace_id = nm.id
    $where
    ORDER BY i.registered_at DESC
    LIMIT $per_page OFFSET $offset
";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param("sss", ...$params);
}
$stmt->execute();
$identifiers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get namespaces for add form
$namespaces = $conn->query("SELECT * FROM namespace_mappings WHERE is_active = 1 ORDER BY category")->fetch_all(MYSQLI_ASSOC);

$show_add_form = isset($_GET['action']) && $_GET['action'] === 'add';
$edit_doi = $_GET['edit'] ?? '';
$edit_item = null;

if ($edit_doi) {
    $stmt = $conn->prepare("
        SELECT i.*, nm.long_form, nm.category
        FROM identifiers i
        JOIN namespace_mappings nm ON i.namespace_id = nm.id
        WHERE i.doi = ?
    ");
    $stmt->bind_param("s", $edit_doi);
    $stmt->execute();
    $edit_item = $stmt->get_result()->fetch_assoc();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Google Tag Manager -->
    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
    new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
    j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
    'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
    })(window,document,'script','dataLayer','GTM-MFSCQ9KR');</script>
    <!-- End Google Tag Manager -->

    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-KZK7295SVH"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());

      gtag('config', 'G-KZK7295SVH');
    </script>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Identifier Management - EdTech Identifier</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <!-- Google Tag Manager (noscript) -->
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-MFSCQ9KR"
    height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    <!-- End Google Tag Manager (noscript) -->

    <div class="header">
        <div class="container">
            <div class="flex flex-between align-center">
                <div>
                    <h1>🔗 Identifier Management</h1>
                    <p class="subtitle">Manage DOI-like identifiers and their metadata</p>
                </div>
                <div>
                    <a href="dashboard.php" class="btn btn-secondary btn-small">
                        📊 Dashboard
                    </a>
                    <a href="?logout=1" class="btn btn-secondary btn-small">
                        🚪 Logout
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Navigation -->
        <div class="nav">
            <a href="dashboard.php" class="nav-link">📊 Dashboard</a>
            <a href="prefixes.php" class="nav-link">📁 Prefixes</a>
            <a href="identifiers.php" class="nav-link active">🔗 Identifiers</a>
            <a href="bulk.php" class="nav-link">📤 Bulk Upload</a>
        </div>

        <!-- Success/Error Messages -->
        <?php if ($success): ?>
        <div class="alert alert-success">
            <?= h($success) ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-error">
            <?= h($error) ?>
        </div>
        <?php endif; ?>

        <!-- Add New Identifier Form -->
        <?php if ($show_add_form): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">➕ Add New Identifier</h2>
            </div>

            <?php if (empty($namespaces)): ?>
            <div class="alert alert-warning">
                No active namespaces found. <a href="prefixes.php?action=add" style="color: var(--cds-link-primary);">Add a namespace</a> first.
            </div>
            <?php else: ?>
            <form method="POST">
                <input type="hidden" name="action" value="add">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--cds-spacing-05);">
                    <div class="form-group">
                        <label class="form-label" for="namespace_id">Namespace *</label>
                        <select id="namespace_id" name="namespace_id" class="form-input form-select" required>
                            <option value="">Select namespace...</option>
                            <?php foreach ($namespaces as $ns): ?>
                            <option value="<?= $ns['id'] ?>">
                                <?= h($ns['category']) ?> (<?= h($ns['short_form']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="suffix">Suffix *</label>
                        <input
                            type="text"
                            id="suffix"
                            name="suffix"
                            class="form-input"
                            placeholder="e.g., 2025.0001"
                            required
                        >
                        <p class="text-muted text-small mt-2">Unique suffix for this namespace</p>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="title">Title</label>
                    <input
                        type="text"
                        id="title"
                        name="title"
                        class="form-input"
                        placeholder="Resource title or name"
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="target_url">Target URL *</label>
                    <input
                        type="url"
                        id="target_url"
                        name="target_url"
                        class="form-input"
                        placeholder="https://example.com/resource"
                        required
                    >
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--cds-spacing-05);">
                    <div class="form-group">
                        <label class="form-label" for="resource_type">Resource Type</label>
                        <select id="resource_type" name="resource_type" class="form-input form-select">
                            <option value="other">Other</option>
                            <option value="journal_article">Journal Article</option>
                            <option value="dataset">Dataset</option>
                            <option value="course_module">Course Module</option>
                            <option value="educational_material">Educational Material</option>
                            <option value="person">Person</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="description">Description</label>
                    <textarea
                        id="description"
                        name="description"
                        class="form-input form-textarea"
                        placeholder="Optional description of the resource..."
                    ></textarea>
                </div>

                <div class="flex" style="gap: var(--cds-spacing-04);">
                    <button type="submit" class="btn btn-primary">
                        ➕ Create Identifier
                    </button>
                    <a href="identifiers.php" class="btn btn-secondary">
                        ❌ Cancel
                    </a>
                </div>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Edit Form -->
        <?php if ($edit_item): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">✏️ Edit Identifier</h2>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="original_doi" value="<?= h($edit_item['doi']) ?>">

                <div class="form-group">
                    <label class="form-label">DOI (Read Only)</label>
                    <div style="font-family: monospace; background: var(--cds-layer-accent); padding: var(--cds-spacing-04); border-radius: 4px;">
                        <?= h($edit_item['doi']) ?>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="title">Title</label>
                    <input
                        type="text"
                        id="title"
                        name="title"
                        class="form-input"
                        value="<?= h($edit_item['title']) ?>"
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="target_url">Target URL *</label>
                    <input
                        type="url"
                        id="target_url"
                        name="target_url"
                        class="form-input"
                        value="<?= h($edit_item['target_url']) ?>"
                        required
                    >
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--cds-spacing-05);">
                    <div class="form-group">
                        <label class="form-label" for="resource_type">Resource Type</label>
                        <select id="resource_type" name="resource_type" class="form-input form-select">
                            <option value="other" <?= $edit_item['resource_type'] === 'other' ? 'selected' : '' ?>>Other</option>
                            <option value="journal_article" <?= $edit_item['resource_type'] === 'journal_article' ? 'selected' : '' ?>>Journal Article</option>
                            <option value="dataset" <?= $edit_item['resource_type'] === 'dataset' ? 'selected' : '' ?>>Dataset</option>
                            <option value="course_module" <?= $edit_item['resource_type'] === 'course_module' ? 'selected' : '' ?>>Course Module</option>
                            <option value="educational_material" <?= $edit_item['resource_type'] === 'educational_material' ? 'selected' : '' ?>>Educational Material</option>
                            <option value="person" <?= $edit_item['resource_type'] === 'person' ? 'selected' : '' ?>>Person</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="status">Status</label>
                        <select id="status" name="status" class="form-input form-select">
                            <option value="active" <?= $edit_item['STATUS'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="reserved" <?= $edit_item['STATUS'] === 'reserved' ? 'selected' : '' ?>>Reserved</option>
                            <option value="withdrawn" <?= $edit_item['STATUS'] === 'withdrawn' ? 'selected' : '' ?>>Withdrawn</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="description">Description</label>
                    <textarea
                        id="description"
                        name="description"
                        class="form-input form-textarea"
                    ><?= h($edit_item['description']) ?></textarea>
                </div>

                <div class="flex" style="gap: var(--cds-spacing-04);">
                    <button type="submit" class="btn btn-primary">
                        💾 Update Identifier
                    </button>
                    <a href="identifiers.php" class="btn btn-secondary">
                        ❌ Cancel
                    </a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Identifiers List -->
        <div class="card">
            <div class="card-header">
                <div class="flex flex-between align-center">
                    <h2 class="card-title">🔗 Identifiers (<?= number_format($total) ?>)</h2>
                    <?php if (!$show_add_form && !$edit_item): ?>
                    <a href="?action=add" class="btn btn-primary">
                        ➕ Add New Identifier
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Search -->
            <form method="GET" class="mb-3" style="border-bottom: 1px solid var(--cds-border-subtle); padding-bottom: var(--cds-spacing-05);">
                <div style="display: flex; gap: var(--cds-spacing-04); align-items: end;">
                    <div style="flex: 1;">
                        <label class="form-label" for="search">Search Identifiers</label>
                        <input
                            type="text"
                            id="search"
                            name="search"
                            class="form-input"
                            placeholder="Search by DOI, title, or description..."
                            value="<?= h($search) ?>"
                        >
                    </div>
                    <button type="submit" class="btn btn-secondary" style="white-space: nowrap;">
                        🔍 Search
                    </button>
                    <?php if (!empty($search)): ?>
                    <a href="identifiers.php" class="btn btn-secondary">
                        ❌ Clear
                    </a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (empty($identifiers)): ?>
            <div class="text-center" style="padding: var(--cds-spacing-07);">
                <div style="font-size: 3rem; margin-bottom: var(--cds-spacing-04); opacity: 0.5;">🔗</div>
                <h3 style="color: var(--cds-text-secondary); margin-bottom: var(--cds-spacing-04);">
                    <?= empty($search) ? 'No Identifiers Yet' : 'No Results Found' ?>
                </h3>
                <p class="text-muted">
                    <?= empty($search) ? 'Add your first identifier to get started.' : 'Try adjusting your search terms.' ?>
                </p>
                <?php if (empty($search)): ?>
                <a href="?action=add" class="btn btn-primary mt-3">
                    ➕ Add First Identifier
                </a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Identifier</th>
                        <th>Category</th>
                        <th>Target URL</th>
                        <th>Stats</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($identifiers as $item): ?>
                    <tr>
                        <td>
                            <div style="font-family: monospace; font-size: 0.75rem; color: var(--cds-link-primary);">
                                <?= h($item['doi']) ?>
                            </div>
                            <?php if ($item['title']): ?>
                            <div class="text-small" style="margin-top: 2px;">
                                <?= h($item['title']) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-small">
                            <?= h($item['category']) ?>
                        </td>
                        <td>
                            <a href="<?= h($item['target_url']) ?>" target="_blank"
                               style="color: var(--cds-link-primary); text-decoration: none; font-size: 0.75rem;"
                               title="<?= h($item['target_url']) ?>">
                                <?= h(substr($item['target_url'], 0, 40)) ?><?= strlen($item['target_url']) > 40 ? '...' : '' ?>
                            </a>
                        </td>
                        <td class="text-small">
                            <div>Resolved: <span style="color: var(--cds-support-success);"><?= number_format($item['resolution_count']) ?></span></div>
                            <div class="text-muted">
                                Added: <?= date('M j, Y', strtotime($item['registered_at'])) ?>
                            </div>
                        </td>
                        <td>
                            <span style="color: var(<?php
                                echo $item['STATUS'] === 'active' ? '--cds-support-success' :
                                     ($item['STATUS'] === 'withdrawn' ? '--cds-support-error' : '--cds-support-warning');
                            ?>);">
                                <?= h(ucfirst($item['STATUS'])) ?>
                            </span>
                        </td>
                        <td>
                            <div style="display: flex; gap: var(--cds-spacing-03);">
                                <a href="?edit=<?= urlencode($item['doi']) ?>" class="btn btn-secondary btn-small" title="Edit">
                                    ✏️
                                </a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this identifier?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="doi" value="<?= h($item['doi']) ?>">
                                    <button type="submit" class="btn btn-danger btn-small" title="Delete">
                                        🗑️
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div style="display: flex; justify-content: center; gap: var(--cds-spacing-04); margin-top: var(--cds-spacing-05); padding-top: var(--cds-spacing-05); border-top: 1px solid var(--cds-border-subtle);">
                <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" class="btn btn-secondary btn-small">
                    ← Previous
                </a>
                <?php endif; ?>

                <span style="color: var(--cds-text-secondary); align-self: center; font-size: 0.875rem;">
                    Page <?= $page ?> of <?= $total_pages ?>
                </span>

                <?php if ($page < $total_pages): ?>
                <a href="?page=<?= $page + 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" class="btn btn-secondary btn-small">
                    Next →
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
