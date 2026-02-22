<?php
/**
 * Prefix Management
 * EdTech Identifier System - Fresh & Simple Version
 */

// Error handling
ini_set('display_errors', 0);
error_reporting(E_ALL);

try {
    require_once '../includes/config.php';
    require_once '../includes/auth.php';

    // Check login
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }

    $conn = db_connect();

} catch (Exception $e) {
    error_log("Prefixes page error: " . $e->getMessage());
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
                    $long_form = trim($_POST['long_form']);
                    $short_form = trim($_POST['short_form']);
                    $category = trim($_POST['category']);
                    $description = trim($_POST['description']);

                    if (!empty($long_form) && !empty($short_form) && !empty($category)) {
                        // Check for duplicates
                        $stmt = $conn->prepare("SELECT id FROM namespace_mappings WHERE long_form = ? OR short_form = ?");
                        $stmt->bind_param("ss", $long_form, $short_form);
                        $stmt->execute();

                        if ($stmt->get_result()->num_rows === 0) {
                            $stmt = $conn->prepare("INSERT INTO namespace_mappings (long_form, short_form, category, description) VALUES (?, ?, ?, ?)");
                            $stmt->bind_param("ssss", $long_form, $short_form, $category, $description);

                            if ($stmt->execute()) {
                                $success = "Prefix added successfully: " . htmlspecialchars($long_form);
                                error_log("Prefix created: " . $long_form . " by " . get_admin_username());
                            } else {
                                $error = "Database error adding prefix: " . htmlspecialchars($stmt->error);
                                error_log("Prefix insert failed: " . $stmt->error);
                            }
                        } else {
                            $error = "Prefix already exists (long form or short form conflicts)";
                        }
                    } else {
                        $error = "Long form, short form, and category are required";
                    }
                } catch (Exception $e) {
                    $error = "Error processing prefix: " . htmlspecialchars($e->getMessage());
                    error_log("Add prefix exception: " . $e->getMessage());
                }
                break;

            case 'toggle_active':
                try {
                    $id = (int)$_POST['id'];
                    $stmt = $conn->prepare("UPDATE namespace_mappings SET is_active = NOT is_active WHERE id = ?");
                    $stmt->bind_param("i", $id);

                    if ($stmt->execute()) {
                        $success = "Prefix status updated successfully";
                        error_log("Prefix status toggled for ID: " . $id . " by " . get_admin_username());
                    } else {
                        $error = "Database error updating prefix: " . htmlspecialchars($stmt->error);
                    }
                } catch (Exception $e) {
                    $error = "Error updating prefix: " . htmlspecialchars($e->getMessage());
                    error_log("Toggle prefix exception: " . $e->getMessage());
                }
                break;

            case 'delete':
                try {
                    $id = (int)$_POST['id'];

                    // Check if prefix is used by identifiers
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM identifiers WHERE namespace_id = ?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $count = $stmt->get_result()->fetch_assoc()['count'];

                    if ($count > 0) {
                        $error = "Cannot delete prefix - it has {$count} associated identifiers";
                    } else {
                        $stmt = $conn->prepare("DELETE FROM namespace_mappings WHERE id = ?");
                        $stmt->bind_param("i", $id);

                        if ($stmt->execute()) {
                            $success = "Prefix deleted successfully";
                            error_log("Prefix deleted ID: " . $id . " by " . get_admin_username());
                        } else {
                            $error = "Database error deleting prefix: " . htmlspecialchars($stmt->error);
                        }
                    }
                } catch (Exception $e) {
                    $error = "Error deleting prefix: " . htmlspecialchars($e->getMessage());
                    error_log("Delete prefix exception: " . $e->getMessage());
                }
                break;
        }
    }
}

// Get all prefixes safely
try {
    // First check if identifiers table exists
    $tables_result = $conn->query("SHOW TABLES LIKE 'identifiers'");
    $identifiers_table_exists = $tables_result->num_rows > 0;

    if ($identifiers_table_exists) {
        // Check if the identifiers table has the expected structure
        $columns_result = $conn->query("SHOW COLUMNS FROM identifiers LIKE 'namespace_id'");
        $has_namespace_id = $columns_result->num_rows > 0;

        if ($has_namespace_id) {
            $prefixes_query = "
                SELECT nm.*, COUNT(i.doi) as identifier_count
                FROM namespace_mappings nm
                LEFT JOIN identifiers i ON nm.id = i.namespace_id
                GROUP BY nm.id
                ORDER BY nm.created_at DESC
            ";
        } else {
            // Fallback: just get namespace mappings without count
            $prefixes_query = "
                SELECT nm.*, 0 as identifier_count
                FROM namespace_mappings nm
                ORDER BY nm.created_at DESC
            ";
        }
    } else {
        // Identifiers table doesn't exist, just get namespace mappings
        $prefixes_query = "
            SELECT nm.*, 0 as identifier_count
            FROM namespace_mappings nm
            ORDER BY nm.created_at DESC
        ";
    }

    $result = $conn->query($prefixes_query);

    if ($result) {
        $prefixes = $result->fetch_all(MYSQLI_ASSOC);
    } else {
        $prefixes = [];
        $error = "Error loading prefixes: " . htmlspecialchars($conn->error);
        error_log("Prefixes query failed: " . $conn->error);
    }
} catch (Exception $e) {
    $prefixes = [];
    $error = "Error loading prefixes: " . htmlspecialchars($e->getMessage());
    error_log("Prefixes query exception: " . $e->getMessage());
}

