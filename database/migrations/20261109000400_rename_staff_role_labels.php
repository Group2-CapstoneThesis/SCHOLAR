<?php
declare(strict_types=1); // keep typed values strict

use Phinx\Migration\AbstractMigration; // the migration parent class comes from Phinx

// the user asked on 2026-11-09 to drop "(development label)" and "(provisional)" from the two staff role names
// shown on the account pages; the earlier migration that added them is already applied, so it stays as it was
// and this one renames the labels instead (only the shown names change; the codes rdpo_admin / rdpo_head stay)
class RenameStaffRoleLabels extends AbstractMigration {
    public function up(): void {
        $this->execute("UPDATE roles SET label = 'RDPO / Administrator' WHERE code = 'rdpo_admin'");
        $this->execute("UPDATE roles SET label = 'RDPO / Head administrator' WHERE code = 'rdpo_head'");
    }

    public function down(): void {
        // putting the old names back is harmless, so this rollback is allowed
        $this->execute("UPDATE roles SET label = 'RDPO / Administrator (development label)' WHERE code = 'rdpo_admin'");
        $this->execute("UPDATE roles SET label = 'RDPO / Head administrator (provisional)' WHERE code = 'rdpo_head'");
    }
}
