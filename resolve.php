<?php
/**
 * Optimized Identifier Resolver for EdTech DOI-like System
 * Supports both long-form and short-form resolution with content negotiation
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/security.php';

// Set security headers
set_security_headers();

class IdentifierResolver {
    private $conn;
    private $start_time;
    
    public function __construct($db_connection) {
        $this->conn = $db_connection;
        $this->start_time = microtime(true);
    }
    
    /**
     * Resolve an identifier with proper DOI-like functionality
     */
    public function resolve($identifier_input, $accept_header = '', $user_agent = '', $ip = '', $referrer = '') {
        // Normalize and validate the identifier
        $parsed = $this->parseIdentifier($identifier_input);
        if (!$parsed) {
            $this->logResolution($identifier_input, 'resolve', 'Invalid format', $user_agent, $ip, $referrer);
            return ['error' => 'Invalid identifier format', 'code' => 400];
        }
        
        // Look up the identifier in the database
        $identifier_data = $this->lookupIdentifier($parsed['namespace'], $parsed['suffix']);
        if (!$identifier_data) {
            $this->logResolution($identifier_input, 'resolve', 'Not found', $user_agent, $ip, $referrer);
            return ['error' => 'Identifier not found', 'code' => 404];
        }
        
        // Check if identifier is active
        if ($identifier_data['status'] !== 'active') {
            $response = $this->handleSpecialStatus($identifier_data);
            $this->logResolution($identifier_data['doi'], 'resolve', 'Special status: ' . $identifier_data['status'], $user_agent, $ip, $referrer);
            return $response;
        }
        
        // Update resolution statistics
        $this->updateResolutionStats($identifier_data['doi']);
        
        // Determine response based on Accept header (content negotiation)
        $response_type = $this->determineResponseType($accept_header);
        
        // Log successful resolution
        $this->logResolution($identifier_data['doi'], 'resolve', 'Success: ' . $response_type, $user_agent, $ip, $referrer);
        
        switch ($response_type) {
            case 'metadata':
                return $this->returnMetadata($identifier_data);
            case 'json':
                return $this->returnJSON($identifier_data);
            case 'redirect':
            default:
                return $this->returnRedirect($identifier_data);
        }
    }
    
    /**
     * Parse and normalize identifier input
     */
    private function parseIdentifier($input) {
        $input = trim($input);
        
        // Handle full URLs (extract just the identifier part)
        if (preg_match('#^https?://[^/]+/(.+)$#', $input, $matches)) {
            $input = $matches[1];
        }
        
        // Pattern 1: Long form - edtechid.journal/suffix
        if (preg_match('#^(edtechid\.[a-zA-Z0-9_]+)/(.+)$#', $input, $matches)) {
            return [
                'namespace' => $matches[1],
                'suffix' => $matches[2],
                'type' => 'long'
            ];
        }
        
        // Pattern 2: Short form - ej/suffix  
        if (preg_match('#^([a-z]{1,3})/(.+)$#', $input, $matches)) {
            return [
                'namespace' => $matches[1],
                'suffix' => $matches[2],
                'type' => 'short'
            ];
        }
        
        // Pattern 3: Legacy format - 100.001/suffix
        if (preg_match('#^(\d+\.\d+)/(.+)$#', $input, $matches)) {
            return [
                'namespace' => 'edtechid.' . $matches[1],
                'suffix' => $matches[2],
                'type' => 'legacy'
            ];
        }
        
        // Pattern 4: Flexible format - assume missing edtechid prefix
        if (preg_match('#^([a-zA-Z0-9._]+)/(.+)$#', $input, $matches)) {
            $namespace = $matches[1];
            if (!str_starts_with($namespace, 'edtechid.')) {
                $namespace = 'edtechid.' . $namespace;
            }
            return [
                'namespace' => $namespace,
                'suffix' => $matches[2],
                'type' => 'flexible'
            ];
        }
        
        return false;
    }
    
    /**
     * Look up identifier in database with namespace resolution
     */
    private function lookupIdentifier($namespace, $suffix) {
        // Use the optimized view that joins with namespace mappings
        $sql = "
            SELECT 
                i.doi,
                i.namespace_id,
                i.suffix,
                i.target_url,
                i.title,
                i.description,
                i.resource_type,
                i.status,
                i.version,
                i.is_latest_version,
                i.superseded_by,
                i.access_level,
                i.license,
                i.registered_at,
                i.resolution_count,
                nm.long_form as namespace_long,
                nm.short_form as namespace_short,
                nm.category as namespace_category
            FROM identifiers i
            JOIN namespace_mappings nm ON i.namespace_id = nm.id
            WHERE (nm.long_form = ? OR nm.short_form = ?) 
            AND i.suffix = ?
            AND nm.is_active = TRUE
            LIMIT 1
        ";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sss", $namespace, $namespace, $suffix);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        return $result;
    }
    
    /**
     * Determine response type based on Accept header
     */
    private function determineResponseType($accept_header) {
        $accept_header = strtolower($accept_header);
        
        // Content negotiation rules (similar to DOI system)
        if (strpos($accept_header, 'application/json') !== false) {
            return 'json';
        }
        if (strpos($accept_header, 'application/vnd.datacite') !== false) {
            return 'metadata';
        }
        if (strpos($accept_header, 'application/vnd.crossref') !== false) {
            return 'metadata';
        }
        if (strpos($accept_header, 'application/x-bibtex') !== false) {
            return 'metadata';
        }
        if (strpos($accept_header, 'text/x-bibliography') !== false) {
            return 'metadata';
        }
        
        // Default to redirect for browsers
        return 'redirect';
    }
    
    /**
     * Handle special status identifiers (withdrawn, superseded, etc.)
     */
    private function handleSpecialStatus($data) {
        switch ($data['status']) {
            case 'withdrawn':
                return [
                    'error' => 'This identifier has been withdrawn',
                    'code' => 410, // Gone
                    'details' => [
                        'doi' => $data['doi'],
                        'title' => $data['title'],
                        'status' => 'withdrawn'
                    ]
                ];
                
            case 'superseded':
                if ($data['superseded_by']) {
                    return [
                        'redirect' => '/resolve?id=' . urlencode($data['superseded_by']),
                        'code' => 301, // Moved Permanently
                        'details' => [
                            'original_doi' => $data['doi'],
                            'new_doi' => $data['superseded_by'],
                            'message' => 'This identifier has been superseded'
                        ]
                    ];
                }
                break;
                
            case 'reserved':
                return [
                    'error' => 'This identifier is reserved but not yet published',
                    'code' => 404,
                    'details' => [
                        'doi' => $data['doi'],
                        'status' => 'reserved'
                    ]
                ];
        }
        
        return ['error' => 'Identifier is not available', 'code' => 404];
    }
    
    /**
     * Return metadata representation (like DOI content negotiation)
     */
    private function returnMetadata($data) {
        $metadata = [
            'doi' => $data['doi'],
            'title' => $data['title'],
            'description' => $data['description'],
            'resource_type' => $data['resource_type'],
            'namespace' => [
                'long_form' => $data['namespace_long'],
                'short_form' => $data['namespace_short'],
                'category' => $data['namespace_category']
            ],
            'target_url' => $data['target_url'],
            'version' => $data['version'],
            'is_latest_version' => (bool)$data['is_latest_version'],
            'access_level' => $data['access_level'],
            'license' => $data['license'],
            'registered_at' => $data['registered_at'],
            'resolution_count' => (int)$data['resolution_count'],
            'alternative_urls' => $this->getAlternativeUrls($data['doi'])
        ];
        
        if ($data['superseded_by']) {
            $metadata['superseded_by'] = $data['superseded_by'];
        }
        
        return [
            'type' => 'metadata',
            'content_type' => 'application/vnd.edtech-id+json',
            'data' => $metadata
        ];
    }
    
    /**
     * Return JSON representation
     */
    private function returnJSON($data) {
        return [
            'type' => 'json',
            'content_type' => 'application/json',
            'data' => [
                'success' => true,
                'doi' => $data['doi'],
                'target_url' => $data['target_url'],
                'title' => $data['title'],
                'description' => $data['description'],
                'namespace' => $data['namespace_long'],
                'short_form' => $data['namespace_short'] . '/' . $data['suffix']
            ]
        ];
    }
    
    /**
     * Return redirect response (default behavior)
     */
    private function returnRedirect($data) {
        return [
            'type' => 'redirect',
            'url' => $data['target_url'],
            'code' => 302,
            'headers' => [
                'Cache-Control' => 'max-age=3600', // Cache for 1 hour
                'X-DOI' => $data['doi'],
                'X-Resolution-Count' => $data['resolution_count']
            ]
        ];
    }
    
    /**
     * Get alternative URLs for content negotiation
     */
    private function getAlternativeUrls($doi) {
        $sql = "SELECT url, url_type, mime_type FROM identifier_urls 
                WHERE doi = ? AND is_active = TRUE 
                ORDER BY priority ASC";
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
    
    /**
     * Update resolution statistics
     */
    private function updateResolutionStats($doi) {
        $sql = "UPDATE identifiers 
                SET resolution_count = resolution_count + 1,
                    last_resolved_at = NOW() 
                WHERE doi = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("s", $doi);
        $stmt->execute();
        $stmt->close();
    }
    
    /**
     * Log resolution attempt
     */
    private function logResolution($doi, $action, $details, $user_agent = '', $ip = '', $referrer = '') {
        $response_time = round((microtime(true) - $this->start_time) * 1000);
        
        $log_details = json_encode([
            'details' => $details,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        $sql = "INSERT INTO identifier_logs 
                (doi, action, details, user_agent, ip_address, referrer, response_time_ms) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ssssssi", $doi, $action, $log_details, $user_agent, $ip, $referrer, $response_time);
        $stmt->execute();
        $stmt->close();
    }
}

// Main resolution logic
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method not allowed');
}

$conn = create_db_connection($db_config);
if (!$conn) {
    http_response_code(500);
    header('Location: /error.php?code=500');
    exit();
}

// Get resolution parameters
$identifier = $_GET['id'] ?? '';
$accept_header = $_SERVER['HTTP_ACCEPT'] ?? '';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
$referrer = $_SERVER['HTTP_REFERER'] ?? '';

if (empty($identifier)) {
    $conn->close();
    http_response_code(400);
    header('Location: /error.php?code=400');
    exit();
}

// Resolve the identifier
$resolver = new IdentifierResolver($conn);
$result = $resolver->resolve($identifier, $accept_header, $user_agent, $ip_address, $referrer);

$conn->close();

// Handle the response
if (isset($result['error'])) {
    http_response_code($result['code']);
    
    if (strpos($accept_header, 'application/json') !== false) {
        header('Content-Type: application/json');
        echo json_encode($result);
    } else {
        header("Location: /error.php?code={$result['code']}&details=" . urlencode($result['error']));
    }
    exit();
}

// Handle successful responses
switch ($result['type']) {
    case 'redirect':
        http_response_code($result['code']);
        if (isset($result['headers'])) {
            foreach ($result['headers'] as $header => $value) {
                header("$header: $value");
            }
        }
        header("Location: {$result['url']}");
        break;
        
    case 'json':
        http_response_code(200);
        header("Content-Type: {$result['content_type']}");
        echo json_encode($result['data'], JSON_PRETTY_PRINT);
        break;
        
    case 'metadata':
        http_response_code(200);
        header("Content-Type: {$result['content_type']}");
        echo json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        break;
        
    default:
        http_response_code(500);
        header('Location: /error.php?code=500');
}

exit();