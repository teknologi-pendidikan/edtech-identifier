<?php
/**
 * Bulk Upload Feature
 * EdTech Identifier System - Fresh & Simple Version
 */

require_once '../includes/config.php';
require_once '../includes/auth.php';

// Require login
require_login();

$conn = db_connect();
$success = '';
$error = '';
$upload_results = [];

// Handle CSV upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];

    if ($file['error'] === UPLOAD_ERR_OK && $file['type'] === 'text/csv') {
        $handle = fopen($file['tmp_name'], 'r');

        if ($handle !== false) {
            $header = fgetcsv($handle); // Read header row
            $expected_headers = ['namespace', 'suffix', 'title', 'description', 'target_url', 'resource_type'];

            if ($header && array_diff($expected_headers, $header) === []) {
                $row = 1;
                $success_count = 0;
                $error_count = 0;

                while (($data = fgetcsv($handle)) !== false) {
                    $row++;

                    if (count($data) < 6) {
                        $upload_results[] = ['row' => $row, 'status' => 'error', 'message' => 'Missing columns'];
                        $error_count++;
                        continue;
                    }

                    $namespace = trim($data[0]);
                    $suffix = trim($data[1]);
                    $title = trim($data[2]);
                    $description = trim($data[3]);
                    $target_url = trim($data[4]);
                    $resource_type = trim($data[5]) ?: 'other';

                    // Validate required fields
                    if (empty($namespace) || empty($suffix) || empty($target_url)) {
                        $upload_results[] = ['row' => $row, 'status' => 'error', 'message' => 'Missing required fields'];
                        $error_count++;
                        continue;
                    }

                    // Find namespace ID
                    $stmt = $conn->prepare("SELECT id, long_form FROM namespace_mappings WHERE long_form = ? OR short_form = ?");
                    $stmt->bind_param("ss", $namespace, $namespace);
                    $stmt->execute();
                    $ns_result = $stmt->get_result()->fetch_assoc();

                    if (!$ns_result) {
                        $upload_results[] = ['row' => $row, 'status' => 'error', 'message' => "Namespace '$namespace' not found"];
                        $error_count++;
                        continue;
                    }

                    $namespace_id = $ns_result['id'];
                    $doi = $ns_result['long_form'] . '/' . $suffix;

                    // Check for duplicate
                    $stmt = $conn->prepare("SELECT id FROM identifiers WHERE doi = ?");
                    $stmt->bind_param("s", $doi);
                    $stmt->execute();

                    if ($stmt->get_result()->num_rows > 0) {
                        $upload_results[] = ['row' => $row, 'status' => 'error', 'message' => "Identifier '$doi' already exists"];
                        $error_count++;
                        continue;
                    }

                    // Validate URL
                    if (!filter_var($target_url, FILTER_VALIDATE_URL)) {
                        $upload_results[] = ['row' => $row, 'status' => 'error', 'message' => "Invalid URL format"];
                        $error_count++;
                        continue;
                    }

                    // Insert identifier
                    $stmt = $conn->prepare("
                        INSERT INTO identifiers (doi, namespace_id, suffix, target_url, title, description, resource_type, status, registered_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())
                    ");
                    $stmt->bind_param("sissss", $doi, $namespace_id, $suffix, $target_url, $title, $description, $resource_type);

                    if ($stmt->execute()) {
                        $upload_results[] = ['row' => $row, 'status' => 'success', 'message' => "Created: $doi"];
                        $success_count++;
                    } else {
                        $upload_results[] = ['row' => $row, 'status' => 'error', 'message' => "Database error: " . $conn->error];
                        $error_count++;
                    }
                }

                fclose($handle);

                if ($success_count > 0) {
                    $success = "Successfully created $success_count identifiers" . ($error_count > 0 ? " ($error_count errors)" : "");
                } else {
                    $error = "No identifiers were created" . ($error_count > 0 ? " ($error_count errors)" : "");
                }
            } else {
                $error = 'Invalid CSV header. Expected: ' . implode(', ', $expected_headers);
            }
        } else {
            $error = 'Could not read CSV file';
        }
    } else {
        $error = 'Please upload a valid CSV file';
    }
}

