<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/security.php';

// Set security headers
set_security_headers();

// Add tracking variables
$submission_success = false;
$created_prefix = '';
$created_suffix = '';
$turnstile_error = false;
$validation_errors = [];

$conn = create_db_connection($db_config);
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Function to generate a random suffix
function generate_random_suffix()
{
    return bin2hex(random_bytes(3)); // 6-character random string
}

// Function to check if a suffix exists
function is_suffix_unique($prefix, $suffix, $conn)
{
    $stmt = $conn->prepare("SELECT 1 FROM identifiers WHERE prefix = ? AND suffix = ?");
    $stmt->bind_param("ss", $prefix, $suffix);
    $stmt->execute();
    $stmt->store_result();
    $is_unique = $stmt->num_rows === 0;
    $stmt->close();
    return $is_unique;
}

// Function to verify Turnstile token
function verify_turnstile($token)
{
    $secret_key = '0x4AAAAAABLf2mLvGLOleK92Qt8TUExvAAA'; // Replace with your actual secret key

    $data = [
        'secret' => $secret_key,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR']
    ];

    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    $result = curl_exec($ch);
    curl_close($ch);

    $result_json = json_decode($result, true);
    return isset($result_json['success']) && $result_json['success'] === true;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Check CSRF token
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        log_security_event('csrf_token_invalid', ['action' => 'deposit']);
        $validation_errors[] = 'Invalid security token. Please refresh the page and try again.';
    }

    // Check rate limiting
    if (!check_rate_limit('deposit', 3, 300)) { // 3 submissions per 5 minutes
        log_security_event('rate_limit_exceeded', ['action' => 'deposit']);
        $validation_errors[] = 'Too many submissions. Please wait before trying again.';
    }

    // Verify Turnstile token
    $token = $_POST['cf-turnstile-response'] ?? '';
    if (!verify_turnstile($token)) {
        $turnstile_error = true;
        $validation_errors[] = 'Human verification failed. Please complete the security check.';
    }

    // If no validation errors so far, process the form
    if (empty($validation_errors)) {
        // Validate and sanitize inputs
        $prefix = validate_text_input($_POST['prefix'] ?? '', 50);
        $auto_generate = $_POST['auto_generate'] ?? 'off';
        $suffix = $auto_generate === "on" ? generate_random_suffix() : validate_text_input($_POST['suffix'] ?? '', 100);
        $url = $_POST['target_url'] ?? '';
        $title = validate_text_input($_POST['title'] ?? '', 255);
        $description = validate_text_input($_POST['description'] ?? '', 1000);

        // Validate prefix format
        if (!$prefix || !validate_prefix_format($prefix)) {
            $validation_errors[] = 'Invalid prefix format.';
        }

        // Validate suffix format (if not auto-generated)
        if ($auto_generate !== "on" && (!$suffix || !validate_suffix_format($suffix))) {
            $validation_errors[] = 'Invalid suffix format. Only letters, numbers, hyphens, and underscores are allowed.';
        }

        // Validate URL
        if (!validate_url($url)) {
            $validation_errors[] = 'Invalid URL format or unsafe URL detected.';
        }

        // Validate title
        if (!$title) {
            $validation_errors[] = 'Title is required.';
        }

        // Check if manually entered suffix is unique
        if ($auto_generate !== "on" && !is_suffix_unique($prefix, $suffix, $conn)) {
            $validation_errors[] = 'This identifier already exists. Please choose a different suffix or use auto-generation.';
        }

        // If validation passes, insert the record
        if (empty($validation_errors)) {
            $stmt = $conn->prepare("INSERT INTO identifiers (prefix, suffix, target_url, title, description) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $prefix, $suffix, $url, $title, $description);

            if ($stmt->execute()) {
                // Set success variables
                $submission_success = true;
                $created_prefix = $prefix;
                $created_suffix = $suffix;

                // Log the creation of a new identifier
                $id = $stmt->insert_id;
                $log_stmt = $conn->prepare("INSERT INTO identifier_logs (identifier_id, action, changed_by, details) VALUES (?, 'create', ?, ?)");
                $user = $_SESSION['username'] ?? 'anonymous';
                $details = json_encode(['url' => $url, 'title' => $title]);
                $log_stmt->bind_param("sss", $id, $user, $details);
                $log_stmt->execute();
                $log_stmt->close();

                log_security_event('identifier_created', ['prefix' => $prefix, 'suffix' => $suffix]);
            } else {
                $validation_errors[] = 'Database error occurred. Please try again.';
                log_security_event('database_error', ['error' => $conn->error, 'action' => 'deposit']);
            }

            $stmt->close();
        }
    }
}

