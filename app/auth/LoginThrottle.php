<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Auth;

use PDO;
use InvalidArgumentException;

// slows down password guessing by counting recent failed logins
// two limits are checked: one for the typed username/email, and a higher one for the visitor's IP address
// the account limit stops someone from guessing one account's password over and over
// the address limit stops one computer from trying a few passwords on many different usernames
class LoginThrottle {
    private PDO $pdo;
    private int $accountMaxFailures;
    private int $addressMaxFailures;
    private int $windowSeconds;

    public function __construct(PDO $pdo, int $accountMaxFailures, int $addressMaxFailures, int $windowSeconds) {
        self::checkSettings($accountMaxFailures, $addressMaxFailures, $windowSeconds);
        $this->pdo = $pdo;
        $this->accountMaxFailures = $accountMaxFailures;
        $this->addressMaxFailures = $addressMaxFailures;
        $this->windowSeconds = $windowSeconds;
    }

    // Configuration also calls this, so bad .env values are caught before any login is attempted
    public static function checkSettings(int $accountMaxFailures, int $addressMaxFailures, int $windowSeconds): void {
        // the bounds keep the limit meaningful: 0 would block everyone, a huge number would block nobody
        // the address limit cannot be lower than the account limit, otherwise one account could never reach its own limit
        // the window is between 1 minute and 1 day
        if ($accountMaxFailures < 1 || $accountMaxFailures > 100
            || $addressMaxFailures < $accountMaxFailures || $addressMaxFailures > 1000
            || $windowSeconds < 60 || $windowSeconds > 86400) {
            throw new InvalidArgumentException('Invalid login throttle configuration.');
        }
    }

    // turns the typed username/email into a fixed-length fingerprint for the failed_logins table
    // lowercase first, because "Admin" and "admin" log into the same account and must share one counter
    // example: "Local_Admin" and "local_admin" both become the same 64-character hash
    public static function accountKey(string $identifier): string {
        $normalizedIdentifier = mb_strtolower($identifier, 'UTF-8');
        return hash('sha256', 'account:' . $normalizedIdentifier);
    }

    // same idea for the visitor's IP address; the "address:" prefix keeps the two kinds of keys from ever matching
    public static function addressKey(string $address): string {
        return hash('sha256', 'address:' . $address);
    }

    // returns true when either limit has been reached inside the current window
    // this runs BEFORE the password is checked, so a blocked attacker learns nothing even with the right password
    // unknown usernames are counted the same way as real ones, so this cannot be used to discover which accounts exist
    public function isBlocked(string $identifier, string $address, int $now): bool {
        // only attempts newer than this moment count; older ones have "expired" out of the window
        $windowStart = $now - $this->windowSeconds;

        $accountFailures = $this->countFailures('account_key', self::accountKey($identifier), $windowStart);
        if ($accountFailures >= $this->accountMaxFailures) {
            return true;
        }

        $addressFailures = $this->countFailures('address_key', self::addressKey($address), $windowStart);
        if ($addressFailures >= $this->addressMaxFailures) {
            return true;
        }

        return false;
    }

    // saves one failed attempt; Authentication calls this for wrong passwords, unknown accounts and disabled accounts alike
    public function recordFailure(string $identifier, string $address, int $now): void {
        $statement = $this->pdo->prepare("
            INSERT INTO failed_logins (account_key, address_key, attempted_at)
            VALUES (:account_key, :address_key, :attempted_at)
        ");
        $statement->execute([
            'account_key' => self::accountKey($identifier),
            'address_key' => self::addressKey($address),
            'attempted_at' => $now,
        ]);

        // housekeeping: rows outside the window no longer affect any decision, so remove them to keep the table small
        $statement = $this->pdo->prepare('DELETE FROM failed_logins WHERE attempted_at <= :window_start');
        $statement->execute(['window_start' => $now - $this->windowSeconds]);
    }

    // after a successful login, the account's earlier mistakes are forgiven
    // the address counter is left alone so one successful login cannot reset a spraying attack from the same computer
    public function clearAccount(string $identifier): void {
        $statement = $this->pdo->prepare('DELETE FROM failed_logins WHERE account_key = :account_key');
        $statement->execute(['account_key' => self::accountKey($identifier)]);
    }

    // shared counting query for both limits
    // $column is only ever one of the two fixed names below, never text from a request,
    // because a column name cannot be sent as a prepared value
    private function countFailures(string $column, string $key, int $windowStart): int {
        if ($column !== 'account_key' && $column !== 'address_key') {
            throw new InvalidArgumentException('Unknown failed-login column.');
        }
        $statement = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM failed_logins
            WHERE $column = :key AND attempted_at > :window_start
        ");
        $statement->execute(['key' => $key, 'window_start' => $windowStart]);
        return (int) $statement->fetchColumn();
    }
}
