<?php
require_once __DIR__ . '/includes/config.php';

// Initialize variables
$error = '';
$result = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');

    if (preg_match('#^([a-zA-Z0-9\.]+)/(.+)$#', $identifier, $matches)) {
        $prefix = $matches[1];
        $suffix = $matches[2];

        // If the prefix doesn't start with 'edtechid.', prepend it
        if (!str_starts_with($prefix, 'edtechid.')) {
            $prefix = 'edtechid.' . $prefix;
        }

        // Connect to database
        $conn = create_db_connection($db_config);
        if (!$conn) {
            $error = 'Database connection failed.';
        } else {
            $stmt = $conn->prepare("SELECT prefix, suffix, target_url, title, description FROM identifiers WHERE prefix = ? AND suffix = ?");
            $stmt->bind_param("ss", $prefix, $suffix);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $conn->close();

            if (!$result) {
                $error = 'Identifier not found.';
            }
        }
    } else {
        $error = 'Invalid identifier format. Please use the format: edtechid.PREFIX/SUFFIX';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EdTech Identifier Lookup</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="icon" type="image/x-icon"
        href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🔗</text></svg>">
</head>

<body>
    <header class="page-header">
        <div class="header-content">
            <h1 class="page-title">EdTech Identifier Lookup</h1>
            <p class="page-subtitle">Resolve educational technology identifiers to their target resources</p>
        </div>
    </header>

    <div class="main-container">
        <main class="content-section">
            <h2 class="section-title">Lookup Identifier</h2>
            <p class="helper-text">
                Enter an EdTech identifier to resolve it to its target resource.
                Identifiers follow the format <code>edtechid.PREFIX/SUFFIX</code>.
            </p>

            <form method="POST">
                <div class="form-group">
                    <label for="identifier" class="form-label">EdTech Identifier</label>
                    <input type="text" id="identifier" name="identifier" class="text-input"
                        placeholder="e.g., edtechid.100/a35def or simply 100/a35def"
                        value="<?php echo htmlspecialchars($_POST['identifier'] ?? ''); ?>" required autocomplete="off">
                </div>
                <button type="submit" class="btn btn-primary">Resolve Identifier</button>
            </form>

            <?php if ($error): ?>
                <div class="notification notification-error">
                    <div class="notification-icon">⚠️</div>
                    <div class="notification-content">
                        <h3 class="notification-title">Resolution Failed</h3>
                        <p class="notification-message"><?php echo htmlspecialchars($error); ?></p>
                        <?php if (strpos($error, 'not found') !== false): ?>
                            <p class="notification-message">
                                Make sure the identifier is correct and try again. You can also browse available identifiers
                                using the admin panel.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($result): ?>
                <div class="result-card">
                    <h2 class="result-title">✅ Identifier Resolved Successfully</h2>

                    <div class="result-item">
                        <span class="result-label">Full Identifier</span>
                        <div class="result-value">
                            <code><?php echo htmlspecialchars($result['prefix'] . '/' . $result['suffix']); ?></code>
                        </div>
                    </div>

                    <div class="result-item">
                        <span class="result-label">Title</span>
                        <div class="result-value">
                            <?php echo htmlspecialchars($result['title'] ?: 'Untitled Resource'); ?>
                        </div>
                    </div>

                    <div class="result-item">
                        <span class="result-label">Description</span>
                        <div class="result-value">
                            <?php echo htmlspecialchars($result['description'] ?: 'No description available for this resource.'); ?>
                        </div>
                    </div>

                    <div class="result-item">
                        <span class="result-label">Target Resource</span>
                        <div class="result-value">
                            <a href="<?php echo htmlspecialchars($result['target_url']); ?>" class="result-link"
                                target="_blank" rel="noopener noreferrer">
                                <?php echo htmlspecialchars($result['target_url']); ?> ↗
                            </a>
                        </div>
                    </div>

                    <div class="result-item">
                        <span class="result-label">Prefix Registry</span>
                        <div class="result-value">
                            <?php echo htmlspecialchars($result['prefix']); ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>

        <aside class="info-panel">
            <h2 class="section-title">About EdTech Identifiers</h2>

            <div class="info-item">
                <h3 class="info-title">What are EdTech Identifiers?</h3>
                <p class="info-description">
                    EdTech identifiers are persistent, resolvable identifiers for educational technology resources.
                    They provide a stable way to reference digital learning materials, tools, and datasets.
                </p>
            </div>

            <div class="info-item">
                <h3 class="info-title">Identifier Format</h3>
                <p class="info-description">
                    Identifiers follow the pattern:
                </p>
                <div class="example-code">edtechid.PREFIX/SUFFIX</div>
                <p class="info-description">
                    Where PREFIX identifies the namespace and SUFFIX is the local identifier within that namespace.
                </p>
            </div>

            <div class="info-item">
                <h3 class="info-title">Examples</h3>
                <div class="example-code">
                    edtechid.100/a35def<br>
                    edtechid.mit/course-6.001<br>
                    edtechid.oer/physics-101
                </div>
            </div>

            <div class="info-item">
                <h3 class="info-title">Need Help?</h3>
                <p class="info-description">
                    If you're having trouble resolving an identifier or need to register new identifiers,
                    please contact your system administrator or visit the admin panel.
                </p>
                <a href="admin/" class="result-link">Admin Panel →</a>
            </div>

            <div class="info-item">
                <h3 class="info-title">API Access</h3>
                <p class="info-description">
                    Programmatic access is available through our REST API:
                </p>
                <div class="example-code">
                    GET /api.php?id=edtechid.PREFIX/SUFFIX
                </div>
            </div>
        </aside>
    </div>

    <script>
        // Add some interactivity
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.querySelector('form');
            const submitBtn = document.querySelector('.btn-primary');
            const input = document.querySelector('#identifier');

            // Add loading state on form submission
            form.addEventListener('submit', function () {
                submitBtn.textContent = 'Resolving...';
                submitBtn.classList.add('loading');
            });

            // Auto-focus the input field
            input.focus();

            // Add keyboard shortcut (Ctrl/Cmd + Enter to submit)
            input.addEventListener('keydown', function (e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    form.submit();
                }
            });
        });
    </script>
</body>

</html>
