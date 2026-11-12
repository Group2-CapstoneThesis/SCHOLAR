<?php
declare(strict_types=1); // keep typed values strict

use Phinx\Migration\AbstractMigration; // the migration parent class comes from Phinx

// M12 staff account management (2026-11-09):
// 1. adds the provisional head administrator role (FB-07, not yet confirmed by RDPO), who manages staff accounts
// 2. adds two password columns to users:
//    must_change_password  1 = the account still has a temporary password and must choose its own before working
//    password_changed_at   when the password last changed; sessions that logged in before this time are ended
class AddHeadAdminRoleAndPasswordFields extends AbstractMigration {
    public function up(): void {
        // both staff roles are added if missing; an existing row is left exactly as it is
        // (the seed also adds rdpo_admin, but account management needs both roles even in a database that was never seeded)
        $this->execute("
            INSERT INTO roles (code, label) VALUES
                ('rdpo_admin', 'RDPO / Administrator (development label)'),
                ('rdpo_head', 'RDPO / Head administrator (provisional)')
            ON DUPLICATE KEY UPDATE code = code
        ");

        // existing accounts get must_change_password = 0, so nobody is forced to change a password they chose themselves
        $this->execute("
            ALTER TABLE users
                ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash,
                ADD COLUMN password_changed_at TIMESTAMP NULL DEFAULT NULL AFTER must_change_password
        ");
    }

    public function down(): void {
        // removing these would let accounts with a temporary password skip choosing their own,
        // so this rollback is refused like the other account migrations
        throw new RuntimeException('Rollback would drop the temporary-password safeguard and is not supported.');
    }
}
