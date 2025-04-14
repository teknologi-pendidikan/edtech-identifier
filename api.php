<?php
require_once __DIR__ . '/includes/config.php';

$conn = create_db_connection($db_config);
if (!$conn) {
    // API should return JSON error instead of redirecting
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// Set headers for JSON response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Allow cross-origin requests
header('Access-Control-Allow-Methods: GET');

// Get request parameters
$id = $_GET['id'] ?? '';
$format = strtolower($_GET['format'] ?? 'json');

// Function to get a specific identifier
function get_identifier($id, $conn)
{
    if (preg_match('#^(edtechid\.[0-9]+)/(.+)$#', $id, $matches)) { // Updated regex pattern
        $prefix = $matches[1];
        $suffix = $matches[2];

        $stmt = $conn->prepare("SELECT prefix, suffix, target_url, title, description,
                                DATE_FORMAT(created_at, '%Y-%m-%d') as created
                                FROM identifiers
                                WHERE prefix = ? AND suffix = ?");
        $stmt->bind_param("ss", $prefix, $suffix);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $row['url'] = $row['target_url']; // Rename for better API consistency
            unset($row['target_url']);
            $row['id'] = $row['prefix'] . '/' . $row['suffix']; // Add full ID
            return $row;
        }
        $stmt->close();
    }
    return null;
}

// Function to list all identifiers
function list_identifiers($prefix = null, $limit = 100, $conn)
{
    $sql = "SELECT prefix, suffix, target_url, title, description,
            DATE_FORMAT(created_at, '%Y-%m-%d') as created
            FROM identifiers";

    $params = [];
    $types = "";

    if ($prefix) {
        $sql .= " WHERE prefix = ?";
        $params[] = $prefix;
        $types = "s";
    }

    $sql .= " ORDER BY created_at DESC LIMIT ?";
    $params[] = $limit;
    $types .= "i";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $identifiers = [];
    while ($row = $result->fetch_assoc()) {
        $row['url'] = $row['target_url']; // Rename for better API consistency
        unset($row['target_url']);
        $row['id'] = $row['prefix'] . '/' . $row['suffix']; // Add full ID
        $identifiers[] = $row;
    }
    $stmt->close();

    return $identifiers;
}

// Handle request
if (!empty($id)) {
    // GET /api.php?id=10.100/example
    $identifier = get_identifier($id, $conn);

    if ($identifier) {
        echo json_encode($identifier);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Identifier not found']);
    }
} else {
    // GET /api.php?prefix=10.100&limit=50
    $prefix = $_GET['prefix'] ?? null;
    $limit = min(intval($_GET['limit'] ?? 100), 500); // Max 500 results

    $identifiers = list_identifiers($prefix, $limit, $conn);

    // Return response
    $response = [
        'count' => count($identifiers),
        'data' => $identifiers
    ];

    echo json_encode($response);
}

$conn->close();
?>

