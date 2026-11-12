<?php
// the purpose of this file is for writing the "who did what" history (audit_logs table)
declare(strict_types=1); // keep typed method arguments strict

namespace Scholar;

use PDO;
use InvalidArgumentException;

// records one event per important action: logins, logouts, account changes and thesis record changes
// callers that change data should call this inside the same transaction, so the change and its history
// are saved together or not at all
class AuditLog {
    // every action code the application writes; an unknown code is a programming mistake, so it is refused
    private const ACTION_CODES = [
        'auth.login', 'auth.logout',
        'account.disabled', 'account.enabled',
        // staff account management (M12): changed_fields holds only field names such as "role" or "username",
        // never any password
        'account.created', 'account.role_changed',
        // nothing writes this any more (emails were removed on 2026-11-10); it stays listed so older entries in the
        // Activity log keep their readable name and can still be filtered
        'account.email_changed',
        'account.username_changed', // a head administrator changed a username (user's request, 2026-11-10)
        'account.password_reset',   // the head administrator gave the account a new temporary password
        'account.password_changed', // the staff member chose a new password themselves
        'account.permissions_changed', // permission switches moved; changed_fields lists the permission codes
        // two-step login (user's request, 2026-11-10); no code or secret is ever written here
        'account.two_factor_enabled',  // the person linked an authenticator app
        'account.two_factor_disabled', // the person switched two-step login off (RDPO administrators only)
        'account.two_factor_reset',    // a head administrator switched it off for someone who lost their phone
        'account.backup_codes_generated', // a new set of 10 backup codes replaced the old set
        'account.remembered_devices_forgotten', // every "remember this device" browser was forgotten
        'account.emergency_access',    // the terminal gave a temporary password and switched two-step login off
        'auth.backup_code_used',       // a backup code was used instead of the app at login
        // the head administrator deleted entries older than one year (M12, FB-07);
        // changed_fields holds how many and up to which date, e.g. "120 entries before 2025-11-09"
        'audit.old_entries_deleted',
        // backups (M12, FB-04); changed_fields holds the backup file name, which contains no personal data
        'backup.created', 'backup.downloaded', 'backup.restored',
        'report.usage_exported', // the visits and logins report was downloaded (M12, FB-06)
        'thesis.created', 'thesis.updated',
        'thesis.published', 'thesis.unpublished',
        'thesis.archived', 'thesis.restored',
        'thesis.pdf_uploaded', 'thesis.pdf_viewed',
        'report.completion_list_exported', // the Registrar completion list was downloaded (M12, FB-03)
    ];

    // every action code, for the unit test that checks the Activity log has a readable label for each one
    public static function actionCodes(): array {
        return self::ACTION_CODES;
    }

    // $actorUserId is NULL when the action came from the terminal instead of a logged-in RDPO account
    // $changedFields lists only field NAMES, for example ['title', 'abstract']; values are never logged
    public static function record(PDO $pdo, ?int $actorUserId, string $actionCode, ?string $entityType, ?int $entityId, array $changedFields = []): void {
        if (!in_array($actionCode, self::ACTION_CODES, true)) {
            throw new InvalidArgumentException('Unknown audit action.');
        }

        // turn ['title', 'abstract'] into "title, abstract"; an empty list is saved as NULL
        $changedText = null;
        if (count($changedFields) > 0) {
            $changedText = implode(', ', $changedFields);
        }

        $statement = $pdo->prepare("
            INSERT INTO audit_logs (actor_user_id, action_code, entity_type, entity_id, changed_fields)
            VALUES (:actor_user_id, :action_code, :entity_type, :entity_id, :changed_fields)
        ");
        $statement->execute([
            'actor_user_id' => $actorUserId,
            'action_code' => $actionCode,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'changed_fields' => $changedText,
        ]);
    }

    // newest events for one record, used on the RDPO record page to show its history
    // the username comes from a LEFT JOIN so terminal actions (no actor) still appear, labelled later as "terminal"
    public static function historyFor(PDO $pdo, string $entityType, int $entityId, int $limit): array {
        $statement = $pdo->prepare("
            SELECT a.action_code, a.changed_fields, a.created_at, u.username
            FROM audit_logs a
            LEFT JOIN users u ON u.id = a.actor_user_id
            WHERE a.entity_type = :entity_type AND a.entity_id = :entity_id
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT :limit
        ");
        // LIMIT needs a real integer, so bind it with PDO::PARAM_INT instead of passing it in execute()
        $statement->bindValue('entity_type', $entityType);
        $statement->bindValue('entity_id', $entityId, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }
}
