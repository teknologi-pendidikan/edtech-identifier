<?php
/**
 * Public Lookup Interface
 * EdTech Identifier System - Fresh & Simple Version
 */

require_once 'includes/config.php';

$result = null;
$error = '';

// Handle lookup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['identifier'])) {
    $identifier = trim($_POST['identifier']);

    // Parse identifier
    if (preg_match('#^([a-zA-Z0-9\.]+)/(.+)$#', $identifier, $matches)) {
        $prefix = $matches[1];
        $suffix = $matches[2];

        $conn = db_connect();

        // Try both long and short form lookup
        $stmt = $conn->prepare("
            SELECT i.*, nm.long_form, nm.short_form, nm.category
            FROM identifiers i
            JOIN namespace_mappings nm ON i.namespace_id = nm.id
            WHERE (nm.long_form = ? OR nm.short_form = ?) AND i.suffix = ?
        ");
        $stmt->bind_param("sss", $prefix, $prefix, $suffix);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if ($result) {
            // Update resolution count
            $stmt = $conn->prepare("UPDATE identifiers SET resolution_count = resolution_count + 1, last_resolved_at = NOW() WHERE doi = ?");
            $stmt->bind_param("s", $result['doi']);
            $stmt->execute();
        } else {
            $error = 'Identifier not found';
        }
    } else {
        $error = 'Invalid identifier format. Use: prefix/suffix';
    }
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
    <title>EdTech Identifier Lookup</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <!-- Google Tag Manager (noscript) -->
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-MFSCQ9KR"
    height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    <!-- End Google Tag Manager (noscript) -->

    <div class="header">
        <div class="container">
            <h1>EdTech Identifier Lookup</h1>
            <p class="subtitle">Persistent identifier resolution for educational technology resources</p>
        </div>
    </div>

    <div class="container">
        <!-- Lookup Form -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Identifier Lookup</h2>
            </div>

            <form method="POST">
                <div class="form-group">
                    <label class="form-label" for="identifier">Enter Identifier</label>
                    <input
                        type="text"
                        id="identifier"
                        name="identifier"
                        class="form-input"
                        placeholder="e.g., edtechid.journal/2025.0001 or ej/2025.0001"
                        value="<?= h($_POST['identifier'] ?? '') ?>"
                        required
                    >
                    <p class="text-muted text-small mt-2">
                        Supports both long form (edtechid.journal/suffix) and short form (ej/suffix)
                    </p>
                </div>

                <button type="submit" class="btn btn-primary">
                    🔍 Lookup Identifier
                </button>
            </form>
        </div>

        <!-- Error Display -->
        <?php if ($error): ?>
        <div class="alert alert-error">
            <?= h($error) ?>
        </div>
        <?php endif; ?>

        <!-- Results Display -->
        <?php if ($result): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">✅ Identifier Found</h2>
            </div>

            <div class="form-group">
                <label class="form-label">DOI</label>
                <div style="font-family: monospace; background: var(--cds-field); padding: var(--cds-spacing-04); border: 1px solid var(--cds-border-strong);">
                    <?= h($result['doi']) ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Title</label>
                <div style="font-weight: 500; color: var(--cds-text-primary);">
                    <?= h($result['title'] ?? 'No title') ?>
                </div>
            </div>

            <?php if ($result['description']): ?>
            <div class="form-group">
                <label class="form-label">Description</label>
                <div style="color: var(--cds-text-secondary);">
                    <?= h($result['description']) ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label class="form-label">Target URL</label>
                <div>
                    <a href="<?= h($result['target_url']) ?>" target="_blank" style="color: var(--cds-link-primary);">
                        <?= h($result['target_url']) ?>
                    </a>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--cds-spacing-05); margin-top: var(--cds-spacing-05);">
                <div>
                    <label class="form-label">Category</label>
                    <div><?= h($result['category']) ?></div>
                </div>
                <div>
                    <label class="form-label">Resource Type</label>
                    <div><?= h(ucwords(str_replace('_', ' ', $result['resource_type'] ?? 'other'))) ?></div>
                </div>
                <div>
                    <label class="form-label">Status</label>
                    <div style="color: var(<?= $result['status'] === 'active' ? '--cds-support-success' : '--cds-support-warning' ?>);">
                        <?= h(ucfirst($result['status'])) ?>
                    </div>
                </div>
                <div>
                    <label class="form-label">Resolved</label>
                    <div><?= number_format($result['resolution_count']) ?> times</div>
                </div>
            </div>

            <div class="mt-3">
                <a href="<?= h($result['target_url']) ?>" target="_blank" class="btn btn-primary">
                    🚀 Go to Resource
                </a>
                <button onclick="copyToClipboard('<?= h($result['doi']) ?>')" class="btn btn-secondary">
                    📋 Copy DOI
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick Examples -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Examples</h2>
            </div>
            <p class="text-muted mb-3">Try these example identifier formats:</p>
            <div style="display: grid; gap: var(--cds-spacing-03);">
                <div style="font-family: monospace; color: var(--cds-link-primary);">
                    edtechid.journal/2025.0001
                </div>
                <div style="font-family: monospace; color: var(--cds-link-primary);">
                    ej/2025.0001
                </div>
                <div style="font-family: monospace; color: var(--cds-link-primary);">
                    edtechid.dataset/research-2025
                </div>
            </div>
        </div>
    </div>

    <div style="text-align: center; margin-top: var(--cds-spacing-08); padding: var(--cds-spacing-06); color: var(--cds-text-secondary);">
        <p>&copy; 2026 EdTech Identifier System | <a href="admin/login.php" style="color: var(--cds-link-primary);">Admin Login</a></p>
    </div>

    <script>
    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(function() {
            alert('DOI copied to clipboard!');
        });
    }
    </script>
</body>
</html>
