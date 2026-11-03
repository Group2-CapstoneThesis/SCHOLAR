<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Auth;

use PDO;

// shared helpers keep the same safeguards on login, administrative pages, and logout
class Session {
    public static function start(): void {
        $configuration = loadConfiguration();
        $settings = $configuration['session'];
        session_name('scholar_session'); // separate cookie name for this application
        ini_set('session.use_strict_mode', '1'); // reject unknown identifiers supplied by clients
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0'); // never put session identifiers into URLs
        // file cleanup is storage housekeeping; evaluate() below enforces the actual expiry rules
        ini_set('session.gc_maxlifetime', (string) $settings['maximum_seconds']);
        session_save_path(__DIR__ . '/../../storage/sessions');
        $isHttps = false;
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $isHttps = true;
        }
        // httponly blocks javascript access (only on client-side), samesite set to lax helps against csrf attacks
        session_set_cookie_params([
            'lifetime' => 0, // cookie ends with the browser session; server timers are checked separately
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (!session_start()) {
            throw new \RuntimeException('Session could not be started.');
        }
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        // retired identifiers contain no identity; leave them anonymous rather than restoring access
        // do not expire their cookie here: a delayed request must not delete the newer browser cookie
        if (isset($_SESSION['retired_at'])) {
            unset($_SESSION['user_id'], $_SESSION['login_at'], $_SESSION['last_activity_at'], $_SESSION['renewed_at']);
            return;
        }
        if (isset($_SESSION['user_id'])) {
            if (!is_int($_SESSION['user_id']) || $_SESSION['user_id'] < 1) {
                self::expire();
            }
            $now = time();
            $policy = new SessionPolicy($settings['idle_seconds'], $settings['maximum_seconds'], $settings['renewal_seconds']);
            $decision = $policy->evaluate($_SESSION, $now);
            if ($decision === 'expired') {
                self::expire(); // stop this request before protected content or actions
            }
            if ($decision === 'renew') {
                // save only a marker in the old file before creating the replacement identifier
                // retaining an anonymous old file avoids immediately deleting a file another request uses
                $authenticatedData = $_SESSION;
                $_SESSION = ['retired_at' => $now];
                if (!session_regenerate_id(false)) {
                    throw new \RuntimeException('Session identifier could not be renewed.');
                }
                $_SESSION = $authenticatedData; // only the new identifier keeps authenticated access
                $_SESSION['renewed_at'] = $now;
                // keep the form token so forms already open in other tabs can still be submitted
            }
            // activity means an authenticated server request, not mouse movement or typing
            $_SESSION['last_activity_at'] = $now;
        }
    }

    // authentication calls this only after the credentials have been successfully verified
    public static function establishIdentity(int $userId): void {
        if ($userId < 1 || session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('An active session is required for login.');
        }
        // remove anonymous/previous state before rotation; no identity remains in the old file
        $_SESSION = [];
        if (!session_regenerate_id(true)) {
            throw new \RuntimeException('Session identifier could not be changed.');
        }
        $now = time();
        $_SESSION['user_id'] = $userId;
        $_SESSION['login_at'] = $now; // this fixed timestamp is never refreshed by activity or renewal
        $_SESSION['last_activity_at'] = $now;
        $_SESSION['renewed_at'] = $now;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    public static function csrfToken(): string {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf($token): bool {
        // modified forms may send arrays rather than strings; reject them before comparison
        if (!is_string($token) || !isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    // every protected page calls this before doing anything else
    // the PDO connection is needed because the account's status is read fresh from the database on each request
    // it returns the logged-in account as ['id' => ..., 'username' => ..., 'role_code' => ...] for the page to use
    public static function requireAuthentication(PDO $pdo): array {
        if (isset($_SESSION['retired_at']) || !isset($_SESSION['user_id']) || !is_int($_SESSION['user_id']) || $_SESSION['user_id'] < 1) {
            $destination = 'login.php';
            if (isset($_SESSION['retired_at'])) {
                $destination = 'login.php?expired=1';
            }
            header('Location: ' . $destination, true, 302);
            exit;
        }

        // the session only remembers WHO logged in, not whether that account is still allowed in,
        // so ask the database every time; this is what signs a staff member out on their next click after RDPO disables them
        // the role code is read in the same query so Access can check permissions without a second lookup
        $statement = $pdo->prepare("
            SELECT u.id, u.username, u.is_active, r.code AS role_code
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE u.id = :id
        ");
        $statement->execute(['id' => $_SESSION['user_id']]);
        $account = $statement->fetch(); // false when the account no longer exists

        if (!$account || (int) $account['is_active'] !== 1) {
            self::invalidate(); // remove the identity, the server session file and the browser cookie
            // a fixed message on the login page explains why; this person already proved who they are,
            // so telling them their own account is inactive does not reveal anything to a stranger
            header('Location: login.php?ended=1', true, 302);
            exit;
        }

        return [
            'id' => (int) $account['id'],
            'username' => $account['username'],
            'role_code' => $account['role_code'],
        ];
    }

    // one-time messages for the POST/redirect/GET pattern:
    // a page saves "Record saved." before redirecting, and the next page shows it once and forgets it
    // $isError = true shows the message in red instead of green, e.g. "PDF not uploaded: ..."
    public static function setFlash(string $message, bool $isError = false): void {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_is_error'] = $isError;
    }

    // returns the waiting message ('' when there is none); see takeFlashIsError() for its colour
    public static function takeFlash(): string {
        if (!isset($_SESSION['flash_message']) || !is_string($_SESSION['flash_message'])) {
            return '';
        }
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']); // remove it so a refresh does not show it again
        return $message;
    }

    // call right after takeFlash(); true when that message was saved as an error
    public static function takeFlashIsError(): bool {
        $isError = false;
        if (isset($_SESSION['flash_is_error']) && $_SESSION['flash_is_error'] === true) {
            $isError = true;
        }
        unset($_SESSION['flash_is_error']);
        return $isError;
    }

    private static function expire(): void {
        self::invalidate();
        header('Location: login.php?expired=1', true, 302);
        exit;
    }

    public static function invalidate(): void {
        $_SESSION = []; // remove the identity, timestamps, and form security token
        $cookieSettings = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $cookieSettings['path'],
            'secure' => $cookieSettings['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (!session_destroy()) {
            throw new \RuntimeException('Session could not be ended.');
        }
    }
}
