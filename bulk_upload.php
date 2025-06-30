<?php
require_once __DIR__ . '/includes/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];

    if ($file['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $file['tmp_name'];
        $fileContent = file_get_contents($fileTmpPath);

        $lines = explode("\n", $fileContent);
        $conn = create_db_connection($db_config);

        if (!$conn) {
            die("Database connection failed: " . mysqli_connect_error());
        }

        $successCount = 0;
        $errorCount = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line))
                continue;

            // Check if the prefix is missing and infer a default prefix
            if (!str_contains($line, '/')) {
                $errorCount++;
                echo "<p>Error: Invalid format. Missing '/' in line: '$line'.</p>";
                continue;
            }

            list($prefix, $suffix) = explode('/', $line, 2);

            if (!str_starts_with($prefix, 'edtechid.')) {
                $suffix = $prefix . '/' . $suffix;
                $prefix = 'edtechid.100'; // Default prefix
            }

            $stmtCheckPrefix = $conn->prepare("SELECT COUNT(*) FROM prefixes WHERE prefix = ?");
            $stmtCheckPrefix->bind_param("s", $prefix);
            $stmtCheckPrefix->execute();
            $stmtCheckPrefix->bind_result($prefixExists);
            $stmtCheckPrefix->fetch();
            $stmtCheckPrefix->close();

            if (!$prefixExists) {
                $errorCount++;
                echo "<p>Error: Prefix '$prefix' does not exist in the prefixes table.</p>";
                continue;
            }

            if (empty($suffix) || empty($url) || empty($title) || empty($desc)) {
                $errorCount++;
                echo "<p>Error: Missing required fields in line: '$line'.</p>";
                continue;
            }

            $stmt = $conn->prepare("INSERT INTO identifiers (prefix, suffix, target_url, title, description) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $prefix, $suffix, $url, $title, $desc);

            if (!$stmt->execute()) {
                if ($stmt->errno === 1062) { // Duplicate entry error
                    echo "<p>Error: Duplicate entry for prefix '$prefix' and suffix '$suffix'.</p>";
                } else {
                    echo "<p>Error inserting record: " . htmlspecialchars($stmt->error) . "</p>";
                }
                $errorCount++;
            } else {
                $successCount++;
            }

            $stmt->close();
        }

        $conn->close();

        echo "<p>Upload complete. Success: $successCount, Errors: $errorCount</p>";
    } else {
        echo "<p>Error uploading file. Please try again.</p>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Upload Identifiers - EdTech Identifier System</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="icon" type="image/x-icon"
        href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📁</text></svg>">
</head>

<body>
    <header class="page-header">
        <div class="header-content">
            <h1 class="page-title">Bulk Upload Identifiers</h1>
            <p class="page-subtitle">Upload multiple EdTech identifiers at once using a CSV file</p>
        </div>
    </header>

    <div class="main-container">
        <main class="content-section">
            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])): ?>
                <?php if ($errorCount > 0 || $successCount > 0): ?>
                    <div class="notification <?php echo $successCount > 0 ? 'notification-success' : 'notification-error'; ?>">
                        <div class="notification-icon"><?php echo $successCount > 0 ? '✅' : '⚠️'; ?></div>
                        <div class="notification-content">
                            <h3 class="notification-title">Upload Complete</h3>
                            <p class="notification-message">
                                Successfully processed: <strong><?php echo $successCount; ?></strong> identifiers
                                <?php if ($errorCount > 0): ?>
                                    | Errors: <strong><?php echo $errorCount; ?></strong> identifiers
                                <?php endif; ?>
                            </p>
                            <?php if ($errorCount > 0): ?>
                                <p class="notification-message">Please review the errors above and fix any issues in your CSV file.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <h2 class="section-title">Upload CSV File</h2>
            <p class="helper-text">
                Select a properly formatted CSV file containing your EdTech identifiers. The system will validate each
                entry and provide detailed feedback.
            </p>

            <form method="POST" enctype="multipart/form-data" id="upload-form">
                <div class="form-group">
                    <label for="file" class="form-label">CSV File *</label>
                    <input type="file" name="file" id="file" class="text-input" accept=".csv,.txt" required
                        style="padding: var(--cds-spacing-04);">
                    <div class="helper-text">
                        Upload a CSV file with your identifier data. Maximum file size: 10MB.
                        Supported formats: .csv, .txt
                    </div>
                </div>

                <div class="form-group">
                    <div style="display: flex; align-items: center; margin-bottom: var(--cds-spacing-04);">
                        <input type="checkbox" name="validate_only" id="validate_only"
                            style="width: auto; margin-right: var(--cds-spacing-03);">
                        <label for="validate_only" class="form-label" style="margin-bottom: 0; font-weight: 400;">
                            Validate only (don't save to database)
                        </label>
                    </div>
                    <div class="helper-text">Check this option to test your CSV file format without actually creating
                        the identifiers.</div>
                </div>

                <button type="submit" class="btn btn-primary" id="upload-btn">
                    <span id="btn-text">Upload Identifiers</span>
                </button>
            </form>

            <div class="result-card" style="margin-top: var(--cds-spacing-08);">
                <h2 class="result-title">📋 Required CSV Format</h2>

                <div class="result-item">
                    <span class="result-label">Column Order (Required)</span>
                    <div class="result-value">
                        The CSV file must have exactly 5 columns in this order:
                    </div>
                </div>

                <div style="margin: var(--cds-spacing-06) 0;">
                    <div
                        style="display: grid; grid-template-columns: 1fr 2fr; gap: var(--cds-spacing-04); margin-bottom: var(--cds-spacing-04);">
                        <strong style="color: var(--cds-text-secondary);">Column 1:</strong>
                        <span><strong>Prefix</strong> - Category prefix (e.g., "edtechid.100")</span>
                    </div>
                    <div
                        style="display: grid; grid-template-columns: 1fr 2fr; gap: var(--cds-spacing-04); margin-bottom: var(--cds-spacing-04);">
                        <strong style="color: var(--cds-text-secondary);">Column 2:</strong>
                        <span><strong>Suffix</strong> - Unique identifier within the prefix</span>
                    </div>
                    <div
                        style="display: grid; grid-template-columns: 1fr 2fr; gap: var(--cds-spacing-04); margin-bottom: var(--cds-spacing-04);">
                        <strong style="color: var(--cds-text-secondary);">Column 3:</strong>
                        <span><strong>Target URL</strong> - The destination URL</span>
                    </div>
                    <div
                        style="display: grid; grid-template-columns: 1fr 2fr; gap: var(--cds-spacing-04); margin-bottom: var(--cds-spacing-04);">
                        <strong style="color: var(--cds-text-secondary);">Column 4:</strong>
                        <span><strong>Title</strong> - Human-readable resource title</span>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: var(--cds-spacing-04);">
                        <strong style="color: var(--cds-text-secondary);">Column 5:</strong>
                        <span><strong>Description</strong> - Brief resource description</span>
                    </div>
                </div>

                <div class="result-item">
                    <span class="result-label">Example CSV Content</span>
                    <div class="example-code">
                        edtechid.100,course-intro-cs,https://university.edu/cs101,Introduction to Computer
                        Science,Beginner programming course
                        edtechid.oer,physics-mechanics,https://openbooks.edu/physics/mechanics,Classical Mechanics
                        Textbook,Open educational resource for physics
                        edtechid.mit,6.001-sicp,https://web.mit.edu/6.001/,Structure and Interpretation of Computer
                        Programs,Classic MIT programming course
                    </div>
                </div>
            </div>
        </main>

        <aside class="info-panel">
            <h2 class="section-title">Upload Guidelines</h2>

            <div class="info-item">
                <h3 class="info-title">File Requirements</h3>
                <ul
                    style="color: var(--cds-text-secondary); font-size: 0.875rem; margin: var(--cds-spacing-04) 0; padding-left: var(--cds-spacing-06);">
                    <li>CSV format with comma separators</li>
                    <li>UTF-8 encoding recommended</li>
                    <li>No header row required</li>
                    <li>Maximum 1000 rows per upload</li>
                    <li>File size limit: 10MB</li>
                </ul>
            </div>

            <div class="info-item">
                <h3 class="info-title">Data Validation</h3>
                <p class="info-description">
                    The system will automatically validate:
                </p>
                <ul
                    style="color: var(--cds-text-secondary); font-size: 0.875rem; margin: var(--cds-spacing-04) 0; padding-left: var(--cds-spacing-06);">
                    <li>Prefix exists in the system</li>
                    <li>Suffix uniqueness within prefix</li>
                    <li>Valid URL format</li>
                    <li>Required fields are not empty</li>
                    <li>Character encoding issues</li>
                </ul>
            </div>

            <div class="info-item">
                <h3 class="info-title">Error Handling</h3>
                <p class="info-description">
                    If errors occur during upload:
                </p>
                <ul
                    style="color: var(--cds-text-secondary); font-size: 0.875rem; margin: var(--cds-spacing-04) 0; padding-left: var(--cds-spacing-06);">
                    <li>Valid rows are processed successfully</li>
                    <li>Invalid rows are skipped with detailed error messages</li>
                    <li>Duplicate identifiers are reported</li>
                    <li>You can fix errors and re-upload</li>
                </ul>
            </div>

            <div class="info-item">
                <h3 class="info-title">Best Practices</h3>
                <ul
                    style="color: var(--cds-text-secondary); font-size: 0.875rem; margin: var(--cds-spacing-04) 0; padding-left: var(--cds-spacing-06);">
                    <li>Test with validation-only mode first</li>
                    <li>Use descriptive titles and descriptions</li>
                    <li>Verify URLs are accessible</li>
                    <li>Keep backup of your CSV file</li>
                    <li>Upload in smaller batches for large datasets</li>
                </ul>
            </div>

            <div class="info-item">
                <h3 class="info-title">Need Help?</h3>
                <p class="info-description">
                    Additional tools and resources:
                </p>
                <div style="margin-top: var(--cds-spacing-04);">
                    <a href="index.php" class="result-link">← Back to Lookup</a><br>
                    <a href="deposit.php" class="result-link">Create Single Identifier</a><br>
                    <a href="list.php" class="result-link">Browse Existing Identifiers</a><br>
                    <a href="admin/" class="result-link">Admin Panel</a>
                </div>
            </div>

            <div class="info-item">
                <h3 class="info-title">Download Template</h3>
                <p class="info-description">
                    Download a sample CSV template to get started:
                </p>
                <div style="margin-top: var(--cds-spacing-04);">
                    <button type="button" class="btn btn-secondary" onclick="downloadTemplate()">
                        Download CSV Template
                    </button>
                </div>
            </div>
        </aside>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('upload-form');
            const uploadBtn = document.getElementById('upload-btn');
            const btnText = document.getElementById('btn-text');
            const fileInput = document.getElementById('file');
            const validateOnly = document.getElementById('validate_only');

            // Update button text based on validation mode
            validateOnly.addEventListener('change', function () {
                if (this.checked) {
                    btnText.textContent = 'Validate CSV File';
                } else {
                    btnText.textContent = 'Upload Identifiers';
                }
            });

            // File input validation
            fileInput.addEventListener('change', function () {
                const file = this.files[0];
                if (file) {
                    // Check file size (10MB limit)
                    if (file.size > 10 * 1024 * 1024) {
                        alert('File size exceeds 10MB limit. Please choose a smaller file.');
                        this.value = '';
                        return;
                    }

                    // Check file type
                    const validTypes = ['text/csv', 'application/csv', 'text/plain'];
                    if (!validTypes.includes(file.type) && !file.name.toLowerCase().endsWith('.csv')) {
                        alert('Please select a valid CSV file.');
                        this.value = '';
                        return;
                    }
                }
            });

            // Form submission handling
            form.addEventListener('submit', function () {
                const isValidation = validateOnly.checked;
                btnText.textContent = isValidation ? 'Validating...' : 'Uploading...';
                uploadBtn.classList.add('loading');
                uploadBtn.disabled = true;
            });
        });

        // Download CSV template
        function downloadTemplate() {
            const csvContent = `edtechid.100,example-course,https://example.edu/course,Example Course Title,This is an example course description
edtechid.oer,sample-resource,https://example.org/resource,Sample Educational Resource,Open educational resource example
edtechid.mit,demo-material,https://example.mit.edu/demo,Demo Learning Material,Sample learning material from MIT`;

            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'edtech-identifiers-template.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>
</body>

</html>
