<?php
/**
 * DOI-like Identifier Resolver
 * EdTech Identifier System - Fresh & Simple Version
 * 
 * Handles resolution of identifiers to target URLs
 * Supports multiple URL patterns:
 * - /resolve?id=edtech.journal/2025.001
 * - /resolve/edtech.journal/2025.001
 * - /edtech.journal/2025.001 (with URL rewriting)
 */

require_once 'includes/config.php';

// Enable error reporting for debugging
ini_set('display_errors', 0);
error_reporting(E_ALL);

/**
 * Log resolution attempt
 */
function log_resolution($identifier, $success, $ip_address, $user_agent, $target_url = null) {
    try {
        $conn = db_connect();
        
        // Prepare details for JSON field
        $details = json_encode([
            'success' => $success,
            'target_url' => $target_url,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        $stmt = $conn->prepare("
            INSERT INTO identifier_logs (doi, action, details, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $action = 'resolve'; // Use 'resolve' for both success and failure, details will contain success flag
        $stmt->bind_param("sssss", $identifier, $action, $details, $ip_address, $user_agent);
        $stmt->execute();
        
    } catch (Exception $e) {
        error_log("Failed to log resolution: " . $e->getMessage());
    }
}

/**
 * Parse identifier from various URL patterns
 */
function parse_identifier() {
    // Method 1: Query parameter ?id=identifier
    if (!empty($_GET['id'])) {
        return $_GET['id'];
    }
    
    // Method 2: Path info /resolve/identifier
    if (!empty($_SERVER['PATH_INFO'])) {
        return ltrim($_SERVER['PATH_INFO'], '/');
    }
    
    // Method 3: Request URI parsing (for URL rewriting)
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
    
    // Remove query string
    $request_uri = strtok($request_uri, '?');
    
    // For direct identifier access (e.g., /edtech.journal/2025.001)
    if ($request_uri !== '/' && $request_uri !== $script_name) {
        // Remove leading slash and resolve.php if present
        $identifier = ltrim($request_uri, '/');
        $identifier = preg_replace('#^resolve/?#', '', $identifier);
        
        // Check if it looks like an identifier (has prefix/suffix pattern)
        if (preg_match('#^[a-zA-Z0-9\.]+/.+$#', $identifier)) {
            return $identifier;
        }
    }
    
    return null;
}

/**
 * Resolve identifier to target URL
 */
function resolve_identifier($identifier) {
    try {
        $conn = db_connect();
        
        // Parse identifier into prefix and suffix
        if (!preg_match('#^([a-zA-Z0-9\.]+)/(.+)$#', $identifier, $matches)) {
            return ['success' => false, 'error' => 'Invalid identifier format'];
        }
        
        $prefix = $matches[1];
        $suffix = $matches[2];
        
        // Look up identifier (try both long and short form)
        $stmt = $conn->prepare("
            SELECT i.*, nm.long_form, nm.short_form, nm.category
            FROM identifiers i
            JOIN namespace_mappings nm ON i.namespace_id = nm.id
            WHERE (nm.long_form = ? OR nm.short_form = ?) 
              AND i.suffix = ? 
              AND i.STATUS = 'active'
              AND nm.is_active = 1
        ");
        $stmt->bind_param("sss", $prefix, $prefix, $suffix);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if (!$result) {
            return ['success' => false, 'error' => 'Identifier not found'];
        }
        
        // Update resolution statistics
        $stmt = $conn->prepare("
            UPDATE identifiers 
            SET resolution_count = resolution_count + 1, last_resolved_at = NOW() 
            WHERE doi = ?
        ");
        $stmt->bind_param("s", $result['doi']);
        $stmt->execute();
        
        return [
            'success' => true,
            'data' => $result,
            'target_url' => $result['target_url']
        ];
        
    } catch (Exception $e) {
        error_log("Resolution error: " . $e->getMessage());
        return ['success' => false, 'error' => 'System error during resolution'];
    }
}

/**
 * Handle the resolution request
 */
function handle_resolution() {
    $identifier = parse_identifier();
    
    if (!$identifier) {
        return show_resolver_interface();
    }
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    // Resolve the identifier
    $resolution = resolve_identifier($identifier);
    
    if ($resolution['success']) {
        $target_url = $resolution['target_url'];
        
        // Log successful resolution
        log_resolution($identifier, true, $ip_address, $user_agent, $target_url);
        
        // Redirect to target URL
        header('HTTP/1.1 302 Found');
        header('Location: ' . $target_url);
        header('Cache-Control: no-cache, must-revalidate');
        exit;
        
    } else {
        // Log failed resolution
        log_resolution($identifier, false, $ip_address, $user_agent);
        
        // Show error page
        show_error_page($identifier, $resolution['error']);
    }
}

/**
 * Show resolver interface for manual lookup
 */
function show_resolver_interface() {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Identifier Resolver - EdTech Identifier</title>
        <link rel="stylesheet" href="assets/style.css">
    </head>
    <body>
        <div class="container" style="max-width: 600px; margin-top: var(--cds-spacing-09);">
            <div style="text-align: center; margin-bottom: var(--cds-spacing-07);">
                <h1 style="color: var(--cds-text-primary);">🔗 Identifier Resolver</h1>
                <p class="subtitle">Enter an EdTech identifier to resolve</p>
            </div>

            <div class="card">
                <form method="get" action="resolve.php">
                    <div class="form-group">
                        <label class="form-label" for="id">Identifier</label>
                        <input
                            type="text"
                            id="id"
                            name="id"
                            class="form-input"
                            placeholder="e.g., edtech.journal/2025.001"
                            pattern="[a-zA-Z0-9\.]+/.+"
                            required
                        >
                        <p class="text-muted text-small mt-2">Format: namespace/suffix</p>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        🔍 Resolve Identifier
                    </button>
                </form>
            </div>

            <div style="margin-top: var(--cds-spacing-07); text-align: center;">
                <p class="text-muted text-small">
                    <a href="index.php">← Back to Main Site</a> |
                    <a href="admin/dashboard.php">Admin Dashboard</a>
                </p>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Show error page for failed resolutions
 */
function show_error_page($identifier, $error) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Identifier Not Found - EdTech Identifier</title>
        <link rel="stylesheet" href="assets/style.css">
    </head>
    <body>
        <div class="container" style="max-width: 600px; margin-top: var(--cds-spacing-09);">
            <div style="text-align: center; margin-bottom: var(--cds-spacing-07);">
                <div style="font-size: 4rem; margin-bottom: var(--cds-spacing-05); opacity: 0.5;">❌</div>
                <h1 style="color: var(--cds-text-primary);">Identifier Not Found</h1>
                <p class="subtitle">The requested identifier could not be resolved</p>
            </div>

            <div class="card">
                <div style="margin-bottom: var(--cds-spacing-05);">
                    <strong>Requested Identifier:</strong>
                    <code style="background: var(--cds-layer-accent); padding: 4px 8px; border-radius: 4px;">
                        <?= htmlspecialchars($identifier) ?>
                    </code>
                </div>

                <div style="margin-bottom: var(--cds-spacing-05);">
                    <strong>Error:</strong> <?= htmlspecialchars($error) ?>
                </div>

                <div style="padding: var(--cds-spacing-05); background: var(--cds-layer-accent); border-radius: 4px;">
                    <h3 style="margin-top: 0;">Possible reasons:</h3>
                    <ul style="margin-bottom: 0;">
                        <li>The identifier does not exist</li>
                        <li>The identifier has been withdrawn</li>
                        <li>The namespace is inactive</li>
                        <li>Incorrect identifier format</li>
                    </ul>
                </div>
            </div>

            <div style="margin-top: var(--cds-spacing-07); text-align: center;">
                <p>
                    <a href="resolve.php" class="btn btn-secondary">Try Another Identifier</a>
                    <a href="index.php" class="btn btn-secondary">← Back to Main Site</a>
                </p>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Main execution
handle_resolution();
?>