<?php
declare(strict_types=1); // keep typed values strict

use Phinx\Migration\AbstractMigration; // the migration parent class comes from Phinx

// website visits (M12, FB-06, provisional meaning chosen by the user on 2026-11-09):
// one row = one visit to the website in general (not one row per page): a browser that has not opened any page of the
// site in the last 30 minutes starts a new visit (see Scholar\SiteVisits)
// nothing about the visitor is stored: no IP address, no browser details, no account, only the time
class CreateSiteVisits extends AbstractMigration {
    public function up(): void {
        $this->execute("
            CREATE TABLE site_visits (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                visited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_site_visits_visited_at (visited_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void {
        // dropping the table would erase the visit counts RDPO reports on, so this rollback is refused
        throw new RuntimeException('Rollback would erase the visit counts and is not supported.');
    }
}
