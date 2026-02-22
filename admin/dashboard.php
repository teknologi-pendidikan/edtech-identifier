<?php
/**
 * Admin Dashboard
 * EdTech Identifier System - Fresh & Simple Version
 */

require_once '../includes/config.php';
require_once '../includes/auth.php';

// Require login
require_login();

// Handle logout
if (isset($_GET['logout'])) {
    logout();
}

// Get statistics
$conn = db_connect();

// Total identifiers
$result = $conn->query("SELECT COUNT(*) as count FROM identifiers");
$total_identifiers = $result->fetch_assoc()['count'];

// Total namespaces
$result = $conn->query("SELECT COUNT(*) as count FROM namespace_mappings WHERE is_active = 1");
$total_namespaces = $result->fetch_assoc()['count'];

// Recent activity
$result = $conn->query("SELECT COUNT(*) as count FROM identifier_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$recent_activity = $result->fetch_assoc()['count'];

// Top resolving identifiers
$top_resolved = $conn->query("
    SELECT i.doi, i.title, i.resolution_count, nm.category
    FROM identifiers i
    JOIN namespace_mappings nm ON i.namespace_id = nm.id
    ORDER BY i.resolution_count DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Recent identifiers
$recent_identifiers = $conn->query("
    SELECT i.doi, i.title, i.registered_at, nm.category
    FROM identifiers i
    JOIN namespace_mappings nm ON i.namespace_id = nm.id
    ORDER BY i.registered_at DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - EdTech Identifier</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <div class="header">
        <div class="container">
            <div class="flex flex-between align-center">
                <div>
                    <h1>📊 Admin Dashboard</h1>
                    <p class="subtitle">Welcome back, <?= h(get_admin_username()) ?>!</p>
                </div>
                <div>
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
            <a href="dashboard.php" class="nav-link active">📊 Dashboard</a>
            <a href="prefixes.php" class="nav-link">📁 Prefixes</a>
            <a href="identifiers.php" class="nav-link">🔗 Identifiers</a>
            <a href="bulk.php" class="nav-link">📤 Bulk Upload</a>
        </div>

        <!-- Statistics Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: var(--cds-spacing-05); margin-bottom: var(--cds-spacing-06);">
            <div class="card">
                <div style="text-align: center;">
                    <div style="font-size: 2rem; color: var(--cds-support-info); margin-bottom: var(--cds-spacing-03);">
                        🔗
                    </div>
                    <div style="font-size: 2rem; font-weight: 600; color: var(--cds-text-primary); margin-bottom: var(--cds-spacing-03);">
                        <?= number_format($total_identifiers) ?>
                    </div>
                    <div class="text-muted">Total Identifiers</div>
                </div>
            </div>

            <div class="card">
                <div style="text-align: center;">
                    <div style="font-size: 2rem; color: var(--cds-support-success); margin-bottom: var(--cds-spacing-03);">
                        📁
                    </div>
                    <div style="font-size: 2rem; font-weight: 600; color: var(--cds-text-primary); margin-bottom: var(--cds-spacing-03);">
                        <?= number_format($total_namespaces) ?>
                    </div>
                    <div class="text-muted">Active Namespaces</div>
                </div>
            </div>

            <div class="card">
                <div style="text-align: center;">
                    <div style="font-size: 2rem; color: var(--cds-support-warning); margin-bottom: var(--cds-spacing-03);">
                        📈
                    </div>
                    <div style="font-size: 2rem; font-weight: 600; color: var(--cds-text-primary); margin-bottom: var(--cds-spacing-03);">
                        <?= number_format($recent_activity) ?>
                    </div>
                    <div class="text-muted">Activities (7 days)</div>
                </div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--cds-spacing-06);">
            <!-- Top Resolved Identifiers -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">🔥 Most Resolved</h2>
                </div>

                <?php if (empty($top_resolved)): ?>
                <div class="text-muted text-center" style="padding: var(--cds-spacing-06);">
                    No identifiers resolved yet
                </div>
                <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Identifier</th>
                            <th>Category</th>
                            <th>Resolutions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_resolved as $item): ?>
                        <tr>
                            <td>
                                <div style="font-family: monospace; font-size: 0.75rem;">
                                    <?= h($item['doi']) ?>
                                </div>
                                <?php if ($item['title']): ?>
                                <div class="text-small text-muted">
                                    <?= h(substr($item['title'], 0, 40)) ?><?= strlen($item['title']) > 40 ? '...' : '' ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-small">
                                <?= h($item['category']) ?>
                            </td>
                            <td>
                                <span style="color: var(--cds-support-success); font-weight: 600;">
                                    <?= number_format($item['resolution_count']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Recent Identifiers -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">🆕 Recently Added</h2>
                </div>

                <?php if (empty($recent_identifiers)): ?>
                <div class="text-muted text-center" style="padding: var(--cds-spacing-06);">
                    No identifiers added yet
                </div>
                <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Identifier</th>
                            <th>Category</th>
                            <th>Added</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_identifiers as $item): ?>
                        <tr>
                            <td>
                                <div style="font-family: monospace; font-size: 0.75rem;">
                                    <?= h($item['doi']) ?>
                                </div>
                                <?php if ($item['title']): ?>
                                <div class="text-small text-muted">
                                    <?= h(substr($item['title'], 0, 40)) ?><?= strlen($item['title']) > 40 ? '...' : '' ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-small">
                                <?= h($item['category']) ?>
                            </td>
                            <td class="text-small">
                                <?= date('M j', strtotime($item['registered_at'])) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card" style="margin-top: var(--cds-spacing-06);">
            <div class="card-header">
                <h2 class="card-title">⚡ Quick Actions</h2>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--cds-spacing-04);">
                <a href="identifiers.php?action=add" class="btn btn-primary">
                    ➕ Add New Identifier
                </a>
                <a href="prefixes.php?action=add" class="btn btn-secondary">
                    📁 Add New Prefix
                </a>
                <a href="bulk.php" class="btn btn-secondary">
                    📤 Bulk Upload
                </a>
                <a href="../index.php" target="_blank" class="btn btn-secondary">
                    👀 View Public Site
                </a>
            </div>
        </div>
    </div>
</body>
</html>
