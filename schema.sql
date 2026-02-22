-- Optimized Database Schema for EdTech Identifier System (DOI-like)
-- This schema fixes the structural issues and adds proper DOI functionality

-- Drop existing tables if you're doing a fresh install
-- DROP TABLE IF EXISTS identifier_logs;
-- DROP TABLE IF EXISTS identifiers; 
-- DROP TABLE IF EXISTS namespace_mappings;
-- DROP TABLE IF EXISTS prefixes;

-- Create namespace mappings table to support both long and short forms
CREATE TABLE namespace_mappings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    long_form VARCHAR(64) NOT NULL COMMENT 'Long form prefix (e.g. edtechid.journal)',
    short_form VARCHAR(8) NOT NULL COMMENT 'Short form prefix (e.g. ej)',
    category VARCHAR(64) NOT NULL COMMENT 'Category name for display',
    description TEXT COMMENT 'Description of this namespace',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Whether this namespace accepts new identifiers',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_long_form (long_form),
    UNIQUE KEY unique_short_form (short_form),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Maps between long-form and short-form namespace prefixes';

-- Optimized identifiers table with proper DOI-like structure  
CREATE TABLE identifiers (
    doi VARCHAR(255) NOT NULL PRIMARY KEY COMMENT 'Complete DOI-like identifier (prefix/suffix)',
    namespace_id INT UNSIGNED NOT NULL COMMENT 'References namespace_mappings.id',
    suffix VARCHAR(100) NOT NULL COMMENT 'The unique suffix within the namespace',
    target_url TEXT NOT NULL COMMENT 'Primary target URL for redirection',
    
    -- Metadata fields (DOI-like)
    title VARCHAR(500) COMMENT 'Resource title/name',
    description TEXT COMMENT 'Resource description',
    resource_type ENUM('journal_article', 'dataset', 'course_module', 'educational_material', 'person', 'other') DEFAULT 'other',
    language VARCHAR(10) DEFAULT 'en' COMMENT 'ISO 639-1 language code',
    
    -- Creator/Publisher information
    creators JSON COMMENT 'Array of creators/authors in structured format',
    publisher VARCHAR(255) COMMENT 'Publisher name',
    publication_year YEAR COMMENT 'Publication year',
    
    -- Technical metadata
    mime_type VARCHAR(100) COMMENT 'MIME type of the target resource',
    file_size BIGINT UNSIGNED COMMENT 'File size in bytes if applicable',
    checksum_md5 VARCHAR(32) COMMENT 'MD5 checksum for integrity checking',
    
    -- Version management (critical for DOI-like systems)
    version VARCHAR(50) DEFAULT '1.0' COMMENT 'Version of this resource',
    is_latest_version BOOLEAN DEFAULT TRUE COMMENT 'Whether this is the latest version',
    superseded_by VARCHAR(255) COMMENT 'DOI of newer version if superseded',
    
    -- Status and lifecycle
    status ENUM('active', 'reserved', 'withdrawn', 'superseded') DEFAULT 'active',
    registration_agency VARCHAR(100) DEFAULT 'EdTech.ID' COMMENT 'Registration agency name',
    
    -- Access control
    access_level ENUM('public', 'restricted', 'private') DEFAULT 'public',
    license VARCHAR(100) COMMENT 'License under which resource is available',
    
    -- Timestamps
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When DOI was registered',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_resolved_at TIMESTAMP NULL COMMENT 'Last time this DOI was resolved',
    resolution_count INT UNSIGNED DEFAULT 0 COMMENT 'Number of times resolved',
    
    -- Constraints and indexes
    FOREIGN KEY (namespace_id) REFERENCES namespace_mappings(id),
    UNIQUE KEY unique_namespace_suffix (namespace_id, suffix),
    INDEX idx_namespace (namespace_id),
    INDEX idx_status (status),
    INDEX idx_resource_type (resource_type),
    INDEX idx_registered (registered_at),
    INDEX idx_updated (updated_at),
    INDEX idx_resolution (last_resolved_at),
    INDEX idx_version (version, is_latest_version),
    FULLTEXT INDEX ft_search (title, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Stores persistent DOI-like identifiers with full metadata';

-- Enhanced activity log table
CREATE TABLE identifier_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doi VARCHAR(255) NOT NULL,
    action ENUM('register', 'resolve', 'update', 'withdraw', 'supersede') NOT NULL,
    details JSON COMMENT 'Action details and metadata changes',
    user_agent TEXT COMMENT 'User agent for resolution tracking',
    ip_address VARCHAR(45) COMMENT 'IP address for resolution tracking',
    referrer TEXT COMMENT 'Referrer URL for resolution analytics',
    response_time_ms INT UNSIGNED COMMENT 'Response time in milliseconds',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_doi (doi),
    INDEX idx_action (action),
    INDEX idx_created (created_at),
    INDEX idx_ip_date (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Comprehensive activity and analytics log';

-- URL alternatives table for content negotiation
CREATE TABLE identifier_urls (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doi VARCHAR(255) NOT NULL,
    url TEXT NOT NULL,
    url_type ENUM('landing', 'resource', 'metadata', 'api') DEFAULT 'resource',
    mime_type VARCHAR(100) COMMENT 'MIME type served at this URL',
    priority TINYINT UNSIGNED DEFAULT 100 COMMENT 'Priority for content negotiation',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (doi) REFERENCES identifiers(doi) ON DELETE CASCADE,
    INDEX idx_doi_type (doi, url_type),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Multiple URLs per identifier for content negotiation';

-- Legacy identifiers table for migration support
CREATE TABLE legacy_identifiers (
    old_id VARCHAR(255) NOT NULL PRIMARY KEY,
    new_doi VARCHAR(255) NOT NULL,
    migration_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (new_doi) REFERENCES identifiers(doi),
    INDEX idx_new_doi (new_doi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Maps old identifier formats to new DOI system';

-- Insert default namespace mappings based on your README specification
INSERT INTO namespace_mappings (long_form, short_form, category, description) VALUES
('edtechid.journal', 'ej', 'Journal Articles', 'Scholarly articles and academic papers in educational technology'),
('edtechid.dataset', 'ed', 'Datasets', 'Educational datasets and research data'),
('edtechid.course', 'ec', 'Course Modules', 'Educational courses and learning modules'),
('edtechid.material', 'em', 'Educational Materials', 'Learning resources and educational content'),
('edtechid.person', 'ep', 'Person/Author', 'Author and contributor identifiers'),
('edtechid.institution', 'ei', 'Institutions', 'Educational institutions and organizations'),
('edtechid.standard', 'es', 'Standards', 'Educational standards and frameworks'),
('edtechid.tool', 'et', 'Tools', 'Educational technology tools and software'),
('edtechid.assessment', 'ea', 'Assessments', 'Educational assessments and evaluations'),
('edtechid.research', 'er', 'Research', 'Educational research and studies');

-- Create optimized functions for identifier operations

DELIMITER //

-- Function to generate a properly formatted DOI
CREATE FUNCTION generate_doi(namespace_long VARCHAR(64), suffix_val VARCHAR(100)) 
RETURNS VARCHAR(255)
READS SQL DATA
DETERMINISTIC
BEGIN
    RETURN CONCAT(namespace_long, '/', suffix_val);
END //

-- Function to resolve short form to long form
CREATE FUNCTION resolve_namespace(input_prefix VARCHAR(64))
RETURNS VARCHAR(64)
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE long_form_result VARCHAR(64) DEFAULT NULL;
    
    -- First check if it's already a long form
    SELECT long_form INTO long_form_result 
    FROM namespace_mappings 
    WHERE long_form = input_prefix AND is_active = TRUE
    LIMIT 1;
    
    -- If not found, check if it's a short form
    IF long_form_result IS NULL THEN
        SELECT long_form INTO long_form_result
        FROM namespace_mappings 
        WHERE short_form = input_prefix AND is_active = TRUE
        LIMIT 1;
    END IF;
    
    RETURN long_form_result;
END //

-- Procedure to register a new identifier with validation
CREATE PROCEDURE register_identifier(
    IN p_namespace VARCHAR(64),
    IN p_suffix VARCHAR(100),
    IN p_target_url TEXT,
    IN p_title VARCHAR(500),
    IN p_description TEXT,
    IN p_resource_type VARCHAR(50),
    OUT p_doi VARCHAR(255),
    OUT p_result VARCHAR(100)
)
BEGIN
    DECLARE v_namespace_id INT UNSIGNED DEFAULT NULL;
    DECLARE v_long_form VARCHAR(64) DEFAULT NULL;
    DECLARE v_doi VARCHAR(255);
    DECLARE exit handler for SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_result = 'ERROR: Registration failed';
    END;
    
    START TRANSACTION;
    
    -- Resolve namespace to long form and get ID
    SELECT nm.id, nm.long_form INTO v_namespace_id, v_long_form
    FROM namespace_mappings nm
    WHERE (nm.long_form = p_namespace OR nm.short_form = p_namespace) 
    AND nm.is_active = TRUE
    LIMIT 1;
    
    IF v_namespace_id IS NULL THEN
        SET p_result = 'ERROR: Invalid namespace';
        ROLLBACK;
    ELSE
        -- Generate DOI
        SET v_doi = generate_doi(v_long_form, p_suffix);
        
        -- Check for conflicts
        IF EXISTS(SELECT 1 FROM identifiers WHERE doi = v_doi) THEN
            SET p_result = 'ERROR: Identifier already exists';
            ROLLBACK;
        ELSE
            -- Insert new identifier
            INSERT INTO identifiers (
                doi, namespace_id, suffix, target_url, title, description, 
                resource_type, status, registered_at
            ) VALUES (
                v_doi, v_namespace_id, p_suffix, p_target_url, p_title, 
                p_description, p_resource_type, 'active', NOW()
            );
            
            -- Log the registration
            INSERT INTO identifier_logs (doi, action, details) 
            VALUES (v_doi, 'register', JSON_OBJECT('initial_url', p_target_url));
            
            SET p_doi = v_doi;
            SET p_result = 'SUCCESS';
            COMMIT;
        END IF;
    END IF;
END //

DELIMITER ;

-- Create views for common queries

-- View for easy identifier lookup with namespace info
CREATE VIEW v_identifier_lookup AS
SELECT 
    i.doi,
    i.suffix,
    nm.long_form as namespace_long,
    nm.short_form as namespace_short,
    nm.category as namespace_category,
    i.target_url,
    i.title,
    i.description,
    i.resource_type,
    i.status,
    i.version,
    i.is_latest_version,
    i.registered_at,
    i.last_resolved_at,
    i.resolution_count
FROM identifiers i
JOIN namespace_mappings nm ON i.namespace_id = nm.id
WHERE i.status = 'active';

-- View for resolution analytics  
CREATE VIEW v_resolution_stats AS
SELECT 
    i.doi,
    i.title,
    nm.category,
    i.resolution_count,
    i.last_resolved_at,
    COUNT(il.id) as total_logs,
    COUNT(CASE WHEN il.action = 'resolve' THEN 1 END) as resolution_logs,
    COUNT(CASE WHEN il.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as recent_activity
FROM identifiers i
JOIN namespace_mappings nm ON i.namespace_id = nm.id
LEFT JOIN identifier_logs il ON i.doi = il.doi
WHERE i.status = 'active'
GROUP BY i.doi, i.title, nm.category, i.resolution_count, i.last_resolved_at;