<?php
// the purpose of this file is for reading the whole audit history on the Activity log page and for deleting
// entries older than one year (M12, FB-07)
declare(strict_types=1); // keep typed method arguments strict

namespace Scholar;

use PDO;

// AuditLog writes the history; this class reads it for the head administrator's Activity log page
// and removes old entries when the head administrator asks (FB-07: keep one year, deletion itself is logged)
class ActivityLog {
    public const ENTRIES_PER_PAGE = 50;

    // how long entries are kept before the head administrator may delete them (FB-07: one year)
    // written as MySQL interval text, so the database does the date arithmetic in one place
    private const RETENTION_INTERVAL = 'INTERVAL 1 YEAR';

    // readable names for every action code, in the order they appear in the "What" filter
    public const ACTION_LABELS = [
        'auth.login' => 'Logged in',
        'auth.logout' => 'Logged out',
        'thesis.created' => 'Record created',
        'thesis.updated' => 'Record edited',
        'thesis.published' => 'Record published',
        'thesis.unpublished' => 'Record unpublished',
        'thesis.archived' => 'Record archived',
        'thesis.restored' => 'Record restored',
        'thesis.pdf_uploaded' => 'PDF uploaded',
        'thesis.pdf_viewed' => 'PDF opened',
        'report.completion_list_exported' => 'Completion list downloaded',
        'account.created' => 'Account created',
        'account.role_changed' => 'Account role changed',
        'account.email_changed' => 'Account email changed', // older entries only: emails were removed on 2026-11-10
        'account.username_changed' => 'Account username changed',
        'account.permissions_changed' => 'Account permissions changed',
        'account.password_reset' => 'Password reset by a head administrator',
        'account.password_changed' => 'Changed own password',
        'account.disabled' => 'Account disabled',
        'account.enabled' => 'Account enabled',
        'account.two_factor_enabled' => '2FA turned on',
        'account.two_factor_disabled' => '2FA turned off',
        'account.two_factor_reset' => '2FA reset by a head administrator',
        'account.backup_codes_generated' => 'New backup codes made',
        'account.remembered_devices_forgotten' => 'Remembered devices forgotten',
        'account.emergency_access' => 'Emergency access from the terminal',
        'auth.backup_code_used' => 'Logged in with a backup code',
        'audit.old_entries_deleted' => 'Old activity entries deleted',
        'backup.created' => 'Backup made',
        'backup.downloaded' => 'Backup downloaded',
        'backup.restored' => 'Backup restored',
        'report.usage_exported' => 'Usage report downloaded',
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // reads the filters from the address (?action=...&account=...&from=2026-01-01&to=2026-01-31&page=2)
    // anything unexpected falls back to "all", so a strange link shows the full log instead of an error
    // account is an account id, or 'terminal' for actions done from the terminal (no account)
    public static function filtersFromQuery(array $query): array {
        $filters = ['action' => '', 'account' => '', 'from' => '', 'to' => '', 'page' => 1];
        if (isset($query['action']) && is_string($query['action']) && isset(self::ACTION_LABELS[$query['action']])) {
            $filters['action'] = $query['action'];
        }
        if (isset($query['account']) && is_string($query['account'])) {
            if ($query['account'] === 'terminal' || preg_match('/\A[1-9][0-9]{0,18}\z/', $query['account'])) {
                $filters['account'] = $query['account'];
            }
        }
        foreach (['from', 'to'] as $field) {
            if (isset($query[$field]) && is_string($query[$field]) && self::isRealDate($query[$field])) {
                $filters[$field] = $query[$field];
            }
        }
        if (isset($query['page']) && is_string($query['page']) && preg_match('/\A[1-9][0-9]{0,4}\z/', $query['page'])) {
            $filters['page'] = (int) $query['page'];
        }
        return $filters;
    }

    // true for a real calendar date written as YYYY-MM-DD (what an <input type="date"> sends)
    // checkdate() refuses dates such as 2026-02-30
    public static function isRealDate(string $text): bool {
        if (!preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $text, $parts)) {
            return false;
        }
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    // one page of entries, newest first, with the names needed to show them:
    // the acting account's username, and for the affected item the record title or account username
    // returns ['rows' => ..., 'total' => matching count, 'page' => page shown, 'pages' => page count]
    public function search(array $filters): array {
        $conditions = [];
        $values = [];
        if ($filters['action'] !== '') {
            $conditions[] = 'a.action_code = :action';
            $values['action'] = $filters['action'];
        }
        if ($filters['account'] === 'terminal') {
            $conditions[] = 'a.actor_user_id IS NULL';
        } elseif ($filters['account'] !== '') {
            $conditions[] = 'a.actor_user_id = :account';
            $values['account'] = (int) $filters['account'];
        }
        if ($filters['from'] !== '') {
            $conditions[] = 'a.created_at >= :from_date';
            $values['from_date'] = $filters['from'] . ' 00:00:00';
        }
        if ($filters['to'] !== '') {
            // "to" includes that whole day, so compare with the start of the NEXT day
            $conditions[] = 'a.created_at < DATE_ADD(:to_date, INTERVAL 1 DAY)';
            $values['to_date'] = $filters['to'];
        }
        $where = '';
        if (count($conditions) > 0) {
            $where = 'WHERE ' . implode(' AND ', $conditions);
        }

        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM audit_logs a $where");
        $statement->execute($values);
        $total = (int) $statement->fetchColumn();
        $pages = max(1, (int) ceil($total / self::ENTRIES_PER_PAGE));
        $page = min($filters['page'], $pages); // a page number past the end shows the last page
        $offset = ($page - 1) * self::ENTRIES_PER_PAGE;

        // LEFT JOINs: terminal actions have no actor, and a deleted test record or account simply shows no name
        $statement = $this->pdo->prepare("
            SELECT a.id, a.action_code, a.entity_type, a.entity_id, a.changed_fields, a.created_at,
                   actor.username AS actor_username,
                   t.title AS thesis_title,
                   affected.username AS affected_username
            FROM audit_logs a
            LEFT JOIN users actor ON actor.id = a.actor_user_id
            LEFT JOIN theses t ON a.entity_type = 'thesis' AND t.id = a.entity_id
            LEFT JOIN users affected ON a.entity_type = 'user' AND affected.id = a.entity_id
            $where
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($values as $name => $value) {
            $statement->bindValue($name, $value);
        }
        // LIMIT and OFFSET need real integers, so they are bound with PDO::PARAM_INT
        $statement->bindValue('limit', self::ENTRIES_PER_PAGE, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        return ['rows' => $statement->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    // the accounts that appear in the log as the one who acted, for the "Who" filter
    public function actorOptions(): array {
        return $this->pdo->query("
            SELECT DISTINCT u.id, u.username
            FROM audit_logs a JOIN users u ON u.id = a.actor_user_id
            ORDER BY u.username
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    // the moment one year ago (database time); entries before it may be deleted
    public function retentionCutoff(): string {
        return (string) $this->pdo->query('SELECT NOW() - ' . self::RETENTION_INTERVAL)->fetchColumn();
    }

    // how many entries are older than one year
    public function countOlderThanRetention(): int {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE created_at < :cutoff');
        $statement->execute(['cutoff' => $this->retentionCutoff()]);
        return (int) $statement->fetchColumn();
    }

    // deletes every entry older than one year and records that it happened (FB-07)
    // returns how many entries were deleted; 0 means there was nothing to delete and nothing was recorded
    // the new "deleted" entry is written after the deletion, so it is never removed by the same click
    public function deleteOlderThanRetention(int $actorId): int {
        // fix the cutoff once, so the count, the deletion and the history all use exactly the same moment
        $cutoff = $this->retentionCutoff();
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('DELETE FROM audit_logs WHERE created_at < :cutoff');
            $statement->execute(['cutoff' => $cutoff]);
            $deletedCount = $statement->rowCount(); // rowCount() = how many rows the DELETE removed
            if ($deletedCount > 0) {
                // the history keeps how many entries went and up to which date (no names, no record text)
                AuditLog::record($this->pdo, $actorId, 'audit.old_entries_deleted', 'audit_log', null,
                    [$deletedCount . ' entries before ' . substr($cutoff, 0, 10)]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
        return $deletedCount;
    }
}
