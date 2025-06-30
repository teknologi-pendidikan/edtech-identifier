<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/security.php';

// Set security headers
set_security_headers();

$validation_errors = [];
$upload_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    // Check CSRF token
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        log_security_event('csrf_token_invalid', ['action' => 'bulk_upload']);
        $validation_errors[] = 'Invalid security token. Please refresh the page and try again.';
    }

    // Check rate limiting
    if (!check_rate_limit('bulk_upload', 2, 600)) { // 2 uploads per 10 minutes
        log_security_event('rate_limit_exceeded', ['action' => 'bulk_upload']);
        $validation_errors[] = 'Upload rate limit exceeded. Please wait before trying again.';
    }

    $file = $_FILES['file'];
    $validate_only = isset($_POST['validate_only']);

    if ($file['error'] === UPLOAD_ERR_OK && empty($validation_errors)) {
        // Validate file upload
        $upload_validation = validate_file_upload($file);
        if (!empty($upload_validation)) {
            $validation_errors = array_merge($validation_errors, $upload_validation);
        } else {
            // Sanitize filename and move to secure location
            $original_filename = sanitize_filename($file['name']);
            $temp_path = $file['tmp_name'];

            // Create uploads directory if it doesn't exist
            $upload_dir = __DIR__ . '/temp/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $safe_filename = uniqid('upload_') . '.csv';
            $target_path = $upload_dir . $safe_filename;

            if (move_uploaded_file($temp_path, $target_path)) {
                // Validate CSV content
                $csv_errors = validate_csv_file($target_path);

                if (!empty($csv_errors)) {
                    $validation_errors = array_merge($validation_errors, $csv_errors);
                } else if (!$validate_only) {
                    // Process the file if validation passed and not validation-only mode
                    $result = process_csv_file($target_path);
                    $successCount = $result['success'];
                    $errorCount = $result['errors'];
                    $upload_errors = $result['error_details'];
                }

                // Clean up temporary file
                unlink($target_path);
            } else {
                $validation_errors[] = 'Failed to process uploaded file.';
                log_security_event('file_upload_failed', ['filename' => $original_filename]);
            }
        }
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $validation_errors[] = get_upload_error_message($file['error']);
    }
}

// Validate file upload
function validate_file_upload($file)
{
    $errors = [];

    // Check file size (10MB limit)
    if ($file['size'] > 10 * 1024 * 1024) {
        $errors[] = 'File size exceeds 10MB limit.';
    }

    // Check file type
    $allowed_types = ['text/csv', 'application/csv', 'text/plain'];
    $file_info = finfo_open(FILEINFO_MIME_TYPE);
    $detected_type = finfo_file($file_info, $file['tmp_name']);
    finfo_close($file_info);

    if (!in_array($detected_type, $allowed_types) && !str_ends_with(strtolower($file['name']), '.csv')) {
        $errors[] = 'Invalid file type. Only CSV files are allowed.';
        log_security_event('invalid_file_type_upload', ['detected_type' => $detected_type, 'filename' => $file['name']]);
    }

    return $errors;
}

// Get upload error message
function get_upload_error_message($error_code)
{
    switch ($error_code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'File is too large.';
        case UPLOAD_ERR_PARTIAL:
            return 'File upload was interrupted.';
        case UPLOAD_ERR_NO_FILE:
            return 'No file was selected.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Server configuration error.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Failed to write file to disk.';
        default:
            return 'Unknown upload error.';
    }
}

// Process CSV file with enhanced validation
function process_csv_file($file_path)
{
    $conn = create_db_connection($GLOBALS['db_config']);
    if (!$conn) {
        return ['success' => 0, 'errors' => 1, 'error_details' => ['Database connection failed']];
    }

    $successCount = 0;
    $errorCount = 0;
    $error_details = [];

    $handle = fopen($file_path, 'r');
    if (!$handle) {
        return ['success' => 0, 'errors' => 1, 'error_details' => ['Could not read file']];
    }

    $line_number = 0;

    while (($line = fgets($handle)) !== false) {
        $line_number++;
        $line = trim($line);

        if (empty($line))
            continue;

        $data = str_getcsv($line);

        if (count($data) !== 5) {
            $errorCount++;
            $error_details[] = "Line $line_number: Expected 5 columns, got " . count($data);
            continue;
        }

        list($prefix, $suffix, $url, $title, $desc) = $data;

        // Validate and sanitize each field
        $prefix = validate_text_input($prefix, 50);
        $suffix = validate_text_input($suffix, 100);
        $title = validate_text_input($title, 255);
        $desc = validate_text_input($desc, 1000);

        // Comprehensive validation
        $validation_errors = [];

        if (!$prefix || !validate_prefix_format($prefix)) {
            $validation_errors[] = 'Invalid prefix format';
        }

        if (!$suffix || !validate_suffix_format($suffix)) {
            $validation_errors[] = 'Invalid suffix format';
        }

        if (!validate_url($url)) {
            $validation_errors[] = 'Invalid URL';
        }

        if (!$title) {
            $validation_errors[] = 'Title is required';
        }

        if (!empty($validation_errors)) {
            $errorCount++;
            $error_details[] = "Line $line_number: " . implode(', ', $validation_errors);
            continue;
        }

        // Check if prefix exists
        $prefix_check = $conn->prepare("SELECT COUNT(*) FROM prefixes WHERE prefix = ?");
        $prefix_check->bind_param("s", $prefix);
        $prefix_check->execute();
        $prefix_check->bind_result($prefix_exists);
        $prefix_check->fetch();
        $prefix_check->close();

        if (!$prefix_exists) {
            $errorCount++;
            $error_details[] = "Line $line_number: Prefix '$prefix' does not exist";
            continue;
        }

        // Check for duplicate identifier
        $duplicate_check = $conn->prepare("SELECT COUNT(*) FROM identifiers WHERE prefix = ? AND suffix = ?");
        $duplicate_check->bind_param("ss", $prefix, $suffix);
        $duplicate_check->execute();
        $duplicate_check->bind_result($duplicate_exists);
        $duplicate_check->fetch();
        $duplicate_check->close();

        if ($duplicate_exists) {
            $errorCount++;
            $error_details[] = "Line $line_number: Identifier '$prefix/$suffix' already exists";
            continue;
        }

        // Insert the record
        $stmt = $conn->prepare("INSERT INTO identifiers (prefix, suffix, target_url, title, description) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $prefix, $suffix, $url, $title, $desc);

        if ($stmt->execute()) {
            $successCount++;

            // Log the creation
            $id = $stmt->insert_id;
            $log_stmt = $conn->prepare("INSERT INTO identifier_logs (identifier_id, action, changed_by, details) VALUES (?, 'bulk_create', ?, ?)");
            $user = $_SESSION['username'] ?? 'anonymous';
            $details = json_encode(['line' => $line_number, 'prefix' => $prefix, 'suffix' => $suffix]);
            $log_stmt->bind_param("sss", $id, $user, $details);
            $log_stmt->execute();
            $log_stmt->close();
        } else {
            $errorCount++;
            $error_details[] = "Line $line_number: Database error - " . $stmt->error;
            log_security_event('database_error', ['error' => $stmt->error, 'action' => 'bulk_upload', 'line' => $line_number]);
        }

        $stmt->close();
    }

    fclose($handle);
    $conn->close();

    // Log bulk upload completion
    log_security_event('bulk_upload_completed', ['success' => $successCount, 'errors' => $errorCount]);

    return ['success' => $successCount, 'errors' => $errorCount, 'error_details' => $error_details];
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
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
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
