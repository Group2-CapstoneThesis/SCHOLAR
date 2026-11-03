-- Candidate metadata only. No workflow, manuscript paths, or final ERD.
CREATE TABLE theses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fixture_key VARCHAR(64) NULL UNIQUE,
    title VARCHAR(500) NOT NULL,
    publication_year SMALLINT UNSIGNED NULL,
    program VARCHAR(150) NULL,
    abstract TEXT NULL,
    keywords TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
