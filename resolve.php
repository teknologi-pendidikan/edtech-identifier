<?php
require_once __DIR__ . '/includes/config.php';

$conn = create_db_connection($db_config);
if (!$conn) {
    // Updated: Use the custom error page for database connection errors
    header('Location: /error.php?code=500');
    exit();
}

$uri = $_GET['id'] ?? ''; // e.g. "edtechid.100/2025-001"
if (preg_match('#^(edtechid\.[a-zA-Z0-9]+)/(.+)$#', $uri, $matches)) { // Updated regex pattern
    $prefix = $matches[1];
    $suffix = $matches[2];

    $stmt = $conn->prepare("SELECT target_url FROM identifiers WHERE prefix = ? AND suffix = ?");
    $stmt->bind_param("ss", $prefix, $suffix);
    $stmt->execute();
    $stmt->bind_result($url);

    if ($stmt->fetch()) {
        header("Location: $url");
        exit();
    } else {
        // Updated: Use the custom error page for 404 errors
        header('Location: /error.php?code=404');
        $stmt->close();
        $conn->close();
        exit();
    }
} else {
    // Updated: Use the custom error page for malformed input
    $conn->close();
    header('Location: /error.php?code=400');
    exit();
}
?>