// Get namespaces for reference
$namespaces = $conn->query("SELECT * FROM namespace_mappings WHERE is_active = 1 ORDER BY category")->fetch_all(MYSQLI_ASSOC);
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
    <title>Bulk Upload - EdTech Identifier</title>
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
                    <h1>📤 Bulk Upload</h1>
                    <p class="subtitle">Upload multiple identifiers from CSV file</p>
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
            <a href="identifiers.php" class="nav-link">🔗 Identifiers</a>
            <a href="bulk.php" class="nav-link active">📤 Bulk Upload</a>
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

        <div style="display: grid; grid-template-columns: 1fr 350px; gap: var(--cds-spacing-06);">
            <!-- Upload Form -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">📂 Upload CSV File</h2>
                </div>

                <?php if (empty($namespaces)): ?>
                <div class="alert alert-warning">
                    No active namespaces found. <a href="prefixes.php?action=add" style="color: var(--cds-link-primary);">Add a namespace</a> first.
                </div>
                <?php else: ?>
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label class="form-label" for="csv_file">CSV File *</label>
                        <input
                            type="file"
                            id="csv_file"
                            name="csv_file"
                            class="form-input"
                            accept=".csv"
                            required
                        >
                        <p class="text-muted text-small mt-2">
                            Select a CSV file with the required column headers
                        </p>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        📤 Upload and Process
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <!-- Instructions -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">📋 CSV Format</h2>
                </div>

                <div class="text-small">
                    <p class="mb-3">Your CSV file must include these exact column headers:</p>

                    <div style="background: var(--cds-layer-accent); padding: var(--cds-spacing-04); border-radius: 4px; margin-bottom: var(--cds-spacing-04);">
                        <div style="font-family: monospace; font-size: 0.75rem;">
                            namespace,suffix,title,description,target_url,resource_type
                        </div>
                    </div>

                    <div style="margin-bottom: var(--cds-spacing-04);">
                        <strong>Required columns:</strong>
                        <ul style="margin: var(--cds-spacing-03) 0; padding-left: var(--cds-spacing-05);">
                            <li><strong>namespace:</strong> Long or short form</li>
                            <li><strong>suffix:</strong> Unique suffix</li>
                            <li><strong>target_url:</strong> Valid URL</li>
                        </ul>
                    </div>

                    <div>
                        <strong>Optional columns:</strong>
                        <ul style="margin: var(--cds-spacing-03) 0; padding-left: var(--cds-spacing-05);">
                            <li><strong>title:</strong> Resource title</li>
                            <li><strong>description:</strong> Description</li>
                            <li><strong>resource_type:</strong> See types below</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reference Tables -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--cds-spacing-06); margin-top: var(--cds-spacing-06);">
            <!-- Available Namespaces -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">📁 Available Namespaces</h2>
                </div>

                <?php if (empty($namespaces)): ?>
                <div class="text-muted text-center" style="padding: var(--cds-spacing-06);">
                    No active namespaces found
                </div>
                <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Long Form</th>
                            <th>Short Form</th>
                            <th>Category</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($namespaces as $ns): ?>
                        <tr>
                            <td style="font-family: monospace; font-size: 0.75rem;">
                                <?= h($ns['long_form']) ?>
                            </td>
                            <td>
                                <span style="font-family: monospace; background: var(--cds-layer-accent); padding: 2px 6px; border-radius: 3px;">
                                    <?= h($ns['short_form']) ?>
                                </span>
                            </td>
                            <td class="text-small">
                                <?= h($ns['category']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Resource Types -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">🏷️ Resource Types</h2>
                </div>

                <table class="table">
                    <thead>
                        <tr>
                            <th>Value</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="font-family: monospace; font-size: 0.75rem;">journal_article</td>
                            <td class="text-small">Academic papers and research</td>
                        </tr>
                        <tr>
                            <td style="font-family: monospace; font-size: 0.75rem;">dataset</td>
                            <td class="text-small">Research data and datasets</td>
                        </tr>
                        <tr>
                            <td style="font-family: monospace; font-size: 0.75rem;">course_module</td>
                            <td class="text-small">Educational courses</td>
                        </tr>
                        <tr>
                            <td style="font-family: monospace; font-size: 0.75rem;">educational_material</td>
                            <td class="text-small">Learning resources</td>
                        </tr>
                        <tr>
                            <td style="font-family: monospace; font-size: 0.75rem;">person</td>
                            <td class="text-small">Author/contributor profiles</td>
                        </tr>
                        <tr>
                            <td style="font-family: monospace; font-size: 0.75rem;">other</td>
                            <td class="text-small">Default type (if empty)</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Example CSV -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📄 Example CSV Content</h2>
            </div>

            <div style="background: var(--cds-layer-accent); padding: var(--cds-spacing-05); border-radius: 4px; overflow-x: auto;">
                <pre style="font-family: monospace; font-size: 0.75rem; margin: 0; color: var(--cds-text-primary);">namespace,suffix,title,description,target_url,resource_type
edtechid.journal,2025.0001,AI in Education,Research paper on AI applications,https://example.com/paper1,journal_article
ej,2025.0002,Machine Learning Study,ML research findings,https://example.com/paper2,journal_article
edtechid.dataset,research-2025,Student Performance Data,Dataset for research,https://example.com/dataset1,dataset
ed,learning-2025,Assessment Results,Learning outcomes data,https://example.com/dataset2,dataset</pre>
            </div>

            <div style="margin-top: var(--cds-spacing-04);">
                <button onclick="downloadExample()" class="btn btn-secondary btn-small">
                    💾 Download Example CSV
                </button>
            </div>
        </div>

        <!-- Upload Results -->
        <?php if (!empty($upload_results)): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📋 Upload Results</h2>
            </div>

            <table class="table">
                <thead>
                    <tr>
                        <th>Row</th>
                        <th>Status</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($upload_results as $result): ?>
                    <tr>
                        <td><?= $result['row'] ?></td>
                        <td>
                            <span style="color: var(--cds-support-<?= $result['status'] === 'success' ? 'success' : 'error' ?>);">
                                <?= $result['status'] === 'success' ? '✅' : '❌' ?> <?= ucfirst($result['status']) ?>
                            </span>
                        </td>
                        <td class="text-small">
                            <?= h($result['message']) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <script>
    function downloadExample() {
        const csvContent = "namespace,suffix,title,description,target_url,resource_type\n" +
                          "edtechid.journal,2025.0001,AI in Education Research,Research paper on AI applications in education,https://example.com/paper1,journal_article\n" +
                          "ej,2025.0002,Machine Learning Study,ML research findings for educational technology,https://example.com/paper2,journal_article\n" +
                          "edtechid.dataset,research-2025,Student Performance Data,Dataset containing student performance metrics,https://example.com/dataset1,dataset\n" +
                          "ed,learning-2025,Assessment Results,Learning outcomes and assessment data,https://example.com/dataset2,dataset";

        const blob = new Blob([csvContent], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'identifier_template.csv';
        a.click();
        window.URL.revokeObjectURL(url);
    }
    </script>
</body>
</html>
