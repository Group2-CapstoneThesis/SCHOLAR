<?php
// the purpose of this file is for disabling and re-enabling accounts thru CLI until the account management page exists
declare(strict_types=1); // keep typed method arguments strict

namespace Scholar;

use PDO;

// turns an account's login access off or back on without deleting the account or its history
class AccountStatus {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo; // store the database connection for account queries
    }

    // returns 'disabled', 'already_disabled', or 'not_found' so the terminal can print a clear message
    public function disableAccount(string $identifier): string {
        $account = $this->findAccount($identifier);
        if ($account === null) {
            return 'not_found';
        }
        if ((int) $account['is_active'] === 0) {
            return 'already_disabled'; // keep the original disabled_at time instead of overwriting it
        }

        // existing sessions are not deleted here; Session::requireAuthentication() reads is_active on the
        // next request and signs the person out then
        // the change and its audit entry are saved together; if either fails, neither is kept
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare("
                UPDATE users
                SET is_active = 0, disabled_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $statement->execute(['id' => $account['id']]);
            // actor is NULL because this came from the terminal, not from a logged-in RDPO account
            AuditLog::record($this->pdo, null, 'account.disabled', 'user', (int) $account['id']);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack(); // undo the half-finished change before reporting the error
            throw $exception;
        }
        return 'disabled';
    }

    // returns 'enabled', 'already_active', or 'not_found'
    // the password is unchanged, so the person logs in with the same password as before
    public function enableAccount(string $identifier): string {
        $account = $this->findAccount($identifier);
        if ($account === null) {
            return 'not_found';
        }
        if ((int) $account['is_active'] === 1) {
            return 'already_active';
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare("
                UPDATE users
                SET is_active = 1, disabled_at = NULL
                WHERE id = :id
            ");
            $statement->execute(['id' => $account['id']]);
            AuditLog::record($this->pdo, null, 'account.enabled', 'user', (int) $account['id']);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
        return 'enabled';
    }

    // same username-or-email lookup the login form uses, so either identifier works here too
    // returns the row as an array, or null when no account matches
    private function findAccount(string $identifier): ?array {
        $statement = $this->pdo->prepare("
            SELECT id, is_active
            FROM users
            WHERE username = :username OR email = :email
            LIMIT 1
        ");
        $statement->execute(['username' => $identifier, 'email' => $identifier]);
        $account = $statement->fetch();
        if (!$account) {
            return null;
        }
        return $account;
    }
}
