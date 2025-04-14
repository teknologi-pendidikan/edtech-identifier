<?php
require_once __DIR__ . '/includes/config.php';

$conn = create_db_connection($db_config);
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

$uri = $_GET['id'] ?? ''; // e.g. "edtechid.100/2025-001"
if (preg_match('#^(edtechid\.[0-9]+)/(.+)$#', $uri, $matches)) { // Updated regex pattern
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
        http_response_code(404);
        echo "DOI not found.";
    }
    $stmt->close();
} else {
    echo "Invalid identifier format.";
}

$conn->close();
?>

