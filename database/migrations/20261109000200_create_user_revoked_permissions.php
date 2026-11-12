<?php
declare(strict_types=1); // keep typed values strict

use Phinx\Migration\AbstractMigration; // the migration parent class comes from Phinx

// per-account permission switches (M12, user's request on 2026-11-09):
// the role still decides the MOST an account may do (Scholar\Auth\Access); the head administrator can then switch
// single permissions OFF for one account, for example "may not publish records"
// one row = one permission switched off for one account; no row = the account has what its role gives
// storing only the switched-off permissions means a row can only ever take power away, never add any
class CreateUserRevokedPermissions extends AbstractMigration {
    public function up(): void {
        $this->execute("
            CREATE TABLE user_revoked_permissions (
                user_id BIGINT UNSIGNED NOT NULL,
                permission_code VARCHAR(64) NOT NULL,
                revoked_by BIGINT UNSIGNED NULL,
                revoked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, permission_code),
                CONSTRAINT fk_revoked_permissions_user FOREIGN KEY (user_id) REFERENCES users(id),
                CONSTRAINT fk_revoked_permissions_revoked_by FOREIGN KEY (revoked_by) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void {
        // dropping the table would quietly switch every turned-off permission back on, so this rollback is refused
        throw new RuntimeException('Rollback would give back switched-off permissions and is not supported.');
    }
}
