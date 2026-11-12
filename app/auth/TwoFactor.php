<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Auth;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use OTPHP\TOTP;
use PDO;
use RuntimeException;
use Scholar\AuditLog;

// two-step login (user's request, 2026-11-10)
// step 1 is the password (Authentication); step 2 is a 6-digit code from an authenticator app such as 2FAS or
// Google Authenticator, which makes a new code every 30 seconds from a secret shared once through a QR code
// - head administrators MUST turn it on; for RDPO administrators it is optional
// - head administrators also get 10 single-use backup codes for when the phone is lost or broken
// - "remember this device for 30 days" skips step 2 on that one browser, for that one account
// - the terminal command "emergency-access" is the last resort when everything else is lost (see bin/console.php)
class TwoFactor {
    public const ISSUER = 'S.C.H.O.L.A.R. WCC'; // the name the authenticator app shows above the code
    public const BACKUP_CODE_COUNT = 10;
    public const REMEMBER_DAYS = 30;
    public const DEVICE_COOKIE = 'scholar_device'; // the "remember this device" cookie
    private const MAX_REMEMBERED_TOKENS = 5; // how many accounts one browser can remember at the same time

    // letters and digits for backup codes, without look-alikes such as 0/o, 1/l/i (same as temporary passwords)
    private const BACKUP_CODE_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    private PDO $pdo;
    private string $keyPath;

    // $keyPath is only changed by tests; normally the key lives in the private storage folder
    public function __construct(PDO $pdo, string $keyPath = '') {
        $this->pdo = $pdo;
        if ($keyPath === '') {
            $keyPath = __DIR__ . '/../../storage/two_factor.key';
        }
        $this->keyPath = $keyPath;
    }

    // ---------------------------------------------------------------------------------------------
    // rules by role
    // ---------------------------------------------------------------------------------------------

    // true when this role must have two-step login before it can use any RDPO page
    public static function isRequiredFor(string $roleCode): bool {
        return $roleCode === 'rdpo_head';
    }

    // true when this role gets backup codes (the user asked for them for head administrators)
    // an RDPO administrator who loses their phone asks a head administrator to reset their two-step login instead
    public static function usesBackupCodes(string $roleCode): bool {
        return $roleCode === 'rdpo_head';
    }

    // ---------------------------------------------------------------------------------------------
    // the authenticator app secret and its codes
    // ---------------------------------------------------------------------------------------------

    // a new random secret in the base32 letters authenticator apps expect, e.g. "JBSWY3DPEHPK3PXP..."
    // 20 random bytes (160 bits, the size the TOTP standard recommends) = 32 letters: a QR code that scans easily
    // from a screen, and a key short enough to type by hand (the library's own default of 64 bytes gives 103 letters)
    public const SECRET_BYTES = 20;

    public static function newSecret(): string {
        return TOTP::generate(null, self::SECRET_BYTES)->getSecret();
    }

    // the "otpauth://" address inside the QR code: the secret plus the names the app shows, e.g.
    // "S.C.H.O.L.A.R. WCC (local_admin)"
    public static function setupAddress(string $secret, string $username): string {
        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($username);
        $totp->setIssuer(self::ISSUER);
        return $totp->getProvisioningUri();
    }

    // the QR code as SVG markup to put straight into the page (an SVG needs no image file and no GD library)
    public static function qrCodeSvg(string $address): string {
        $builder = new Builder(writer: new SvgWriter(), data: $address, size: 240, margin: 8);
        $result = $builder->build();
        $svg = $result->getString();
        // drop the XML declaration on the first line; it is only needed when the SVG is a file of its own
        return preg_replace('/\A<\?xml[^>]*\?>\s*/', '', $svg);
    }

    // the secret in groups of four for typing by hand, e.g. "JBSW Y3DP EHPK ..."
    public static function readableSecret(string $secret): string {
        return trim(chunk_split($secret, 4, ' '));
    }

    // keeps only the digits of a typed code, so "123 456" and "123-456" both work
    public static function cleanAppCode(string $typed): string {
        return preg_replace('/[^0-9]/', '', $typed);
    }

