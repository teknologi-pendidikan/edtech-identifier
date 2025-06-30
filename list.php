<?php
require_once __DIR__ . '/includes/config.php';

// Connect to database
$conn = create_db_connection($db_config);
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Get filter parameters
$prefix_filter = isset($_GET['prefix']) ? $_GET['prefix'] : '';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

// Prepare the base query
$query = "SELECT i.prefix, i.suffix, i.title, i.description, i.target_url, i.created_at,
                 p.name as prefix_name
          FROM identifiers i
          JOIN prefixes p ON i.prefix = p.prefix";

// Add filters if provided
$params = [];
$types = "";

if (!empty($prefix_filter) && !empty($search_term)) {
    $query .= " WHERE i.prefix = ? AND (i.title LIKE ? OR i.description LIKE ?)";
    $params[] = $prefix_filter;
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
    $types = "sss";
} elseif (!empty($prefix_filter)) {
    $query .= " WHERE i.prefix = ?";
    $params[] = $prefix_filter;
    $types = "s";
} elseif (!empty($search_term)) {
    $query .= " WHERE i.title LIKE ? OR i.description LIKE ?";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
    $types = "ss";
}

// Add sorting (newest first)
$query .= " ORDER BY i.created_at DESC";

// Prepare and execute the query
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
    <title>Public Directory - EdTech Identifier System</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="icon" type="image/x-icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📖</text></svg>">
</head>

