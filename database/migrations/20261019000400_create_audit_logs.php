<?php
declare(strict_types=1); // keep typed values strict

use Phinx\Migration\AbstractMigration; // the migration parent class comes from Phinx

// a simple history of who did what (audit_logs)
class CreateAuditLogs extends AbstractMigration {
    public function up(): void {
        // actor_user_id: the RDPO account that did it; NULL when it was done from the terminal
        // action_code: a fixed machine name such as 'thesis.created' or 'auth.login'
        // entity_type / entity_id: what was affected, for example 'thesis' and its id
        // changed_fields: only the NAMES of edited fields (e.g. "title, abstract"), never their old or new values,
        //   so the log never copies record text, passwords or session values
        // the indexes let RDPO look up "history of this record", "what did this person do" and "latest events" quickly
        $this->execute("
            CREATE TABLE audit_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                actor_user_id BIGINT UNSIGNED NULL,
                action_code VARCHAR(100) NOT NULL,
                entity_type VARCHAR(50) NULL,
                entity_id BIGINT UNSIGNED NULL,
                changed_fields VARCHAR(500) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_audit_entity (entity_type, entity_id),
                INDEX idx_audit_actor (actor_user_id, created_at),
                INDEX idx_audit_created (created_at),
                CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void {
        // the audit history must not disappear through a rollback
        throw new RuntimeException('Rollback would erase the audit history and is not supported.');
    }
}