    // checks a typed 6-digit code against the secret at the time $now
    // returns the number of the 30-second window that matched, or null when the code is wrong
    // the window before and after are also accepted, because a phone clock can be a little off
    // $lastStep is the last window already used: a code from that window (or an older one) is refused,
    // so a code someone saw over your shoulder cannot be used again
    public static function matchingStep(string $secret, string $typedCode, int $now, ?int $lastStep): ?int {
        $code = self::cleanAppCode($typedCode);
        if (strlen($code) !== 6) {
            return null;
        }
        $totp = TOTP::createFromSecret($secret);
        $period = $totp->getPeriod(); // 30 seconds
        $currentStep = intdiv($now, $period);
        foreach ([$currentStep - 1, $currentStep, $currentStep + 1] as $step) {
            if ($lastStep !== null && $step <= $lastStep) {
                continue; // already used
            }
            // hash_equals compares in constant time, so the comparison does not leak how many digits were right
            if (hash_equals($totp->at($step * $period), $code)) {
                return $step;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------------------------------------
    // reading the account's state
    // ---------------------------------------------------------------------------------------------

    public function isEnabled(int $userId): bool {
        $statement = $this->pdo->prepare('SELECT totp_enabled_at IS NOT NULL FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);
        return (int) $statement->fetchColumn() === 1;
    }

    // ['enabled_at' => ..., 'backup_codes_left' => ..., 'backup_codes_total' => ..., 'remembered_devices' => ...]
    public function summary(int $userId): array {
        $statement = $this->pdo->prepare('SELECT totp_enabled_at FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);
        $enabledAt = $statement->fetchColumn();

        $statement = $this->pdo->prepare('SELECT COUNT(*) AS total, SUM(used_at IS NULL) AS unused FROM user_backup_codes WHERE user_id = :id');
        $statement->execute(['id' => $userId]);
        $codes = $statement->fetch(PDO::FETCH_ASSOC);

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM user_remembered_devices WHERE user_id = :id AND expires_at > NOW()');
        $statement->execute(['id' => $userId]);

        return [
            'enabled_at' => $enabledAt ?: null,
            'backup_codes_total' => (int) $codes['total'],
            'backup_codes_left' => (int) $codes['unused'],
            'remembered_devices' => (int) $statement->fetchColumn(),
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // turning it on and off
    // ---------------------------------------------------------------------------------------------

    // finishes linking the app: the person typed a code that matches the secret shown in the QR code,
    // which proves the app really has the secret before anything is switched on
    // returns true when two-step login is now on; false when the code was wrong
    public function confirmSetup(int $userId, string $secret, string $typedCode): bool {
        $step = self::matchingStep($secret, $typedCode, time(), null);
        if ($step === null) {
            return false;
        }
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('
                UPDATE users SET totp_secret_encrypted = :secret, totp_enabled_at = NOW(), totp_last_step = :step WHERE id = :id
            ');
            $statement->execute(['secret' => $this->encrypt($secret), 'step' => $step, 'id' => $userId]);
            // a newly linked app starts clean: no browser is remembered from before
            $this->deleteRows('user_remembered_devices', $userId);
            AuditLog::record($this->pdo, $userId, 'account.two_factor_enabled', 'user', $userId);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
        return true;
    }

    // switches two-step login off and forgets the secret, the backup codes and the remembered browsers
    // $actorId is who did it (NULL for the terminal); $actionCode says why, e.g. 'account.two_factor_disabled'
    public function turnOff(int $userId, ?int $actorId, string $actionCode): void {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('
                UPDATE users SET totp_secret_encrypted = NULL, totp_enabled_at = NULL, totp_last_step = NULL WHERE id = :id
            ');
            $statement->execute(['id' => $userId]);
            $this->deleteRows('user_backup_codes', $userId);
            $this->deleteRows('user_remembered_devices', $userId);
            AuditLog::record($this->pdo, $actorId, $actionCode, 'user', $userId);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    // ---------------------------------------------------------------------------------------------
    // step 2 of logging in
    // ---------------------------------------------------------------------------------------------

    // true when the typed authenticator code is right; the window is then marked as used
    public function verifyAppCode(int $userId, string $typedCode): bool {
        $statement = $this->pdo->prepare('SELECT totp_secret_encrypted, totp_last_step FROM users WHERE id = :id AND totp_enabled_at IS NOT NULL');
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $lastStep = null;
        if ($row['totp_last_step'] !== null) {
            $lastStep = (int) $row['totp_last_step'];
        }
        $secret = $this->decrypt($row['totp_secret_encrypted']);
        if ($secret === '') {
            return false; // the key file was replaced, so this secret can no longer be read
        }
        $step = self::matchingStep($secret, $typedCode, time(), $lastStep);
        if ($step === null) {
            return false;
        }
        // "totp_last_step < :step" makes two requests with the same code at the same moment accept only one
        $statement = $this->pdo->prepare('
            UPDATE users SET totp_last_step = :step WHERE id = :id AND (totp_last_step IS NULL OR totp_last_step < :step_again)
        ');
        $statement->execute(['step' => $step, 'step_again' => $step, 'id' => $userId]);
        return $statement->rowCount() === 1;
    }

    // ---------------------------------------------------------------------------------------------
    // backup codes
    // ---------------------------------------------------------------------------------------------

    // one new backup code such as "k7mqx-3pahr" (10 random characters from 31, about 49 bits)
    // random_int() uses the operating system's secure random source, the same as the temporary passwords;
    // the codes are shorter than a temporary password because ten of them have to be written down by hand
    public static function newBackupCode(): string {
        $alphabet = self::BACKUP_CODE_ALPHABET;
        $characters = '';
        for ($position = 0; $position < 10; $position++) {
            $characters .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return substr($characters, 0, 5) . '-' . substr($characters, 5);
    }

    // a typed backup code in its stored form: lowercase, without spaces or dashes ("K7MQX 3PAHR" -> "k7mqx3pahr")
    public static function cleanBackupCode(string $typed): string {
        return preg_replace('/[^a-z0-9]/', '', strtolower($typed));
    }

    // replaces every backup code of the account with 10 new ones and returns them so they can be shown ONCE
    // only password hashes are saved, so nobody can read the codes back from the database later
    public function newBackupCodes(int $userId): array {
        $codes = [];
        for ($count = 0; $count < self::BACKUP_CODE_COUNT; $count++) {
            $codes[] = self::newBackupCode();
        }
        $this->pdo->beginTransaction();
        try {
            $this->deleteRows('user_backup_codes', $userId); // the old set stops working
            $statement = $this->pdo->prepare('INSERT INTO user_backup_codes (user_id, code_hash) VALUES (:user_id, :code_hash)');
            foreach ($codes as $code) {
                $statement->execute(['user_id' => $userId, 'code_hash' => password_hash(self::cleanBackupCode($code), PASSWORD_DEFAULT)]);
            }
            AuditLog::record($this->pdo, $userId, 'account.backup_codes_generated', 'user', $userId);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
        return $codes;
    }

    // true when the typed code is one of the account's unused backup codes; that code is then used up for good
    public function useBackupCode(int $userId, string $typedCode): bool {
        $code = self::cleanBackupCode($typedCode);
        if (strlen($code) !== 10) {
            return false;
        }
        $statement = $this->pdo->prepare('SELECT id, code_hash FROM user_backup_codes WHERE user_id = :id AND used_at IS NULL');
        $statement->execute(['id' => $userId]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (password_verify($code, $row['code_hash'])) {
                // "used_at IS NULL" again, so the same code sent twice at the same moment works only once
                $update = $this->pdo->prepare('UPDATE user_backup_codes SET used_at = NOW() WHERE id = :id AND used_at IS NULL');
                $update->execute(['id' => $row['id']]);
                return $update->rowCount() === 1;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------------------------------------
    // "remember this device for 30 days"
    // the browser keeps a long random token in a cookie; the database keeps only its SHA-256 hash, the account
    // and the end date, so a copied database cannot be turned into working cookies
    // ---------------------------------------------------------------------------------------------

    // true when this browser's cookie holds a token that was remembered for this account and has not expired
    public function isRememberedDevice(int $userId): bool {
        foreach (self::cookieTokens() as $token) {
            $statement = $this->pdo->prepare('
                SELECT id FROM user_remembered_devices WHERE user_id = :user_id AND token_hash = :token_hash AND expires_at > NOW()
            ');
            $statement->execute(['user_id' => $userId, 'token_hash' => hash('sha256', $token)]);
            $deviceId = $statement->fetchColumn();
            if ($deviceId !== false) {
                $update = $this->pdo->prepare('UPDATE user_remembered_devices SET last_used_at = NOW() WHERE id = :id');
                $update->execute(['id' => $deviceId]);
                return true;
            }
        }
        return false;
    }

    // remembers this browser for this account for 30 days and sends the cookie
    public function rememberThisDevice(int $userId): void {
        $token = bin2hex(random_bytes(32)); // 64 hex characters, impossible to guess
        $label = '';
        if (isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])) {
            $label = mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 120); // only to tell the devices apart later
        }
        // housekeeping: expired rows no longer let anyone in, so remove them
        $this->pdo->exec('DELETE FROM user_remembered_devices WHERE expires_at <= NOW()');
        $statement = $this->pdo->prepare('
            INSERT INTO user_remembered_devices (user_id, token_hash, device_label, expires_at)
            VALUES (:user_id, :token_hash, :label, NOW() + INTERVAL ' . self::REMEMBER_DAYS . ' DAY)
        ');
        $statement->execute(['user_id' => $userId, 'token_hash' => hash('sha256', $token), 'label' => $label]);

        // keep the tokens of other accounts on this browser, newest last, at most 5
        $tokens = self::cookieTokens();
        $tokens[] = $token;
        $tokens = array_slice($tokens, -self::MAX_REMEMBERED_TOKENS);
        $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie(self::DEVICE_COOKIE, implode('.', $tokens), [
            'expires' => time() + self::REMEMBER_DAYS * 86400,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true, // javascript on the page cannot read it
            'samesite' => 'Lax',
        ]);
    }

    // forgets every remembered browser of this account, e.g. after a lost laptop
    public function forgetDevices(int $userId, int $actorId): void {
        $this->deleteRows('user_remembered_devices', $userId);
        AuditLog::record($this->pdo, $actorId, 'account.remembered_devices_forgotten', 'user', $userId);
    }

    // the tokens in this browser's cookie; anything that does not look like a token is ignored
    private static function cookieTokens(): array {
        if (!isset($_COOKIE[self::DEVICE_COOKIE]) || !is_string($_COOKIE[self::DEVICE_COOKIE])) {
            return [];
        }
        $tokens = [];
        foreach (explode('.', $_COOKIE[self::DEVICE_COOKIE]) as $token) {
            if (preg_match('/\A[0-9a-f]{64}\z/', $token)) {
                $tokens[] = $token;
            }
        }
        return array_slice($tokens, -self::MAX_REMEMBERED_TOKENS);
    }

    // ---------------------------------------------------------------------------------------------
    // encrypting the secret
    // the key is a random file in the private storage folder (created the first time it is needed). it is NOT in
    // the database and NOT in backups, so a stolen backup cannot produce codes. if the key file is lost, the
    // linked apps stop working and each person links again (head administrators through "emergency-access")
    // ---------------------------------------------------------------------------------------------

    public function encrypt(string $secret): string {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); // a fresh random number for every encryption
        return $nonce . sodium_crypto_secretbox($secret, $nonce, $this->key());
    }

    public function decrypt(string $encrypted): string {
        $nonce = substr($encrypted, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipherText = substr($encrypted, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $secret = sodium_crypto_secretbox_open($cipherText, $nonce, $this->key());
        if ($secret === false) {
            // wrong or replaced key file: no code can match, so step 2 simply fails instead of crashing
            return '';
        }
        return $secret;
    }

    private function key(): string {
        if (!is_file($this->keyPath)) {
            $key = sodium_crypto_secretbox_keygen();
            // 'x' creates the file only if it does not exist yet, so two requests at once cannot overwrite each other
            $file = @fopen($this->keyPath, 'x');
            if ($file !== false) {
                fwrite($file, base64_encode($key));
                fclose($file);
            }
        }
        $key = base64_decode((string) file_get_contents($this->keyPath), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('The 2FA key file is damaged.');
        }
        return $key;
    }

    // $table is only ever one of the fixed table names in this class, never text from a request
    private function deleteRows(string $table, int $userId): void {
        $statement = $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE user_id = :id');
        $statement->execute(['id' => $userId]);
    }
}
