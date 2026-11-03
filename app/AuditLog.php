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
        'thesis.created', 'thesis.updated',
        'thesis.published', 'thesis.unpublished',
        'thesis.archived', 'thesis.restored',
        'thesis.pdf_uploaded', 'thesis.pdf_viewed',
    ];

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