// Get record count for each prefix to show stats
function get_prefix_counts($conn)
{
    $counts = [];
    $result = $conn->query("SELECT prefix, COUNT(*) as count FROM identifiers GROUP BY prefix");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $counts[$row['prefix']] = $row['count'];
        }
    }
    return $counts;
}

// Get available prefixes from the database
function get_prefixes($conn)
{
    $prefixes = [];
    $result = $conn->query("SELECT prefix, name, description FROM prefixes WHERE is_active = TRUE ORDER BY prefix");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $prefixes[] = $row;
        }
    }
    return $prefixes;
}

$prefix_counts = get_prefix_counts($conn);
$prefixes = get_prefixes($conn);
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create EdTech Identifier - EdTech Identifier System</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="icon" type="image/x-icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🔗</text></svg>">
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
</head>

<body>
    <header class="page-header">
        <div class="header-content">
            <h1 class="page-title">Create EdTech Identifier</h1>
            <p class="page-subtitle">Register a new persistent identifier for your educational resource</p>
        </div>
    </header>

    <div class="main-container">
        <main class="content-section">
            <?php if ($submission_success): ?>
                <div class="notification notification-success">
                    <div class="notification-icon">✅</div>
                    <div class="notification-content">
                        <h3 class="notification-title">Identifier Created Successfully</h3>
                        <p class="notification-message">
                            Your EdTech identifier has been registered:
                            <strong><?php echo htmlspecialchars($created_prefix) . '/' . htmlspecialchars($created_suffix); ?></strong>
                        </p>
                        <p class="notification-message">
                            <a href="https://<?php echo $_SERVER['HTTP_HOST']; ?>/<?php echo htmlspecialchars($created_prefix) . '/' . htmlspecialchars($created_suffix); ?>"
                               class="result-link" target="_blank" rel="noopener noreferrer">
                                Test your identifier ↗
                            </a>
                        </p>
                    </div>
                </div>
            <?php elseif ($turnstile_error): ?>
                <div class="notification notification-error">
                    <div class="notification-icon">⚠️</div>
                    <div class="notification-content">
                        <h3 class="notification-title">Verification Failed</h3>
                        <p class="notification-message">Human verification failed. Please complete the security check and try again.</p>
                    </div>
                </div>
            <?php elseif ($_SERVER["REQUEST_METHOD"] === "POST" && isset($suffix) && $_POST['auto_generate'] !== "on" && !is_suffix_unique($prefix, $suffix, $conn)): ?>
                <div class="notification notification-error">
                    <div class="notification-icon">⚠️</div>
                    <div class="notification-content">
                        <h3 class="notification-title">Identifier Already Exists</h3>
                        <p class="notification-message">This identifier already exists. Please choose a different suffix or use auto-generation.</p>
                    </div>
                </div>
            <?php elseif (!empty($validation_errors)): ?>
                <div class="notification notification-error">
                    <div class="notification-icon">⚠️</div>
                    <div class="notification-content">
                        <h3 class="notification-title">Validation Errors</h3>
                        <ul class="notification-errors">
                            <?php foreach ($validation_errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <h2 class="section-title">Resource Information</h2>
            <p class="helper-text">
                Fill out the form below to create a new EdTech identifier. Auto-generation is recommended for unique, collision-free identifiers.
            </p>

            <form method="POST" id="deposit-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                <div class="form-group">
                    <label for="prefix" class="form-label">Category Prefix</label>
                    <select name="prefix" id="prefix" class="text-input" required>
                        <option value="">Select a category prefix</option>
                        <?php foreach ($prefixes as $prefix_option): ?>
                            <option value="<?php echo htmlspecialchars($prefix_option['prefix']); ?>"
                                    <?php echo (isset($_POST['prefix']) && $_POST['prefix'] === $prefix_option['prefix']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($prefix_option['name']); ?>
                                <?php echo isset($prefix_counts[$prefix_option['prefix']]) ? " ({$prefix_counts[$prefix_option['prefix']]} identifiers)" : " (0 identifiers)"; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="helper-text">Choose the category that best describes your resource type.</div>
                </div>

                <div class="form-group">
                    <div style="display: flex; align-items: center; margin-bottom: var(--cds-spacing-04);">
                        <input type="checkbox" name="auto_generate" id="auto_generate"
                               style="width: auto; margin-right: var(--cds-spacing-03);" checked>
                        <label for="auto_generate" class="form-label" style="margin-bottom: 0; font-weight: 400;">
                            Auto-generate identifier suffix (recommended)
                        </label>
                    </div>
                    <div class="helper-text">Automatically generates a unique, collision-free suffix for your identifier.</div>
                </div>

                <div class="form-group" id="suffix-group">
                    <label for="suffix" class="form-label">Custom Suffix (optional)</label>
                    <input type="text"
                           name="suffix"
                           id="suffix"
                           class="text-input"
                           placeholder="e.g., my-resource-2025"
                           pattern="[a-zA-Z0-9\-_]+"
                           title="Only letters, numbers, hyphens and underscores allowed"
                           value="<?php echo htmlspecialchars($_POST['suffix'] ?? ''); ?>"
                           disabled>
                    <div class="helper-text">Only letters, numbers, hyphens, and underscores are allowed. Leave empty to auto-generate.</div>
                </div>

                <div class="form-group">
                    <label for="target_url" class="form-label">Target URL *</label>
                    <input type="url"
                           name="target_url"
                           id="target_url"
                           class="text-input"
                           placeholder="https://example.com/your-resource"
                           value="<?php echo htmlspecialchars($_POST['target_url'] ?? ''); ?>"
                           required>
                    <div class="helper-text">The URL where your identifier should redirect. Must be a valid, accessible URL.</div>
                </div>

                <div class="form-group">
                    <label for="title" class="form-label">Resource Title</label>
                    <input type="text"
                           name="title"
                           id="title"
                           class="text-input"
                           placeholder="Enter a descriptive title for your resource"
                           value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>">
                    <div class="helper-text">A human-readable title that describes your educational resource.</div>
                </div>

                <div class="form-group">
                    <label for="description" class="form-label">Description (optional)</label>
                    <textarea name="description"
                              id="description"
                              class="text-input"
                              style="height: 96px; resize: vertical;"
                              placeholder="Provide a brief description of your resource"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    <div class="helper-text">Additional context about your resource to help users understand its purpose.</div>
                </div>

                <div class="result-card" id="preview-card" style="display: none;">
                    <h2 class="result-title">Preview</h2>
                    <div class="result-item">
                        <span class="result-label">Generated Identifier</span>
                        <div class="result-value">
                            <code id="preview-identifier"><?php echo $_SERVER['HTTP_HOST']; ?>/category/suffix</code>
                        </div>
                    </div>
                    <div class="result-item">
                        <span class="result-label">Title</span>
                        <div class="result-value" id="preview-title">Resource title will appear here</div>
                    </div>
                    <div class="result-item">
                        <span class="result-label">Target URL</span>
                        <div class="result-value" id="preview-url">Target URL will appear here</div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Security Verification</label>
                    <div class="cf-turnstile" data-sitekey="0x4AAAAAABLf2o4GsEZo4y3b" data-theme="light"></div>
                    <div class="helper-text">Complete the security verification to prevent automated submissions.</div>
                </div>

                <button type="submit" class="btn btn-primary" id="submit-btn">Create Identifier</button>
            </form>
        </main>

        <aside class="info-panel">
            <h2 class="section-title">Registration Guide</h2>

            <div class="info-item">
                <h3 class="info-title">Available Prefixes</h3>
                <p class="info-description">
                    Choose from the following categories for your identifier:
                </p>
                <?php foreach ($prefixes as $prefix_info): ?>
                    <div style="margin-bottom: var(--cds-spacing-04);">
                        <strong><?php echo htmlspecialchars($prefix_info['name']); ?></strong>
                        <br>
                        <span style="color: var(--cds-text-secondary); font-size: 0.875rem;">
                            <?php echo htmlspecialchars($prefix_info['description'] ?: 'No description available'); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="info-item">
                <h3 class="info-title">Auto-Generation Benefits</h3>
                <p class="info-description">
                    Using auto-generated suffixes ensures:
                </p>
                <ul style="color: var(--cds-text-secondary); font-size: 0.875rem; margin: var(--cds-spacing-04) 0; padding-left: var(--cds-spacing-06);">
                    <li>Unique identifiers with no collisions</li>
                    <li>URL-safe character combinations</li>
                    <li>Consistent format across the system</li>
                    <li>Reduced registration time</li>
                </ul>
            </div>

            <div class="info-item">
                <h3 class="info-title">Custom Suffix Guidelines</h3>
                <p class="info-description">
                    If you choose a custom suffix:
                </p>
                <ul style="color: var(--cds-text-secondary); font-size: 0.875rem; margin: var(--cds-spacing-04) 0; padding-left: var(--cds-spacing-06);">
                    <li>Use only letters, numbers, hyphens, and underscores</li>
                    <li>Keep it concise and meaningful</li>
                    <li>Avoid special characters or spaces</li>
                    <li>Consider future maintainability</li>
                </ul>
            </div>

            <div class="info-item">
                <h3 class="info-title">Need Help?</h3>
                <p class="info-description">
                    If you need assistance with registration or have questions about identifier management:
                </p>
                <div style="margin-top: var(--cds-spacing-04);">
                    <a href="index.php" class="result-link">← Back to Lookup</a><br>
                    <a href="list.php" class="result-link">Browse Existing Identifiers</a><br>
                    <a href="admin/" class="result-link">Admin Panel</a>
                </div>
            </div>
        </aside>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('deposit-form');
            const submitBtn = document.getElementById('submit-btn');
            const autoGenerate = document.getElementById('auto_generate');
            const suffixField = document.getElementById('suffix');
            const suffixGroup = document.getElementById('suffix-group');
            const previewCard = document.getElementById('preview-card');
            const previewIdentifier = document.getElementById('preview-identifier');
            const previewTitle = document.getElementById('preview-title');
            const previewUrl = document.getElementById('preview-url');

            // Handle auto-generate toggle
            autoGenerate.addEventListener('change', function() {
                suffixField.disabled = this.checked;
                suffixGroup.style.opacity = this.checked ? '0.6' : '1';

                if (this.checked) {
                    suffixField.placeholder = 'Auto-generated suffix will be used';
                } else {
                    suffixField.placeholder = 'e.g., my-resource-2025';
                    suffixField.focus();
                }
                updatePreview();
            });

            // Live preview functionality
            function updatePreview() {
                const prefix = document.getElementById('prefix').value;
                const suffix = autoGenerate.checked ? 'auto-generated' : (suffixField.value || 'custom-suffix');
                const title = document.getElementById('title').value || 'Resource title will appear here';
                const url = document.getElementById('target_url').value || 'Target URL will appear here';

                if (prefix) {
                    previewIdentifier.textContent = `<?php echo $_SERVER['HTTP_HOST']; ?>/${prefix}/${suffix}`;
                    previewTitle.textContent = title;
                    previewUrl.textContent = url;
                    previewCard.style.display = 'block';
                } else {
                    previewCard.style.display = 'none';
                }
            }

            // Add event listeners for live preview
            ['prefix', 'suffix', 'title', 'target_url'].forEach(id => {
                const element = document.getElementById(id);
                if (element) {
                    element.addEventListener('input', updatePreview);
                    element.addEventListener('change', updatePreview);
                }
            });

            // Form submission handling
            form.addEventListener('submit', function() {
                submitBtn.textContent = 'Creating Identifier...';
                submitBtn.classList.add('loading');
            });

            // Initialize state
            autoGenerate.dispatchEvent(new Event('change'));
            updatePreview();

            // Auto-focus first empty required field
            const firstEmptyField = form.querySelector('select[required]:not([value]), input[required]:not([value])');
            if (firstEmptyField) {
                firstEmptyField.focus();
            }
        });
    </script>
</body>

</html>
