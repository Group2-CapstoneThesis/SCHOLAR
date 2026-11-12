<?php
declare(strict_types=1); // require the declared argument types

namespace Scholar\Auth;

use PDO;
use Scholar\AuditLog;

// checks the credentials and establishes the logged-in identity
class Authentication {
    private PDO $pdo;
    private LoginThrottle $throttle;

    public function __construct(PDO $pdo, LoginThrottle $throttle) {
        $this->pdo = $pdo;
        $this->throttle = $throttle; // receive the failed-login counter instead of creating it here
    }

    // returns one of three named results so the login page can show the right message:
    // 'logged_in'          the password matched an active account and the session now holds the identity
    // 'rejected'           wrong password, unknown account, or disabled account (all look the same on purpose)
    // 'too_many_attempts'  the failed-login limit was reached, so the password was not even checked
    // 'needs_code'         the password was right, but two-step login is on: the login page sends the person to
    //                      login_code.php for the authenticator code (user's request, 2026-11-10)
    // $address is the visitor's IP address, used only for the address limit in LoginThrottle
    public function login(string $identifier, string $password, string $address): string {
        $now = time();

        // check the limits first; while blocked, even the correct password is refused
        if ($this->throttle->isBlocked($identifier, $address, $now)) {
            return 'too_many_attempts';
        }

        // the account is found by its username (emails were removed on 2026-11-10)
        $statement = $this->pdo->prepare("
            SELECT id, password_hash, is_active
            FROM users
            WHERE username = :username
        ");
        $statement->execute(['username' => $identifier]);
        $user = $statement->fetch();

        // random dummy password hash that is not tied to any account
        // this is for preventing timing attacks by providing a default hash so that verification has the same computational cost and timing regardless if the user exists or not
        // therefore, attackers will not be able to guess which users exist based on computational timing so that they can target a specific username then use password attacking methods
        $passwordHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        if ($user) {
            $passwordHash = $user['password_hash'];
        }

        // the password is verified even for a disabled account, so a disabled account takes the same time as any other attempt
        $passwordMatches = password_verify($password, $passwordHash);

        // MySQL returns the TINYINT column as text or a number depending on the driver, so compare it as an integer
        $accountIsActive = false;
        if ($user && (int) $user['is_active'] === 1) {
            $accountIsActive = true;
        }

        // a disabled account gets exactly the same answer as a wrong password,
        // so someone guessing cannot tell "disabled" apart from "wrong password" or "no such account"
        if (!$user || !$passwordMatches || !$accountIsActive) {
            $this->throttle->recordFailure($identifier, $address, $now); // count this mistake toward the limits
            return 'rejected';
        }

        $userId = (int) $user['id'];

        // two-step login: with an app linked, the password alone is not enough, unless this browser was
        // remembered for this account in the last 30 days
        // the failed-login counter is NOT cleared yet, so wrong codes keep counting toward the same limit
        $twoFactor = new TwoFactor($this->pdo);
        if ($twoFactor->isEnabled($userId) && !$twoFactor->isRememberedDevice($userId)) {
            Session::beginTwoFactor($userId, $identifier);
            return 'needs_code';
        }

        $this->finishLogin($userId, $identifier);
        return 'logged_in';
    }

    // the last part of every successful login: after the password alone, or after the password and the code
    public function finishLogin(int $userId, string $identifier): void {
        $this->throttle->clearAccount($identifier); // a correct login forgives this account's earlier mistakes

        // central session setup also starts the inactivity, lifetime, and renewal timers
        Session::establishIdentity($userId);
        // only successful logins go into the audit history; failed ones are already counted by LoginThrottle
        AuditLog::record($this->pdo, $userId, 'auth.login', 'user', $userId);
    }
}