<body>
    <header class="page-header">
        <div class="header-content">
            <h1 class="page-title">Public Directory</h1>
            <p class="page-subtitle">Browse and discover educational technology resources</p>
        </div>
    </header>

    <div class="main-container">
        <main class="content-section">
            <h2 class="section-title">Resource Directory</h2>
            <p class="helper-text">
                Explore the complete collection of registered EdTech identifiers. Use the filters below to find specific resources or browse by category.
            </p>

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
                        <label for="search" class="form-label">Search Resources</label>
                        <input type="text"
                               name="search"
                               id="search"
                               class="text-input"
                               value="<?php echo htmlspecialchars($search_term); ?>"
                               placeholder="Search by title or description">
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div style="color: var(--cds-text-secondary); font-size: 0.875rem;">
                        <?php if (!empty($prefix_filter) || !empty($search_term)): ?>
                            Filtered results
                        <?php else: ?>
                            Showing all resources
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; gap: var(--cds-spacing-04);">
                        <?php if (!empty($prefix_filter) || !empty($search_term)): ?>
                            <a href="?" class="btn btn-secondary">Clear Filters</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                    </div>
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
                                    <th style="text-align: left; padding: var(--cds-spacing-05); font-weight: 500; color: var(--cds-text-secondary);">Description</th>
                                    <th style="text-align: left; padding: var(--cds-spacing-05); font-weight: 500; color: var(--cds-text-secondary);">Created</th>
                                    <th style="text-align: center; padding: var(--cds-spacing-05); font-weight: 500; color: var(--cds-text-secondary);">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr style="border-bottom: 1px solid var(--cds-border-subtle-01);">
                                        <td style="padding: var(--cds-spacing-05);">
                                            <div style="font-family: 'IBM Plex Mono', monospace; font-size: 0.875rem; margin-bottom: var(--cds-spacing-02);">
                                                <a href="https://<?php echo $_SERVER['HTTP_HOST']; ?>/<?php echo htmlspecialchars($row['prefix']); ?>/<?php echo htmlspecialchars($row['suffix']); ?>"
                                                   class="result-link" target="_blank" rel="noopener noreferrer">
                                                    <?php echo htmlspecialchars($row['prefix']); ?>/<strong><?php echo htmlspecialchars($row['suffix']); ?></strong> ↗
                                                </a>
                                            </div>
                                            <?php if ($row['prefix_name']): ?>
                                                <div style="font-size: 0.75rem; color: var(--cds-text-secondary);">
                                                    <?php echo htmlspecialchars($row['prefix_name']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: var(--cds-spacing-05);">
                                            <div style="font-weight: 500; color: var(--cds-text-primary);">
                                                <?php echo htmlspecialchars($row['title'] ?: 'Untitled Resource'); ?>
                                            </div>
                                        </td>
                                        <td style="padding: var(--cds-spacing-05); max-width: 300px;">
                                            <div style="color: var(--cds-text-secondary); font-size: 0.875rem; line-height: 1.4;">
                                                <?php
                                                $description = $row['description'] ?: 'No description available';
                                                echo htmlspecialchars(strlen($description) > 120 ? substr($description, 0, 120) . '...' : $description);
                                                ?>
                                            </div>
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
                                            <button type="button"
                                                    class="btn btn-secondary"
                                                    style="padding: var(--cds-spacing-03) var(--cds-spacing-05); min-width: auto; height: auto; font-size: 0.75rem;"
                                                    onclick="showQRCode('https://<?php echo $_SERVER['HTTP_HOST']; ?>/<?php echo htmlspecialchars($row['prefix']); ?>/<?php echo htmlspecialchars($row['suffix']); ?>', '<?php echo htmlspecialchars(addslashes($row['title'] ?: 'Resource')); ?>')">
                                                QR Code
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php else: ?>
                <div class="notification notification-info">
                    <div class="notification-icon">ℹ️</div>
                    <div class="notification-content">
                        <h3 class="notification-title">No Resources Found</h3>
                        <p class="notification-message">
                            <?php if (!empty($search_term) || !empty($prefix_filter)): ?>
                                No resources match your current filter criteria. Try adjusting your search terms or clearing the filters.
                            <?php else: ?>
                                No resources have been registered yet. Be the first to create an identifier.
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

            <div style="text-align: center; margin-top: var(--cds-spacing-08);">
                <a href="deposit.php" class="btn btn-primary">Create New Identifier</a>
            </div>
        </main>

        <aside class="info-panel">
            <h2 class="section-title">Directory Guide</h2>

            <div class="info-item">
                <h3 class="info-title">About This Directory</h3>
                <p class="info-description">
                    This public directory contains all registered EdTech identifiers. Each entry represents a unique educational resource with its own persistent identifier.
                </p>
            </div>

            <div class="info-item">
                <h3 class="info-title">How to Use</h3>
                <ul style="color: var(--cds-text-secondary); font-size: 0.875rem; margin: var(--cds-spacing-04) 0; padding-left: var(--cds-spacing-06);">
                    <li>Click on identifiers to visit the resources directly</li>
                    <li>Use filters to narrow down by category</li>
                    <li>Search by title or description keywords</li>
                    <li>Generate QR codes for easy mobile access</li>
                </ul>
            </div>

            <div class="info-item">
                <h3 class="info-title">QR Code Feature</h3>
                <p class="info-description">
                    Generate QR codes for any resource to enable quick mobile access. Perfect for sharing in presentations, documents, or physical materials.
                </p>
            </div>

            <div class="info-item">
                <h3 class="info-title">Resource Categories</h3>
                <p class="info-description">
                    Resources are organized by prefixes that indicate their type or origin. Common categories include:
                </p>
                <div style="color: var(--cds-text-secondary); font-size: 0.875rem; margin-top: var(--cds-spacing-04);">
                    <?php if (!empty($prefixes)): ?>
                        <?php foreach (array_slice($prefixes, 0, 4) as $prefix): ?>
                            <div style="margin-bottom: var(--cds-spacing-02);">
                                <strong><?php echo htmlspecialchars($prefix['name']); ?></strong>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($prefixes) > 4): ?>
                            <div style="color: var(--cds-text-placeholder);">
                                ...and <?php echo count($prefixes) - 4; ?> more categories
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="info-item">
                <h3 class="info-title">Quick Actions</h3>
                <div style="display: flex; flex-direction: column; gap: var(--cds-spacing-04); margin-top: var(--cds-spacing-04);">
                    <a href="index.php" class="result-link">← Back to Lookup</a>
                    <a href="deposit.php" class="result-link">Create New Identifier</a>
                    <a href="bulk_upload.php" class="result-link">Bulk Upload</a>
                    <a href="admin/" class="result-link">Admin Panel</a>
                </div>
            </div>
        </aside>
    </div>

    <!-- QR Code Modal -->
    <div style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 1000; justify-content: center; align-items: center;" id="qr-modal">
        <div style="background-color: var(--cds-background); padding: var(--cds-spacing-08); max-width: 500px; width: 90%; border: 1px solid var(--cds-border-subtle-01); text-align: center;">
            <h3 style="margin: 0 0 var(--cds-spacing-06) 0; font-size: 1.25rem; font-weight: 500; color: var(--cds-text-primary);" id="qr-title">QR Code</h3>

            <div style="display: flex; justify-content: center; margin: var(--cds-spacing-06) 0;" id="qr-container">
                <!-- QR code will be generated here -->
            </div>

            <div style="background-color: var(--cds-layer-01); padding: var(--cds-spacing-04); border-radius: 4px; margin: var(--cds-spacing-06) 0;">
                <div style="font-size: 0.875rem; color: var(--cds-text-secondary); margin-bottom: var(--cds-spacing-02);">Target URL:</div>
                <div style="font-family: 'IBM Plex Mono', monospace; font-size: 0.875rem; color: var(--cds-text-primary); word-break: break-all;" id="qr-url">
                    <!-- URL will be displayed here -->
                </div>
            </div>

            <div style="display: flex; justify-content: center; gap: var(--cds-spacing-04);">
                <button type="button" onclick="closeQRModal()" class="btn btn-secondary">Close</button>
                <button type="button" onclick="downloadQRCode()" class="btn btn-primary">Download QR Code</button>
            </div>
        </div>
    </div>

    <!-- Include QRCode.js library -->
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

    <script>
        const qrModal = document.getElementById('qr-modal');
        const qrContainer = document.getElementById('qr-container');
        const qrTitle = document.getElementById('qr-title');
        const qrUrl = document.getElementById('qr-url');
        let currentQRCode = null;

        function showQRCode(url, title) {
            // Clear previous QR code
            qrContainer.innerHTML = '';

            // Create new QR code
            currentQRCode = new QRCode(qrContainer, {
                text: url,
                width: 200,
                height: 200,
                colorDark: "#161616",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.H
            });

            // Update modal content
            qrTitle.textContent = `QR Code for: ${title}`;
            qrUrl.textContent = url;

            // Show modal
            qrModal.style.display = 'flex';
        }

        function closeQRModal() {
            qrModal.style.display = 'none';
        }

        function downloadQRCode() {
            const canvas = qrContainer.querySelector('canvas');
            if (canvas) {
                const url = canvas.toDataURL('image/png');
                const link = document.createElement('a');
                link.href = url;
                link.download = 'edtech-identifier-qr.png';
                link.style.display = 'none';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }

        // Close modal when clicking outside
        qrModal.addEventListener('click', function(event) {
            if (event.target === qrModal) {
                closeQRModal();
            }
        });

        // Add keyboard support
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && qrModal.style.display === 'flex') {
                closeQRModal();
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