$show_add_form = isset($_GET['action']) && $_GET['action'] === 'add';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prefix Management - EdTech Identifier</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <div class="header">
        <div class="container">
            <div class="flex flex-between align-center">
                <div>
                    <h1>📁 Prefix Management</h1>
                    <p class="subtitle">Manage namespace prefixes and categories</p>
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
            <a href="prefixes.php" class="nav-link active">📁 Prefixes</a>
            <a href="identifiers.php" class="nav-link">🔗 Identifiers</a>
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

        <!-- Add New Prefix Form -->
        <?php if ($show_add_form): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">➕ Add New Prefix</h2>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="add">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--cds-spacing-05);">
                    <div class="form-group">
                        <label class="form-label" for="long_form">Long Form Prefix *</label>
                        <input
                            type="text"
                            id="long_form"
                            name="long_form"
                            class="form-input"
                            placeholder="e.g., edtechid.research"
                            pattern="edtechid\.[a-zA-Z0-9_]+"
                            required
                        >
                        <p class="text-muted text-small mt-2">Must start with "edtechid." followed by alphanumeric characters</p>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="short_form">Short Form Prefix *</label>
                        <input
                            type="text"
                            id="short_form"
                            name="short_form"
                            class="form-input"
                            placeholder="e.g., er"
                            pattern="[a-z]{2,4}"
                            maxlength="4"
                            required
                        >
                        <p class="text-muted text-small mt-2">2-4 lowercase letters</p>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="category">Category Name *</label>
                    <input
                        type="text"
                        id="category"
                        name="category"
                        class="form-input"
                        placeholder="e.g., Research Papers"
                        required
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="description">Description</label>
                    <textarea
                        id="description"
                        name="description"
                        class="form-input form-textarea"
                        placeholder="Optional description of this namespace..."
                    ></textarea>
                </div>

                <div class="flex" style="gap: var(--cds-spacing-04);">
                    <button type="submit" class="btn btn-primary">
                        ➕ Add Prefix
                    </button>
                    <a href="prefixes.php" class="btn btn-secondary">
                        ❌ Cancel
                    </a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Prefixes List -->
        <div class="card">
            <div class="card-header">
                <div class="flex flex-between align-center">
                    <h2 class="card-title">📁 Namespace Prefixes</h2>
                    <?php if (!$show_add_form): ?>
                    <a href="?action=add" class="btn btn-primary">
                        ➕ Add New Prefix
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($prefixes)): ?>
            <div class="text-center" style="padding: var(--cds-spacing-07);">
                <div style="font-size: 3rem; margin-bottom: var(--cds-spacing-04); opacity: 0.5;">📁</div>
                <h3 style="color: var(--cds-text-secondary); margin-bottom: var(--cds-spacing-04);">No Prefixes Yet</h3>
                <p class="text-muted">Add your first namespace prefix to get started.</p>
                <a href="?action=add" class="btn btn-primary mt-3">
                    ➕ Add First Prefix
                </a>
            </div>
            <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Long Form</th>
                        <th>Short</th>
                        <th>Category</th>
                        <th>Identifiers</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($prefixes as $prefix): ?>
                    <tr>
                        <td>
                            <span style="font-family: monospace; color: var(--cds-link-primary);">
                                <?= h($prefix['long_form'] ?? '') ?>
                            </span>
                        </td>
                        <td>
                            <span style="font-family: monospace; background: var(--cds-layer-accent); padding: 2px 6px; border-radius: 3px;">
                                <?= h($prefix['short_form'] ?? '') ?>
                            </span>
                        </td>
                        <td><?= h($prefix['category'] ?? '') ?></td>
                        <td>
                            <span style="color: var(--cds-support-info); font-weight: 600;">
                                <?= number_format($prefix['identifier_count'] ?? 0) ?>
                            </span>
                        </td>
                        <td>
                            <span style="color: var(<?= ($prefix['is_active'] ?? 1) ? '--cds-support-success' : '--cds-support-warning' ?>);">
                                <?= ($prefix['is_active'] ?? 1) ? '🟢 Active' : '🟡 Inactive' ?>
                            </span>
                        </td>
                        <td>
                            <div style="display: flex; gap: var(--cds-spacing-03);">
                                <!-- Toggle Status -->
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="id" value="<?= $prefix['id'] ?? '' ?>">
                                    <button type="submit" class="btn btn-secondary btn-small" title="Toggle Status">
                                        <?= ($prefix['is_active'] ?? 1) ? '⏸️' : '▶️' ?>
                                    </button>
                                </form>

                                <!-- Delete (if no identifiers) -->
                                <?php if (($prefix['identifier_count'] ?? 0) === 0): ?>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this prefix?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $prefix['id'] ?? '' ?>">
                                    <button type="submit" class="btn btn-danger btn-small" title="Delete">
                                        🗑️
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php if (!empty($prefix['description'])): ?>
                    <tr style="border: none;">
                        <td colspan="6" style="padding-top: 0; padding-bottom: var(--cds-spacing-04); border: none;">
                            <div class="text-muted text-small">
                                <?= h($prefix['description'] ?? '') ?>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
