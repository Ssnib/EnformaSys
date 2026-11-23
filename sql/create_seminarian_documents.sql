-- ===================================================================
-- Migration: Create seminarian_documents table for EnformaSys
-- Module: Manage Seminarian Profile (Physical Documents Archive)
-- ===================================================================

CREATE TABLE IF NOT EXISTS seminarian_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seminarian_id INT NOT NULL,
    document_type VARCHAR(100) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT UNSIGNED NULL DEFAULT 0,
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_seminarian_id (seminarian_id),
    CONSTRAINT fk_seminarian_documents_seminarian FOREIGN KEY (seminarian_id) REFERENCES seminarians(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

