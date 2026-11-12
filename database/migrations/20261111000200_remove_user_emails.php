<?php
declare(strict_types=1); // keep typed values strict

use Phinx\Migration\AbstractMigration; // the migration parent class comes from Phinx

// emails are no longer used (user's request, 2026-11-10): staff log in with their username only, and accounts are
// created, found and managed by username; the column and its unique index are removed
// the stored emails are deleted with the column, so a backup was made before this ran on scholar_dev
class RemoveUserEmails extends AbstractMigration {
    public function up(): void {
        $this->execute('ALTER TABLE users DROP INDEX email, DROP COLUMN email');
    }

    public function down(): void {
        // the emails themselves are gone, so a rollback could only add an empty column back; it is refused instead
        throw new RuntimeException('Rollback cannot bring the deleted emails back and is not supported.');
    }
}
