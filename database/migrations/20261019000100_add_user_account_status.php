<?php
declare(strict_types=1); // keep typed values strict

use Phinx\Migration\AbstractMigration; // the migration parent class comes from Phinx

// adds an active/disabled status to each account so RDPO can stop a login without deleting the account
// the four foundation migrations stay unchanged; this is a new reviewed change on top of them
class AddUserAccountStatus extends AbstractMigration {
    public function up(): void {
        // is_active: 1 means the account may log in, 0 means it is disabled
        // existing accounts get DEFAULT 1, so nobody is locked out when this migration runs
        // disabled_at remembers when the account was disabled; it stays NULL while the account is active
        $this->execute("
            ALTER TABLE users
                ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash,
                ADD COLUMN disabled_at TIMESTAMP NULL DEFAULT NULL AFTER is_active
        ");
    }

    public function down(): void {
        // removing these columns would quietly turn every disabled account back into a working login,
        // so this rollback is refused just like the foundation migrations
        throw new RuntimeException('Rollback would re-enable disabled accounts and is not supported.');
    }
}
