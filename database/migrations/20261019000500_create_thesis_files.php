<?php
declare(strict_types=1); // keep typed values strict

use Phinx\Migration\AbstractMigration; // the migration parent class comes from Phinx

// remembers each uploaded thesis PDF; the file itself lives in storage/theses, outside the public web folder
class CreateThesisFiles extends AbstractMigration {
    public function up(): void {
        // storage_name: the random name the file was saved under (e.g. "9f2c...e1.pdf"); never the uploaded name,
        //   so a visitor cannot guess it and a strange uploaded name can never point outside storage/theses
        // original_filename: the name RDPO's file had, shown on screen and suggested when saving a copy
        // size_bytes / sha256: the file's size and fingerprint, to notice later if a stored file was damaged or changed
        // replaced_at: set when a newer PDF is uploaded for the same thesis; the old file is kept, not deleted
        // the index finds "the current PDF of this thesis" (replaced_at IS NULL) quickly
        $this->execute("
            CREATE TABLE thesis_files (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                thesis_id BIGINT UNSIGNED NOT NULL,
                storage_name VARCHAR(100) NOT NULL UNIQUE,
                original_filename VARCHAR(255) NOT NULL,
                size_bytes BIGINT UNSIGNED NOT NULL,
                sha256 CHAR(64) NOT NULL,
                uploaded_by BIGINT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                replaced_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_thesis_files_current (thesis_id, replaced_at),
                CONSTRAINT fk_thesis_files_thesis FOREIGN KEY (thesis_id) REFERENCES theses(id),
                CONSTRAINT fk_thesis_files_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void {
        // dropping this table would lose track of which stored PDF belongs to which thesis
        throw new RuntimeException('Rollback would orphan stored thesis PDFs and is not supported.');
    }
}
