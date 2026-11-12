<?php
declare(strict_types=1); // keep typed values strict

use Phinx\Migration\AbstractMigration; // the migration parent class comes from Phinx

// two-step login (user's request, 2026-11-10): an authenticator app code after the password,
// backup codes for head administrators, and "remember this device for 30 days"
// - users.totp_secret_encrypted: the authenticator app's secret, encrypted with the key in storage/two_factor.key,
//   so a copied database or backup file alone does not reveal it; NULL = two-step login is off
// - users.totp_enabled_at: when the person finished linking their app
// - users.totp_last_step: the last 30-second window whose code was accepted, so one code cannot be used twice
// - user_backup_codes: ten single-use codes, stored as password hashes, never as the codes themselves
// - user_remembered_devices: one row per browser where "remember this device" was ticked; only a hash of the cookie is kept
class AddTwoFactorLogin extends AbstractMigration {
    public function up(): void {
        $this->execute("
            ALTER TABLE users
                ADD COLUMN totp_secret_encrypted VARBINARY(255) NULL DEFAULT NULL AFTER password_changed_at,
                ADD COLUMN totp_enabled_at TIMESTAMP NULL DEFAULT NULL AFTER totp_secret_encrypted,
                ADD COLUMN totp_last_step BIGINT UNSIGNED NULL DEFAULT NULL AFTER totp_enabled_at
        ");
        $this->execute("
            CREATE TABLE user_backup_codes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                code_hash VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                used_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_backup_codes_user (user_id),
                CONSTRAINT fk_backup_codes_user FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->execute("
            CREATE TABLE user_remembered_devices (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                device_label VARCHAR(120) NOT NULL DEFAULT '',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NOT NULL,
                last_used_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_remembered_devices_token (token_hash),
                KEY idx_remembered_devices_user (user_id),
                CONSTRAINT fk_remembered_devices_user FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void {
        // rolling back turns two-step login off for everyone; that is a loss of protection, so it is refused
        throw new RuntimeException('Rollback would turn off two-step login for every account and is not supported.');
    }
}
