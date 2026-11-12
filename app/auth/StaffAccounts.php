<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Auth;

use PDO;
use PDOException;
use RuntimeException;
use Scholar\AuditLog;

// RDPO staff account management (M12): the head administrator creates accounts, changes roles and usernames,
// resets forgotten passwords and disables or re-enables accounts; every staff member can change their own password
// accounts are never deleted, only disabled, so the audit history always still points at a real account
class StaffAccounts {
    // the same password length rule as the terminal command: at least 12 bytes, and at most 72 because
    // PHP's password_hash() (bcrypt) ignores anything after the 72nd byte
    public const PASSWORD_MIN_BYTES = 12;
    public const PASSWORD_MAX_BYTES = 72;

    // letters and digits for temporary passwords, without look-alikes such as 0/o, 1/l/i
    private const TEMPORARY_PASSWORD_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ---------------------------------------------------------------------------------------------
    // reading
    // ---------------------------------------------------------------------------------------------

    // every account for the list page: active accounts first, then by username
    // last_login_at is the newest "auth.login" entry in the audit log (NULL when the account never logged in)
    public function listAccounts(): array {
        $statement = $this->pdo->query("
            SELECT u.id, u.username, u.is_active, u.disabled_at, u.must_change_password, u.created_at,
                   r.code AS role_code, r.label AS role_label,
                   (SELECT MAX(a.created_at) FROM audit_logs a
                    WHERE a.action_code = 'auth.login' AND a.actor_user_id = u.id) AS last_login_at
            FROM users u
            JOIN roles r ON r.id = u.role_id
            ORDER BY u.is_active DESC, u.username
        ");
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    // one account with the same columns as listAccounts(), or null when the id does not exist
    public function findAccount(int $accountId): ?array {
        $statement = $this->pdo->prepare("
            SELECT u.id, u.username, u.is_active, u.disabled_at, u.must_change_password, u.created_at,
                   u.totp_enabled_at, r.code AS role_code, r.label AS role_label,
                   (SELECT MAX(a.created_at) FROM audit_logs a
                    WHERE a.action_code = 'auth.login' AND a.actor_user_id = u.id) AS last_login_at
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE u.id = :id
        ");
        $statement->execute(['id' => $accountId]);
        $account = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            return null;
        }
        return $account;
    }

    // the roles a staff account may be given, as code => label, for the role dropdowns
    // only roles that Access knows about are offered (see Access::staffRoleCodes())
    public function roleOptions(): array {
        $options = [];
        $statement = $this->pdo->query('SELECT code, label FROM roles ORDER BY id');
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $role) {
            if (in_array($role['code'], Access::staffRoleCodes(), true)) {
                $options[$role['code']] = $role['label'];
            }
        }
        return $options;
    }

    // ---------------------------------------------------------------------------------------------
    // checking typed values (static: no database needed, so the unit tests can call them directly)
    // ---------------------------------------------------------------------------------------------

    // the New account form: returns ['values' => [...], 'errors' => [field => message]]
    // an empty errors list means the values may be saved
    public static function newAccountValues(array $post): array {
        $values = ['username' => '', 'role_code' => 'rdpo_admin'];
        // a modified form can send an array instead of text, so check the type before trimming
        if (isset($post['username']) && is_string($post['username'])) {
            $values['username'] = trim($post['username']);
        }
        if (isset($post['role_code']) && is_string($post['role_code'])) {
            $values['role_code'] = $post['role_code'];
        }

        $errors = [];
        $usernameProblem = self::usernameProblem($values['username']);
        if ($usernameProblem !== '') {
            $errors['username'] = $usernameProblem;
        }
        if (!in_array($values['role_code'], Access::staffRoleCodes(), true)) {
            $errors['role_code'] = 'Choose one of the listed roles.';
        }
        return ['values' => $values, 'errors' => $errors];
    }

    // '' when the username is acceptable, otherwise the message to show
    // same rule as the terminal command: 3-64 letters, digits, underscore, dot or hyphen
    public static function usernameProblem(string $username): string {
        if ($username === '') {
            return 'Enter a username.';
        }
        if (!preg_match('/\A[a-zA-Z0-9_.-]{3,64}\z/', $username)) {
            return 'Use 3 to 64 letters, digits, underscores, dots or hyphens.';
        }
        return '';
    }

    // '' when the new password may be used, otherwise the message to show
    // the password is never trimmed: spaces can be part of a password
    public static function newPasswordProblem(string $newPassword, string $confirmation, string $username): string {
        if (strlen($newPassword) < self::PASSWORD_MIN_BYTES) {
            return 'Use at least ' . self::PASSWORD_MIN_BYTES . ' characters.';
        }
        if (strlen($newPassword) > self::PASSWORD_MAX_BYTES) {
            return 'Use at most ' . self::PASSWORD_MAX_BYTES . ' characters (fewer if you use letters like ñ).';
        }
        if (strpos($newPassword, "\0") !== false) {
            return 'The password contains a character that cannot be used.';
        }
        // stripos() finds text without caring about upper/lower case
        if ($username !== '' && stripos($newPassword, $username) !== false) {
            return 'Do not put your username in your password.';
        }
        if (!hash_equals($newPassword, $confirmation)) {
            return 'The two new passwords are not the same.';
        }
        return '';
    }

    // a new random temporary password such as "k7mq-x3pa-hr9t-2bwe" (16 random characters, about 79 bits)
    // random_int() uses the operating system's secure random source, unlike rand()
    public static function temporaryPassword(): string {
        $alphabet = self::TEMPORARY_PASSWORD_ALPHABET;
        $groups = [];
        for ($group = 0; $group < 4; $group++) {
            $characters = '';
            for ($position = 0; $position < 4; $position++) {
                $characters .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $characters;
        }
        return implode('-', $groups);
    }

    // ---------------------------------------------------------------------------------------------
    // changes made by the head administrator
    // each change and its audit entry are saved together; if either fails, neither is kept
    // ---------------------------------------------------------------------------------------------

    // creates the account with a temporary password that the person must replace when they first log in
    // returns ['result' => 'created', 'id' => ..., 'temporary_password' => ...]
    //      or ['result' => 'taken'] when the username is already used by another account
    public function createAccount(array $values, int $actorId): array {
        if ($this->usernameTaken($values['username'], 0)) {
            return ['result' => 'taken'];
        }
        $statement = $this->pdo->prepare('SELECT id FROM roles WHERE code = :code');
        $statement->execute(['code' => $values['role_code']]);
        $roleId = $statement->fetchColumn();
        if (!$roleId) {
            throw new RuntimeException('Run the migrations first; the staff role is missing.');
        }

        $temporaryPassword = self::temporaryPassword();
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare("
                INSERT INTO users (role_id, username, password_hash, must_change_password, password_changed_at)
                VALUES (:role_id, :username, :password_hash, 1, FROM_UNIXTIME(:now))
            ");
            $statement->execute([
                'role_id' => $roleId,
                'username' => $values['username'],
                'password_hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT), // store the hash, never the password
                'now' => time(),
            ]);
            $accountId = (int) $this->pdo->lastInsertId();
            AuditLog::record($this->pdo, $actorId, 'account.created', 'user', $accountId, ['role']);
            $this->pdo->commit();
        } catch (PDOException $exception) {
            $this->pdo->rollBack();
            // 23000 = a unique key refused the row: someone else saved the same username a moment ago
            if ($exception->getCode() === '23000') {
                return ['result' => 'taken'];
            }
            throw $exception;
        }
        return ['result' => 'created', 'id' => $accountId, 'temporary_password' => $temporaryPassword];
    }

    // returns 'changed', 'unchanged', 'self', 'unknown_role' or 'not_found'
    // nobody may change their own role: together with the same rule for disabling, this means the head administrator
    // doing the change always stays an active head administrator, so the system can never be left without one
    public function changeRole(int $accountId, string $roleCode, int $actorId): string {
        if ($accountId === $actorId) {
            return 'self';
        }
        if (!in_array($roleCode, Access::staffRoleCodes(), true)) {
            return 'unknown_role';
        }
        $account = $this->findAccount($accountId);
        if ($account === null) {
            return 'not_found';
        }
        if ($account['role_code'] === $roleCode) {
            return 'unchanged';
        }
        $this->saveWithHistory(
            'UPDATE users SET role_id = (SELECT id FROM roles WHERE code = :role_code) WHERE id = :id',
            ['role_code' => $roleCode, 'id' => $accountId],
            $actorId, 'account.role_changed', $accountId, ['role']
        );
        // the new role applies on the person's next click, because Session reads the role on every request
        return 'changed';
    }

    // changes the username (user's request, 2026-11-10); only head administrators open the account pages at all
    // a head administrator may change their OWN username and those of RDPO administrators, but not another head
    // administrator's: 'changed', 'unchanged', 'taken', 'other_head' or 'not_found'
    // the page checks the format first with usernameProblem()
    public function changeUsername(int $accountId, string $username, int $actorId): string {
        $account = $this->findAccount($accountId);
        if ($account === null) {
            return 'not_found';
        }
        if ($account['role_code'] === 'rdpo_head' && $accountId !== $actorId) {
            return 'other_head';
        }
        if ($account['username'] === $username) {
            return 'unchanged';
        }
        // usernames are compared without regard to capitals by the database, so "Ana" and "ana" count as taken
        if ($this->usernameTaken($username, $accountId)) {
            return 'taken';
        }
        try {
            $this->saveWithHistory(
                'UPDATE users SET username = :username WHERE id = :id',
                ['username' => $username, 'id' => $accountId],
                $actorId, 'account.username_changed', $accountId, ['username']
            );
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return 'taken'; // another request took it at the same moment; the unique index refused this one
            }
            throw $exception;
        }
        return 'changed';
    }

    // true when this head administrator may change the username of $staffMember (a row from findAccount())
    public static function mayChangeUsername(array $staffMember, int $actorId): bool {
        return (int) $staffMember['id'] === $actorId || $staffMember['role_code'] !== 'rdpo_head';
    }

    // $makeActive = false disables the account, true enables it again
    // returns 'disabled', 'enabled', 'unchanged', 'self' or 'not_found'
    public function setActive(int $accountId, bool $makeActive, int $actorId): string {
        if ($accountId === $actorId) {
            return 'self'; // you cannot lock yourself out
        }
        $account = $this->findAccount($accountId);
        if ($account === null) {
            return 'not_found';
        }
        $isActive = (int) $account['is_active'] === 1;
        if ($isActive === $makeActive) {
            return 'unchanged';
        }
        if ($makeActive) {
            $this->saveWithHistory(
                'UPDATE users SET is_active = 1, disabled_at = NULL WHERE id = :id',
                ['id' => $accountId], $actorId, 'account.enabled', $accountId, []
            );
            return 'enabled';
        }
        // the person's open sessions end on their next click: Session::requireAuthentication() reads is_active every time
        $this->saveWithHistory(
            'UPDATE users SET is_active = 0, disabled_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $accountId], $actorId, 'account.disabled', $accountId, []
        );
        return 'disabled';
    }

    // gives the account a new temporary password (for a forgotten password) and ends its open sessions
    // returns ['result' => 'reset', 'temporary_password' => ...] or ['result' => 'self'|'not_found']
    // your own password is changed on the "My password" page instead, which asks for the current one
    public function resetPassword(int $accountId, int $actorId): array {
        if ($accountId === $actorId) {
            return ['result' => 'self'];
        }
        if ($this->findAccount($accountId) === null) {
            return ['result' => 'not_found'];
        }
        $temporaryPassword = self::temporaryPassword();
        // password_changed_at = now ends every session that logged in before this moment (see Session::requireAuthentication())
        $this->saveWithHistory(
            'UPDATE users SET password_hash = :password_hash, must_change_password = 1, password_changed_at = FROM_UNIXTIME(:now) WHERE id = :id',
            ['password_hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT), 'now' => time(), 'id' => $accountId],
            $actorId, 'account.password_reset', $accountId, ['password']
        );
        return ['result' => 'reset', 'temporary_password' => $temporaryPassword];
    }

    // ---------------------------------------------------------------------------------------------
    // permission switches (user's request, 2026-11-09)
    // ---------------------------------------------------------------------------------------------

    // the permissions switched OFF for one account, e.g. ['thesis.publish']
    public function revokedPermissions(int $accountId): array {
        $statement = $this->pdo->prepare('SELECT permission_code FROM user_revoked_permissions WHERE user_id = :id ORDER BY permission_code');
        $statement->execute(['id' => $accountId]);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    // reads the switches from the form: a browser sends only the switches that are ON, as permissions[]=thesis.edit ...
    // anything that is not one of Access::SWITCHABLE_PERMISSIONS is ignored, so a modified form cannot invent permissions
    public static function switchedOnFromForm(array $post): array {
        $switchedOn = [];
        if (isset($post['permissions']) && is_array($post['permissions'])) {
            foreach ($post['permissions'] as $code) {
                if (is_string($code) && isset(Access::SWITCHABLE_PERMISSIONS[$code]) && !in_array($code, $switchedOn, true)) {
                    $switchedOn[] = $code;
                }
            }
        }
        return $switchedOn;
    }

    // saves the switches for one account: every switchable permission NOT in $switchedOn is stored as switched off
    // returns 'changed', 'unchanged', 'self' or 'not_found'
    // nobody may switch their own permissions, so a head administrator cannot lock themselves out by mistake
    public function savePermissions(int $accountId, array $switchedOn, int $actorId): string {
        if ($accountId === $actorId) {
            return 'self';
        }
        if ($this->findAccount($accountId) === null) {
            return 'not_found';
        }
        // array_keys() gives the codes of the switchable list; array_diff() keeps the ones that are not switched on
        $newOff = array_values(array_diff(array_keys(Access::SWITCHABLE_PERMISSIONS), $switchedOn));
        $oldOff = $this->revokedPermissions($accountId);
        // the permissions whose switch moved, in either direction; these names go into the history
        $changed = array_merge(array_diff($newOff, $oldOff), array_diff($oldOff, $newOff));
        if (count($changed) === 0) {
            return 'unchanged';
        }
        sort($changed);

        $this->pdo->beginTransaction();
        try {
            // simplest correct way to replace the set: remove this account's rows, then add the new ones
            $statement = $this->pdo->prepare('DELETE FROM user_revoked_permissions WHERE user_id = :id');
            $statement->execute(['id' => $accountId]);
            $statement = $this->pdo->prepare("
                INSERT INTO user_revoked_permissions (user_id, permission_code, revoked_by)
                VALUES (:user_id, :permission_code, :revoked_by)
            ");
            foreach ($newOff as $code) {
                $statement->execute(['user_id' => $accountId, 'permission_code' => $code, 'revoked_by' => $actorId]);
            }
            // the history lists the permission codes that moved (not secret), e.g. "thesis.archive, thesis.publish"
            AuditLog::record($this->pdo, $actorId, 'account.permissions_changed', 'user', $accountId, $changed);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
        // like a role change, this applies on the person's next click (Session reads the switches on every request)
        return 'changed';
    }

    // the terminal command "set-role" (bin/console.php), used to make the FIRST head administrator,
    // because before that nobody can open the account pages; returns 'changed', 'unchanged', 'unknown_role' or 'not_found'
    // the audit entry has no actor (NULL), which the history shows as "terminal"
    public function changeRoleFromTerminal(string $identifier, string $roleCode): string {
        if (!in_array($roleCode, Access::staffRoleCodes(), true)) {
            return 'unknown_role';
        }
        $statement = $this->pdo->prepare('SELECT id FROM users WHERE username = :username');
        $statement->execute(['username' => $identifier]);
        $accountId = (int) $statement->fetchColumn(); // 0 when no account matches
        if ($accountId === 0) {
            return 'not_found';
        }
        $account = $this->findAccount($accountId);
        if ($account['role_code'] === $roleCode) {
            return 'unchanged';
        }
        $this->saveWithHistory(
            'UPDATE users SET role_id = (SELECT id FROM roles WHERE code = :role_code) WHERE id = :id',
            ['role_code' => $roleCode, 'id' => $accountId],
            null, 'account.role_changed', $accountId, ['role']
        );
        return 'changed';
    }

    // ---------------------------------------------------------------------------------------------
    // a staff member changing their own password
    // ---------------------------------------------------------------------------------------------

    // returns '' when the password was changed, otherwise the message to show
    // the page must call Session::establishIdentity() afterwards, so this session gets a login time after the change
    public function changeOwnPassword(int $accountId, string $currentPassword, string $newPassword, string $confirmation): string {
        $statement = $this->pdo->prepare('SELECT username, password_hash FROM users WHERE id = :id');
        $statement->execute(['id' => $accountId]);
        $account = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            throw new RuntimeException('The signed-in account no longer exists.');
        }
        // asking for the current password means someone who finds an unlocked, signed-in computer cannot take over the account
        if (strlen($currentPassword) > self::PASSWORD_MAX_BYTES || !password_verify($currentPassword, $account['password_hash'])) {
            return 'Your current password is not correct.';
        }
        $problem = self::newPasswordProblem($newPassword, $confirmation, $account['username']);
        if ($problem !== '') {
            return $problem;
        }
        if (hash_equals($currentPassword, $newPassword)) {
            return 'Choose a password that is different from your current one.';
        }
        $this->saveWithHistory(
            'UPDATE users SET password_hash = :password_hash, must_change_password = 0, password_changed_at = FROM_UNIXTIME(:now) WHERE id = :id',
            ['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'now' => time(), 'id' => $accountId],
            $accountId, 'account.password_changed', $accountId, ['password']
        );
        return '';
    }

    // the terminal command "emergency-access" (user's request, 2026-11-10): the last resort for a head administrator
    // who lost their password, their authenticator app AND their backup codes
    // it gives a new temporary password, ends every open session and switches two-step login off; at the next
    // login the person chooses a new password and is then made to link an authenticator app again
    // returns ['result' => 'reset', 'id' => ..., 'username' => ..., 'is_active' => ..., 'temporary_password' => ...]
    // or ['result' => 'not_found']; the audit entries have no actor (NULL), shown as "terminal"
    public function emergencyAccessFromTerminal(string $identifier): array {
        $statement = $this->pdo->prepare('SELECT id, username, is_active FROM users WHERE username = :username');
        $statement->execute(['username' => $identifier]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return ['result' => 'not_found'];
        }
        $accountId = (int) $user['id'];
        $twoFactor = new TwoFactor($this->pdo);
        $twoFactor->turnOff($accountId, null, 'account.emergency_access');
        $temporaryPassword = self::temporaryPassword();
        $this->saveWithHistory(
            'UPDATE users SET password_hash = :password_hash, must_change_password = 1, password_changed_at = FROM_UNIXTIME(:now) WHERE id = :id',
            ['password_hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT), 'now' => time(), 'id' => $accountId],
            null, 'account.password_reset', $accountId, ['password']
        );
        return [
            'result' => 'reset',
            'id' => $accountId,
            'username' => $user['username'],
            'is_active' => (int) $user['is_active'] === 1,
            'temporary_password' => $temporaryPassword,
        ];
    }

    // true when $password is this account's current password; used to confirm risky changes on the
    // Two-step login page, so someone at an unlocked, signed-in computer cannot change them
    public function passwordIsCorrect(int $accountId, string $password): bool {
        if ($password === '' || strlen($password) > self::PASSWORD_MAX_BYTES) {
            return false;
        }
        $statement = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $statement->execute(['id' => $accountId]);
        $hash = $statement->fetchColumn();
        return is_string($hash) && password_verify($password, $hash);
    }

    // ---------------------------------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------------------------------

    // true when another account (not $exceptId) already uses this username
    // the column's collation ignores capitals, so "Ana" and "ana" count as the same username
    private function usernameTaken(string $username, int $exceptId): bool {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username AND id <> :except_id');
        $statement->execute(['username' => $username, 'except_id' => $exceptId]);
        return (int) $statement->fetchColumn() > 0;
    }

    // runs one UPDATE and writes its audit entry in the same transaction
    // $actorId is NULL for a change made from the terminal
    private function saveWithHistory(string $sql, array $values, ?int $actorId, string $actionCode, int $accountId, array $changedFields): void {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($values);
            AuditLog::record($this->pdo, $actorId, $actionCode, 'user', $accountId, $changedFields);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack(); // undo the half-finished change before reporting the error
            throw $exception;
        }
    }
}
