CREATE TABLE thesis_authors (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thesis_id BIGINT UNSIGNED NOT NULL,
    author_name VARCHAR(200) NOT NULL,
    author_order SMALLINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_thesis_author_order (thesis_id, author_order),
    CONSTRAINT fk_authors_thesis FOREIGN KEY (thesis_id) REFERENCES theses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
