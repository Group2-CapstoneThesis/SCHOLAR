<?php
declare(strict_types=1); // keep typed values strict

use Phinx\Migration\AbstractMigration; // the migration parent class comes from Phinx

// adds the extra thesis details and columns needed to manage records
class AddThesisRecordFields extends AbstractMigration {
    public function up(): void {
        // adviser, date_submitted, final_defense_date, page_count: additional thesis metadata fields
        // is_public: 0 = only RDPO sees it, 1 = it appears on the public search page; new records start hidden
        // archived_at: set when RDPO archives a record instead of deleting it
        // created_by / updated_by: which RDPO account made or last changed the record; NULL for seeded records
        // updated_at: set by our UPDATE queries, so publishing and archiving also count as changes
        // the first index serves the public search ("public, not archived, sorted or filtered by year");
        // the second one serves the program filter dropdown and filter
        $this->execute("
            ALTER TABLE theses
                ADD COLUMN adviser VARCHAR(200) NULL AFTER program,
                ADD COLUMN date_submitted DATE NULL AFTER keywords,
                ADD COLUMN final_defense_date DATE NULL AFTER date_submitted,
                ADD COLUMN page_count SMALLINT UNSIGNED NULL AFTER final_defense_date,
                ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0 AFTER page_count,
                ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL AFTER is_public,
                ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER archived_at,
                ADD COLUMN updated_by BIGINT UNSIGNED NULL AFTER created_by,
                ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL AFTER created_at,
                ADD INDEX idx_theses_public_year (is_public, archived_at, publication_year),
                ADD INDEX idx_theses_program (program),
                ADD CONSTRAINT fk_theses_created_by FOREIGN KEY (created_by) REFERENCES users(id),
                ADD CONSTRAINT fk_theses_updated_by FOREIGN KEY (updated_by) REFERENCES users(id)
        ");
    }

    public function down(): void {
        // dropping these columns would erase record details that RDPO typed in, so rollback is refused
        throw new RuntimeException('Rollback would erase thesis record details and is not supported.');
    }
}
