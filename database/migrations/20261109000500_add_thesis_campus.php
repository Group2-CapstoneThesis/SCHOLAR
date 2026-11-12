<?php
declare(strict_types=1); // keep typed values strict

use Phinx\Migration\AbstractMigration; // the migration parent class comes from Phinx

// the campus a thesis came from (user's request, 2026-11-09): one of the names in Scholar\Theses\Campuses
// every record that already exists is set to "WCC QC", as the user asked
class AddThesisCampus extends AbstractMigration {
    public function up(): void {
        // 1. add the column; DEFAULT 'WCC QC' fills in every existing record in the same step
        $this->execute("
            ALTER TABLE theses
                ADD COLUMN campus VARCHAR(50) NOT NULL DEFAULT 'WCC QC' AFTER department,
                ADD INDEX idx_theses_campus (campus)
        ");
        // 2. remove the default again: from now on every new record must name its campus (the form requires it),
        //    so a forgotten campus is an error instead of quietly becoming WCC QC
        $this->execute('ALTER TABLE theses ALTER COLUMN campus DROP DEFAULT');
    }

    public function down(): void {
        // dropping the column would erase the campus chosen for every record, so this rollback is refused
        throw new RuntimeException('Rollback would erase every record\'s campus and is not supported.');
    }
}
