<?php
declare(strict_types=1); // keep typed values strict

use Phinx\Migration\AbstractMigration; // the migration parent class comes from Phinx

// stores recent failed login attempts so repeated password guessing can be slowed down
class CreateFailedLogins extends AbstractMigration {
    public function up(): void {
        // one row is one failed attempt; LoginThrottle counts the recent rows before checking a password
        // account_key and address_key are SHA-256 fingerprints, so the typed username/email and the
        // visitor's IP address are not saved as readable text (a fingerprint is not encryption though)
        // attempted_at uses Unix seconds from PHP's time(), the same clock the session timers use,
        // which avoids MySQL/PHP time zone differences and lets tests supply a fixed time
        // the two indexes let MySQL count "this account in the last few minutes" and
        // "this address in the last few minutes" without reading the whole table
        $this->execute("
            CREATE TABLE failed_logins (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_key CHAR(64) NOT NULL,
                address_key CHAR(64) NOT NULL,
                attempted_at BIGINT UNSIGNED NOT NULL,
                INDEX idx_failed_logins_account (account_key, attempted_at),
                INDEX idx_failed_logins_address (address_key, attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void {
        // unlike accounts, these rows are only short-lived counters, so dropping the table loses nothing important
        $this->execute('DROP TABLE failed_logins');
    }
}
