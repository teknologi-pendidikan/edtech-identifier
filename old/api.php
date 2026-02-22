<?php
/**
 * Optimized REST API for EdTech DOI-like Identifier System
 * Provides comprehensive API access with proper content negotiation
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/resolve.php';

// Set security headers
set_security_headers();

class IdentifierAPI {
    private $conn;
    private $resolver;

    public function __construct($db_connection) {
        $this->conn = $db_connection;
        $this->resolver = new IdentifierResolver($db_connection);
    }

    /**
     * Handle API requests
     */
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = $_GET['path'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? 'application/json';

        // Set appropriate headers
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Accept, Content-Type, Authorization');

        // Handle CORS preflight
        if ($method === 'OPTIONS') {
            http_response_code(200);
            exit();
        }

        try {
            switch ($method) {
                case 'GET':
                    return $this->handleGet($path);
                case 'POST':
                    return $this->handlePost();
                default:
                    throw new Exception('Method not allowed', 405);
            }
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    /**
     * Handle GET requests
     */
    private function handleGet($path) {
        // Parse the path
        if (empty($path)) {
            // List all identifiers
            return $this->listIdentifiers();
        }

        // Check if it's a namespace listing request
        if (preg_match('#^([a-zA-Z0-9.]+)/?$#', $path, $matches)) {
            $namespace = $matches[1];
            if ($this->isValidNamespace($namespace)) {
                return $this->listIdentifiersByNamespace($namespace);
            }
        }

        // Check if it's a specific identifier request
        if (preg_match('#^(.+)$#', $path)) {
            return $this->getIdentifier($path);
        }

        throw new Exception('Invalid API endpoint', 404);
    }

    /**
     * Get a specific identifier with full metadata
     */
    private function getIdentifier($identifier) {
        $result = $this->resolver->resolve($identifier, 'application/json');

        if (isset($result['error'])) {
            throw new Exception($result['error'], $result['code']);
        }

        // Get detailed metadata for API response
        $metadata = $this->getDetailedMetadata($identifier);
        if (!$metadata) {
            throw new Exception('Identifier not found', 404);
        }

        $this->sendSuccess($metadata);
    }

    /**
     * Get detailed metadata for an identifier
     */
    private function getDetailedMetadata($identifier_input) {
        // Parse identifier first
        $parsed = $this->parseIdentifierForAPI($identifier_input);
        if (!$parsed) {
            return null;
        }

        $sql = "
            SELECT
                i.*,
                nm.long_form,
                nm.short_form,
                nm.category,
                nm.description as namespace_description
            FROM identifiers i
            JOIN namespace_mappings nm ON i.namespace_id = nm.id
            WHERE (nm.long_form = ? OR nm.short_form = ?)
            AND i.suffix = ?
            AND i.status = 'active'
            LIMIT 1
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sss", $parsed['namespace'], $parsed['namespace'], $parsed['suffix']);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$result) {
            return null;
        }

        // Format response with comprehensive metadata
        $metadata = [
            'doi' => $result['doi'],
            'namespace' => [
                'long_form' => $result['long_form'],
                'short_form' => $result['short_form'],
                'category' => $result['category'],
                'description' => $result['namespace_description']
            ],
            'suffix' => $result['suffix'],
            'canonical_url' => $this->buildCanonicalURL($result['long_form'], $result['suffix']),
            'short_url' => $this->buildCanonicalURL($result['short_form'], $result['suffix']),
            'target_url' => $result['target_url'],
            'metadata' => [
                'title' => $result['title'],
                'description' => $result['description'],
                'resource_type' => $result['resource_type'],
                'language' => $result['language'],
                'creators' => json_decode($result['creators'] ?? '[]', true),
                'publisher' => $result['publisher'],
                'publication_year' => $result['publication_year'],
                'license' => $result['license']
            ],
            'technical_metadata' => [
                'mime_type' => $result['mime_type'],
                'file_size' => $result['file_size'],
                'checksum_md5' => $result['checksum_md5']
            ],
            'version_info' => [
                'version' => $result['version'],
                'is_latest_version' => (bool)$result['is_latest_version'],
                'superseded_by' => $result['superseded_by']
            ],
            'access_info' => [
                'access_level' => $result['access_level'],
                'status' => $result['status']
            ],
            'registration_info' => [
                'registered_at' => $result['registered_at'],
                'updated_at' => $result['updated_at'],
                'registration_agency' => $result['registration_agency']
            ],
            'statistics' => [
                'resolution_count' => (int)$result['resolution_count'],
                'last_resolved_at' => $result['last_resolved_at']
            ],
            'alternative_urls' => $this->getAlternativeUrls($result['doi']),
            'citations' => $this->generateCitations($result),
            '_links' => $this->generateHATEOASLinks($result)
        ];

        return $metadata;
    }

    /**
     * List identifiers with optional filtering
     */
    private function listIdentifiers() {
        $namespace = $_GET['namespace'] ?? '';
        $search = $_GET['search'] ?? '';
        $resource_type = $_GET['resource_type'] ?? '';
        $limit = min((int)($_GET['limit'] ?? 20), 100);
        $offset = max((int)($_GET['offset'] ?? 0), 0);
        $include_stats = $_GET['include_stats'] ?? 'false';

        // Build dynamic query
        $where_conditions = ["i.status = 'active'"];
        $params = [];
        $types = "";

        if ($namespace) {
            $where_conditions[] = "(nm.long_form = ? OR nm.short_form = ?)";
            $params[] = $namespace;
            $params[] = $namespace;
            $types .= "ss";
        }

        if ($search) {
            $where_conditions[] = "(i.title LIKE ? OR i.description LIKE ? OR i.suffix LIKE ?)";
            $search_term = "%$search%";
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $types .= "sss";
        }

        if ($resource_type) {
            $where_conditions[] = "i.resource_type = ?";
            $params[] = $resource_type;
            $types .= "s";
        }

        $where_clause = "WHERE " . implode(" AND ", $where_conditions);

        $sql = "
            SELECT
                i.doi,
                i.suffix,
                i.target_url,
                i.title,
                i.description,
                i.resource_type,
                i.version,
                i.registered_at,
                " . ($include_stats === 'true' ? 'i.resolution_count, i.last_resolved_at,' : '') . "
                nm.long_form,
                nm.short_form,
                nm.category
            FROM identifiers i
            JOIN namespace_mappings nm ON i.namespace_id = nm.id
            $where_clause
            ORDER BY i.registered_at DESC
            LIMIT ? OFFSET ?
        ";

        $params[] = $limit;
        $params[] = $offset;
        $types .= "ii";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $identifiers = [];
        while ($row = $result->fetch_assoc()) {
            $identifier = [
                'doi' => $row['doi'],
                'namespace' => [
                    'long_form' => $row['long_form'],
                    'short_form' => $row['short_form'],
                    'category' => $row['category']
                ],
                'suffix' => $row['suffix'],
                'canonical_url' => $this->buildCanonicalURL($row['long_form'], $row['suffix']),
                'short_url' => $this->buildCanonicalURL($row['short_form'], $row['suffix']),
                'target_url' => $row['target_url'],
                'title' => $row['title'],
                'description' => $row['description'],
                'resource_type' => $row['resource_type'],
                'version' => $row['version'],
                'registered_at' => $row['registered_at']
            ];

            if ($include_stats === 'true') {
                $identifier['statistics'] = [
                    'resolution_count' => (int)$row['resolution_count'],
                    'last_resolved_at' => $row['last_resolved_at']
                ];
            }

            $identifiers[] = $identifier;
        }
        $stmt->close();

        // Get total count for pagination
        $count_sql = "
            SELECT COUNT(*) as total
            FROM identifiers i
            JOIN namespace_mappings nm ON i.namespace_id = nm.id
            $where_clause
        ";

        // Remove limit/offset from params for count query
        $count_params = array_slice($params, 0, -2);
        $count_types = substr($types, 0, -2);

        $stmt = $this->conn->prepare($count_sql);
        if ($count_types) {
            $stmt->bind_param($count_types, ...$count_params);
        }
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        $response = [
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset,
            'count' => count($identifiers),
            'data' => $identifiers,
            'pagination' => $this->buildPaginationLinks($total, $limit, $offset)
        ];

        $this->sendSuccess($response);
    }

    /**
     * List identifiers by namespace
     */
    private function listIdentifiersByNamespace($namespace) {
        $_GET['namespace'] = $namespace;
        return $this->listIdentifiers();
    }

    /**
     * Generate citations in various formats
     */
    private function generateCitations($data) {
        $citations = [];

        // APA format
        $apa = '';
        if ($data['creators']) {
            $creators = json_decode($data['creators'], true);
            $apa .= implode(', ', array_map(function($creator) {
                return $creator['family_name'] . ', ' . $creator['given_name'][0] . '.';
            }, $creators)) . ' ';
        }
        if ($data['publication_year']) {
            $apa .= "({$data['publication_year']}). ";
        }
        $apa .= $data['title'] . '. ';
        if ($data['publisher']) {
            $apa .= $data['publisher'] . '. ';
        }
        $apa .= "https://urn.edtech.or.id/{$data['doi']}";
        $citations['apa'] = $apa;

        // BibTeX format
        $bibtex = "@misc{" . str_replace(['/', '.'], ['_', '_'], $data['doi']) . ",\n";
        $bibtex .= "  title={" . $data['title'] . "},\n";
        if ($data['creators']) {
            $creators = json_decode($data['creators'], true);
            $author_list = implode(' and ', array_map(function($creator) {
                return $creator['given_name'] . ' ' . $creator['family_name'];
            }, $creators));
            $bibtex .= "  author={" . $author_list . "},\n";
        }
        if ($data['publication_year']) {
            $bibtex .= "  year={" . $data['publication_year'] . "},\n";
        }
        $bibtex .= "  url={https://urn.edtech.or.id/" . $data['doi'] . "},\n";
        $bibtex .= "  doi={" . $data['doi'] . "}\n}";
        $citations['bibtex'] = $bibtex;

        return $citations;
    }

    /**
     * Generate HATEOAS links
     */
    private function generateHATEOASLinks($data) {
        $base_url = 'https://' . $_SERVER['HTTP_HOST'];

        return [
            'self' => [
                'href' => "{$base_url}/api/{$data['doi']}",
                'type' => 'application/json'
            ],
            'resolve' => [
                'href' => "{$base_url}/{$data['doi']}",
                'type' => 'text/html'
            ],
            'metadata' => [
                'href' => "{$base_url}/{$data['doi']}",
                'type' => 'application/vnd.edtech-id+json'
            ],
            'target' => [
                'href' => $data['target_url'],
                'type' => 'application/octet-stream'
            ]
        ];
    }

    /**
     * Helper methods
     */
    private function parseIdentifierForAPI($input) {
        $input = trim($input, '/');

        if (preg_match('#^(edtechid\.[a-zA-Z0-9_]+)/(.+)$#', $input, $matches)) {
            return ['namespace' => $matches[1], 'suffix' => $matches[2]];
        }
        if (preg_match('#^([a-z]{1,3})/(.+)$#', $input, $matches)) {
            return ['namespace' => $matches[1], 'suffix' => $matches[2]];
        }
        if (preg_match('#^([a-zA-Z0-9._]+)/(.+)$#', $input, $matches)) {
            $namespace = $matches[1];
            if (!str_starts_with($namespace, 'edtechid.')) {
                $namespace = 'edtechid.' . $namespace;
            }
            return ['namespace' => $namespace, 'suffix' => $matches[2]];
        }

        return null;
    }

    private function isValidNamespace($namespace) {
        $sql = "SELECT 1 FROM namespace_mappings
                WHERE (long_form = ? OR short_form = ?) AND is_active = TRUE";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ss", $namespace, $namespace);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $exists;
    }

    private function buildCanonicalURL($namespace, $suffix) {
        $base = 'https://' . $_SERVER['HTTP_HOST'];
        return "{$base}/{$namespace}/{$suffix}";
    }

    private function getAlternativeUrls($doi) {
        $sql = "SELECT url, url_type, mime_type FROM identifier_urls
                WHERE doi = ? AND is_active = TRUE ORDER BY priority";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("s", $doi);
        $stmt->execute();
        $result = $stmt->get_result();

        $urls = [];
        while ($row = $result->fetch_assoc()) {
            $urls[] = $row;
        }
        $stmt->close();

        return $urls;
    }

    private function buildPaginationLinks($total, $limit, $offset) {
        $base_url = 'https://' . $_SERVER['HTTP_HOST'] . '/api?' . http_build_query(array_diff_key($_GET, ['limit' => null, 'offset' => null]));
        $links = [];

        if ($offset > 0) {
            $links['previous'] = $base_url . "&limit=$limit&offset=" . max(0, $offset - $limit);
        }

        if ($offset + $limit < $total) {
            $links['next'] = $base_url . "&limit=$limit&offset=" . ($offset + $limit);
        }

        $links['first'] = $base_url . "&limit=$limit&offset=0";
        $links['last'] = $base_url . "&limit=$limit&offset=" . (floor(($total - 1) / $limit) * $limit);

        return $links;
    }

    private function sendSuccess($data, $code = 200) {
        http_response_code($code);
        echo json_encode([
            'success' => true,
            'timestamp' => date('c'),
            'data' => $data
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit();
    }

    private function sendError($message, $code = 400) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'timestamp' => date('c'),
            'code' => $code
        ], JSON_PRETTY_PRINT);
        exit();
    }

    /**
     * Handle POST requests (for registration)
     */
    private function handlePost() {
        // This would handle identifier registration
        // For now, return method not implemented
        throw new Exception('Registration endpoint not yet implemented', 501);
    }
}

// Main execution
$conn = create_db_connection($db_config);
if (!$conn) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

$api = new IdentifierAPI($conn);
$api->handleRequest();

$conn->close();
