-- Create the identifiers table
CREATE TABLE identifiers (
    id CHAR(36) NOT NULL DEFAULT (UUID()), -- MySQL UUID implementation
    prefix VARCHAR(32) NOT NULL COMMENT 'The identifier prefix (e.g. edtechid.100)',
    suffix VARCHAR(100) NOT NULL COMMENT 'The unique identifier suffix within a prefix',
    target_url TEXT NOT NULL COMMENT 'The URL this identifier will redirect to',
    title VARCHAR(255) COMMENT 'Title/name of the resource',
    description TEXT COMMENT 'Description of the resource',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When this identifier was created',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'When this identifier was last modified',

    -- Keys and indexes
    PRIMARY KEY (prefix, suffix),
    UNIQUE KEY unique_id (id),
    INDEX idx_prefix (prefix),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stores persistent identifiers for EdTech resources';

-- Create a log table to track changes (optional but recommended)
CREATE TABLE identifier_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier_id CHAR(36) NOT NULL,
    action ENUM('create', 'update', 'delete') NOT NULL,
    changed_by VARCHAR(255),
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    details JSON COMMENT 'Contains the changes made',

    INDEX idx_identifier (identifier_id),
    INDEX idx_changed_at (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks changes to identifiers';

-- Create a prefixes table to manage available prefixes (optional)
CREATE TABLE prefixes (
    prefix VARCHAR(32) PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'Human-readable name for this prefix',
    description TEXT COMMENT 'Description of what this prefix is used for',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Whether this prefix can be used for new identifiers',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Available identifier prefixes';

-- Insert default prefixes
INSERT INTO prefixes (prefix, name, description)
VALUES
    ('edtechid.internal', 'EDTECH Internal', 'Internal Resources');
