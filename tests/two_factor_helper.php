<?php
// WHAT THIS FILE DOES: shared helpers for the HTTP test suites whose made-up accounts are head administrators,
// who must use two-step login (user's request, 2026-11-10). It is not a test on its own.
// STEP 1: linkTestAuthenticator() links a made-up authenticator app to a test account, straight through TwoFactor.
// STEP 2: testAppCode() makes the 6-digit code that app would show right now.
// STEP 3: answerCodeStep() types that code on login_code.php when the login page asked for it.
// The suites that include it already define requestPage() and tokenFrom(); this file uses theirs.
declare(strict_types=1);

use OTPHP\TOTP;
use Scholar\Auth\TwoFactor;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// links a new made-up app to the account and returns its secret (kept in memory only, never printed)
function linkTestAuthenticator(PDO $pdo, int $userId): string {
    $secret = TwoFactor::newSecret();
    $twoFactor = new TwoFactor($pdo);
    if (!$twoFactor->confirmSetup($userId, $secret, testAppCode($secret))) {
        throw new RuntimeException('Test authenticator could not be linked.');
    }
    return $secret;
}

// the code the app would show now; TOTP::now() is the library's own code maker
function testAppCode(string $secret): string {
    return TOTP::createFromSecret($secret)->now();
}

// when $loginResponse sent the browser to login_code.php, types the current app code there and returns that answer;
// otherwise returns $loginResponse unchanged
// a code is accepted only once per 30 seconds, so the "last used" mark is cleared first: test suites log the same
// account in several times within a few seconds, which a real person never does
function answerCodeStep(CurlHandle $client, string $baseUrl, array $loginResponse, PDO $pdo, int $userId, string $secret): array {
    if (strpos($loginResponse['headers'], 'Location: login_code.php') === false) {
        return $loginResponse;
    }
    $statement = $pdo->prepare('UPDATE users SET totp_last_step = NULL WHERE id = :id');
    $statement->execute(['id' => $userId]);
    $codePage = requestPage($client, $baseUrl, 'login_code.php');
    return requestPage($client, $baseUrl, 'login_code.php', [
        'code' => testAppCode($secret),
        'method' => 'app',
        'csrf_token' => tokenFrom($codePage),
    ]);
}

// removes everything two-step login stored for the test accounts (run in each suite's cleanup, before the accounts go)
function forgetTestTwoFactor(PDO $pdo, array $userIds): void {
    foreach ($userIds as $userId) {
        foreach (['user_backup_codes', 'user_remembered_devices'] as $table) {
            $statement = $pdo->prepare('DELETE FROM ' . $table . ' WHERE user_id = :id');
            $statement->execute(['id' => $userId]);
        }
    }
}
