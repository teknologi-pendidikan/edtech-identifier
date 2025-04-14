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
    <title>EdTech UniverseID - Resource Directory</title>
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --success: #4caf50;
            --text: #333;
            --text-light: #666;
            --bg: #f5f7fa;
            --card-bg: #fff;
            --border: #e1e4e8;
        }

        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg);
            max-width: 900px;
            margin: 40px auto;
            padding: 0 20px;
            line-height: 1.6;
            color: var(--text);
        }

        .container {
            background-color: var(--card-bg);
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        }

        h1 {
            color: var(--primary);
            margin-top: 0;
            margin-bottom: 30px;
            font-size: 26px;
            text-align: center;
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
        }

        select, input {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-family: inherit;
        }

        button {
            padding: 10px 15px;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }

        button:hover {
            background-color: var(--primary-dark);
        }

        .resource-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .resource-table th {
            text-align: left;
            padding: 12px;
            background-color: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }

        .resource-table td {
            padding: 12px;
            border-bottom: 1px solid var(--border);
        }

        .resource-table tr:hover {
            background-color: rgba(67, 97, 238, 0.03);
        }

        .resource-id {
            font-family: monospace;
            font-weight: 600;
            color: var(--primary);
        }

        .resource-title {
            font-weight: 500;
        }

        .resource-description {
            color: var(--text-light);
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .create-link {
            display: inline-block;
            margin-top: 30px;
            padding: 12px 20px;
            background-color: var(--primary);
            color: white;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            text-align: center;
        }

        .create-link:hover {
            background-color: var(--primary-dark);
        }

        .no-results {
            text-align: center;
            padding: 30px;
            color: var(--text-light);
        }

        footer {
            text-align: center;
            margin-top: 40px;
            font-size: 13px;
            color: var(--text-light);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.4);
        }

        .modal-content {
            background-color: var(--card-bg);
            margin: 15% auto;
            padding: 20px;
            border-radius: 12px;
            width: 80%;
            max-width: 500px;
            text-align: center;
        }

        .close {
            color: var(--text-light);
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover,
        .close:focus {
            color: var(--text);
            text-decoration: none;
        }

        .qr-container {
            margin: 20px auto;
        }

        .download-button {
            padding: 10px 15px;
            background-color: var(--success);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }

        .download-button:hover {
            background-color: #43a047;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>EdTech Resource Directory</h1>

        <form method="GET" action="">
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
                        placeholder="Search titles and descriptions">
                </div>

                <button type="submit">Apply Filters</button>
            </div>
        </form>

        <?php if ($result->num_rows > 0): ?>
            <table class="resource-table">
                <thead>
                    <tr>
                        <th>Identifier</th>
                        <th>Title</th>
                        <th>Description</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <a href="https://<?php echo $_SERVER['HTTP_HOST']; ?>/<?php echo htmlspecialchars($row['prefix']); ?>/<?php echo htmlspecialchars($row['suffix']); ?>"
                                   class="resource-id" target="_blank">
                                    <?php echo htmlspecialchars($row['prefix']); ?>/<?php echo htmlspecialchars($row['suffix']); ?>
                                </a>
                            </td>
                            <td class="resource-title">
                                <?php echo htmlspecialchars($row['title'] ?: 'Untitled'); ?>
                            </td>
                            <td class="resource-description">
                                <?php echo htmlspecialchars($row['description'] ?: 'No description available'); ?>
                            </td>
                            <td>
                                <?php echo date('M j, Y', strtotime($row['created_at'])); ?>
                            </td>
                            <td>
                                <button class="qr-button"
                                    data-url="https://<?php echo $_SERVER['HTTP_HOST']; ?>/<?php echo htmlspecialchars($row['prefix']); ?>/<?php echo htmlspecialchars($row['suffix']); ?>"
                                    data-title="<?php echo htmlspecialchars($row['title'] ?: 'Resource'); ?>">
                                    QR
                                </button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>

            <div id="qr-modal" class="modal">
                <div class="modal-content">
                    <span class="close">&times;</span>
                    <h3 id="qr-title">QR Code</h3>
                    <div id="qr-container" class="qr-container"></div>
                    <p class="qr-url"></p>
                    <button id="download-qr" class="download-button">Download QR Code</button>
                </div>
            </div>
        <?php else: ?>
            <div class="no-results">
                <p>No resources found matching your criteria.</p>
            </div>
        <?php endif; ?>

        <div style="text-align: center; margin-top: 30px;">
            <a href="deposit" class="create-link">Create New Resource Link</a>
        </div>
    </div>

    <footer>
        <p>DPTSI | &copy; <?php echo date('Y'); ?> Teknologi Pendidikan ID</p>
    </footer>

    <!-- Include QRCode.js library -->
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

    <script>
        // Get modal elements
        const modal = document.getElementById('qr-modal');
        const closeBtn = document.querySelector('.close');
        const qrContainer = document.getElementById('qr-container');
        const qrTitle = document.getElementById('qr-title');
        const qrUrlDisplay = document.querySelector('.qr-url');
        const downloadBtn = document.getElementById('download-qr');

        // QR code instance
        let qrcode = null;

        // Add event listeners to QR buttons
        document.querySelectorAll('.qr-button').forEach(button => {
            button.addEventListener('click', function() {
                const url = this.getAttribute('data-url');
                const title = this.getAttribute('data-title');

                // Clear previous QR code
                qrContainer.innerHTML = '';

                // Create new QR code
                qrcode = new QRCode(qrContainer, {
                    text: url,
                    width: 200,
                    height: 200,
                    colorDark: "#000000",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.H
                });

                // Update modal content
                qrTitle.textContent = 'QR Code for: ' + title;
                qrUrlDisplay.textContent = url;

                // Show modal
                modal.style.display = 'block';
            });
        });

        // Close modal when clicking the × button
        closeBtn.addEventListener('click', function() {
            modal.style.display = 'none';
        });

        // Close modal when clicking outside of it
        window.addEventListener('click', function(event) {
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });

        // Download QR code as image
        downloadBtn.addEventListener('click', function() {
            const canvas = qrContainer.querySelector('canvas');
            if (canvas) {
                const url = canvas.toDataURL('image/png');
                const a = document.createElement('a');
                a.href = url;
                a.download = 'qrcode.png';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            }
        });
    </script>
</body>
</html>
