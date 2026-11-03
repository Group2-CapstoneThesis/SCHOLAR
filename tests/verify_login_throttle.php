<?php
// WHAT THIS FILE DOES: automatically checks the failed-login limits that slow down password guessing.
// Part A calls the LoginThrottle class directly with fixed times, so the exact limits can be checked without waiting.
// Part B sends real login requests to our local Apache like a browser, using made up account data.

// STEP 1: Allow terminal use only, load the application, and connect to the development database.
// STEP 2 (Part A): Check that unsafe limit settings are refused and sensible ones are accepted.
// STEP 3 (Part A): Record failures for one fictional username and check exactly when the account limit blocks it.
// STEP 4 (Part A): Check the window boundary: one second before it ends the block remains, at its end the oldest failure stops counting.
// STEP 5 (Part A): Check that a successful login clears the account counter, and that "ABC" and "abc" share one counter.
// STEP 6 (Part A): Spread failures over many usernames from one fictional address and check the address limit.
// STEP 7 (Part A): Check that old rows are removed by the housekeeping step.
// STEP 8 (Part B): Create a temporary fictional administrator and enter the wrong password until the limit is reached.
// STEP 9 (Part B): Enter the CORRECT password while blocked; it must still be refused, and must not extend the block.
// STEP 10 (Part B): Repeat the wrong guesses for an unknown username; it must be blocked the same way, so the limit cannot reveal which accounts exist.
// STEP 11 (Part B): Age the temporary account's failures past the window, then log in successfully and check the counter was cleared.
// STEP 12: In cleanup, remove only this script's temporary account and the failed-login rows this script created.

// Part A uses addresses from 192.0.2.0/24, a range reserved for documentation that no real visitor uses.
// Part A's fixed times are 30 days in the past, so its housekeeping can only remove rows that are already expired.
// Each check prints PASS; a failed check stops the remaining checks and reports failure.
// The terminal exit code is 0 for success or 1 for a caught test failure.

declare(strict_types=1); // reject incorrect types passed into the test helpers

use Scholar\Auth\LoginThrottle;

// run this script from the terminal; it is not a browser page
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

// print the action before performing it; static remembers the number between calls
function showStep(string $action): void {
    static $stepNumber = 0;
    $stepNumber++;
    echo "\nSTEPS $stepNumber: $action\n";
}

// report each expected result; throwing stops the test if a requirement fails
function check(bool $condition, string $description): void {
    if (!$condition) {
        throw new RuntimeException($description);
    }
    echo "PASS: $description\n";
}

// cURL is PHP's HTTP client; this helper sends one request to our existing Apache
// form is either an array of submitted fields or null for a normal GET request
function requestPage(CurlHandle $client, string $baseUrl, string $page, $form = null): array {
    $isPost = false;
    if ($form !== null) {
        $isPost = true;
    }
    $options = [
        CURLOPT_URL => $baseUrl . $page,
        CURLOPT_POST => $isPost,
        CURLOPT_RETURNTRANSFER => true, // return the response instead of printing page HTML
        CURLOPT_HEADER => true, // include headers so redirects/cookies can be inspected
        CURLOPT_FOLLOWLOCATION => false, // check the original redirect before following it
        CURLOPT_TIMEOUT => 10,
        CURLOPT_PROXY => '', // send local test requests directly to Apache
    ];
    curl_setopt_array($client, $options);
    if ($form !== null) {
        curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($form)); // encode fields like a browser form
    }
    $response = curl_exec($client);
    if ($response === false) {
        throw new RuntimeException('HTTP request failed.');
    }
    $headerLength = curl_getinfo($client, CURLINFO_HEADER_SIZE);
    // separate the status, headers and HTML body so each check has named values to read
    return [
        'status' => curl_getinfo($client, CURLINFO_HTTP_CODE),
        'headers' => substr($response, 0, $headerLength),
        'body' => substr($response, $headerLength),
    ];
}

// find the hidden CSRF input in the returned HTML, just as a browser form would use it
function tokenFrom(array $response): string {
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $response['body'], $matches)) {
        throw new RuntimeException('CSRF token missing.');
    }
    return $matches[1];
}

// open a fresh login form and submit the given credentials with its CSRF token
function submitLogin(CurlHandle $client, string $baseUrl, string $identifier, string $password): array {
    $login = requestPage($client, $baseUrl, 'login.php');
    return requestPage($client, $baseUrl, 'login.php', [
        'identifier' => $identifier,
        'password' => $password,
        'csrf_token' => tokenFrom($login),
    ]);
}

// returns true when the given settings are refused by LoginThrottle::checkSettings()
function settingsRejected(int $accountMax, int $addressMax, int $windowSeconds): bool {
    try {
        LoginThrottle::checkSettings($accountMax, $addressMax, $windowSeconds);
    } catch (InvalidArgumentException $exception) {
        return true;
    }
    return false;
}

// count saved failed-login rows for one username/email, straight from the database
function countAccountRows(PDO $pdo, string $identifier): int {
    $statement = $pdo->prepare('SELECT COUNT(*) FROM failed_logins WHERE account_key = :account_key');
    $statement->execute(['account_key' => LoginThrottle::accountKey($identifier)]);
    return (int) $statement->fetchColumn();
}

// use our existing Laragon Apache; an alternative local URL can be the first argument
$baseUrl = 'http://scholar.localhost';
if (isset($argv[1])) {
    $baseUrl = $argv[1];
}
$baseUrl = rtrim($baseUrl, '/') . '/';
$pdo = connectDatabase();
$limits = loadConfiguration()['login']; // Part B uses the real limits from .env, whatever they are set to

// every identifier and address this script uses is remembered here so cleanup can remove exactly those rows
$prefix = 'verify_throttle_' . bin2hex(random_bytes(6));
$usedIdentifiers = [];
$usedAddresses = [];
$username = $prefix;
$email = $username . '@example.invalid';
$password = bin2hex(random_bytes(20)); // never print or commit the temporary password
$usedIdentifiers[] = $username;
$usedIdentifiers[] = $email;
$client = curl_init();
curl_setopt($client, CURLOPT_COOKIEFILE, ''); // remember cookies like a browser
curl_setopt($client, CURLOPT_RESOLVE, ['scholar.localhost:80:127.0.0.1']);
$failed = false;
try {
    // ---------------- Part A: the LoginThrottle class with fixed times ----------------

    showStep('Check that unsafe limit settings are refused.');
    check(settingsRejected(0, 5, 600), 'An account limit of 0 is refused');
    check(settingsRejected(5, 4, 600), 'An address limit lower than the account limit is refused');
    check(settingsRejected(5, 20, 59), 'A window shorter than one minute is refused');
    check(!settingsRejected(3, 5, 600), 'Sensible settings are accepted');

    // small limits make the boundaries easy to see: 3 per account, 5 per address, 10-minute window
    $throttle = new LoginThrottle($pdo, 3, 5, 600);
    $startTime = time() - 30 * 86400; // 30 days ago; see the note at the top of this file
    $unitAccount = $prefix . '_unit';
    $usedIdentifiers[] = $unitAccount;
    $addressA = '192.0.2.' . random_int(1, 127);
    $addressB = '192.0.2.' . random_int(128, 254);
    $usedAddresses[] = $addressA;
    $usedAddresses[] = $addressB;

    showStep('Record failures for one fictional username and find where the account limit blocks it.');
    check(!$throttle->isBlocked($unitAccount, $addressA, $startTime), 'A username with no failures is not blocked');
    $throttle->recordFailure($unitAccount, $addressA, $startTime);
    $throttle->recordFailure($unitAccount, $addressA, $startTime + 1);
    check(!$throttle->isBlocked($unitAccount, $addressA, $startTime + 2), 'Two failures stay under a limit of three');
    $throttle->recordFailure($unitAccount, $addressA, $startTime + 2);
    check($throttle->isBlocked($unitAccount, $addressA, $startTime + 3), 'The third failure reaches the limit and blocks the username');

    showStep('Check the window boundary for the oldest failure.');
    // at +599 the first failure (at +0) is still inside the 600-second window
    check($throttle->isBlocked($unitAccount, $addressA, $startTime + 599), 'Still blocked one second before the oldest failure expires');
    // at +600 the first failure falls out, leaving two, which is under the limit
    check(!$throttle->isBlocked($unitAccount, $addressA, $startTime + 600), 'Unblocked once the oldest failure is a full window old');

    showStep('Clear the counter after success, and check that letter case shares one counter.');
    $throttle->clearAccount($unitAccount);
    check(!$throttle->isBlocked($unitAccount, $addressA, $startTime + 3), 'A successful login clears the username counter');
    check(LoginThrottle::accountKey(strtoupper($unitAccount)) === LoginThrottle::accountKey($unitAccount), 'Upper and lower case produce the same counter key');
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $throttle->recordFailure(strtoupper($unitAccount), $addressB, $startTime + 4 + $attempt);
    }
    check($throttle->isBlocked($unitAccount, $addressB, $startTime + 8), 'Failures typed in capitals block the lowercase username too');
    $throttle->clearAccount($unitAccount);

    showStep('Spread failures over many usernames from one fictional address.');
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $sprayedName = $prefix . '_spray' . $attempt;
        $usedIdentifiers[] = $sprayedName;
        $throttle->recordFailure($sprayedName, $addressA, $startTime + 10 + $attempt); // one try each, under every account limit
    }
    $newName = $prefix . '_fresh';
    $usedIdentifiers[] = $newName;
    check($throttle->isBlocked($newName, $addressA, $startTime + 20), 'A brand-new username is blocked from the address that reached its limit');
    check(!$throttle->isBlocked($newName, $addressB, $startTime + 20), 'The same username from a different address is not blocked');

    showStep('Check that housekeeping removes rows older than the window.');
    $addressRows = $pdo->prepare('SELECT COUNT(*) FROM failed_logins WHERE address_key = :address_key');
    $addressRows->execute(['address_key' => LoginThrottle::addressKey($addressA)]);
    $rowsBefore = (int) $addressRows->fetchColumn();
    // recording a failure at +1000 removes every row at or before +400, which is all of address A's earlier rows
    $throttle->recordFailure($newName, $addressA, $startTime + 1000);
    $addressRows->execute(['address_key' => LoginThrottle::addressKey($addressA)]);
    $rowsAfter = (int) $addressRows->fetchColumn();
    check($rowsBefore >= 5 && $rowsAfter === 1, 'Expired rows removed; only the newest failure remains');

    // ---------------- Part B: the real login page through Apache ----------------

    echo "\nPREPARATION: Create this script's temporary account for the HTTP checks.\n";
    // Part B makes two rounds of wrong guesses from 127.0.0.1, so the address limit must leave room for both
    check($limits['address_max_failures'] > 2 * $limits['account_max_failures'], 'Configured address limit leaves room for the HTTP checks');
    putenv('SCHOLAR_ADMIN_PASSWORD=' . $password);
    // proc_open starts a child PHP command; the three pipes are its input/output/error streams
    $process = proc_open([PHP_BINARY, __DIR__ . '/../bin/console.php', 'provision-admin', $username, $email],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]); // no keyboard input is needed for this command
    stream_get_contents($pipes[1]); // consume normal output without exposing it
    stream_get_contents($pipes[2]); // also consume error output without printing credentials
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0, 'CLI provisions temporary administrator');
    putenv('SCHOLAR_ADMIN_PASSWORD');

    showStep('Enter the wrong password until the configured account limit is reached.');
    $allRejected = true;
    for ($attempt = 1; $attempt <= $limits['account_max_failures']; $attempt++) {
        $response = submitLogin($client, $baseUrl, $username, 'incorrect-' . $attempt);
        if ($response['status'] !== 401) {
            $allRejected = false;
        }
    }
    check($allRejected, 'Each wrong password before the limit gets the normal generic error');

    showStep('Enter the correct password while the account is blocked.');
    $rowsBeforeBlockedAttempt = countAccountRows($pdo, $username);
    $response = submitLogin($client, $baseUrl, $username, $password);
    check($response['status'] === 429
        && strpos($response['body'], 'Too many login attempts.') !== false, 'Correct password is refused while blocked');
    check(requestPage($client, $baseUrl, 'admin.php')['status'] === 302, 'Blocked login grants no protected access');
    check(countAccountRows($pdo, $username) === $rowsBeforeBlockedAttempt, 'Blocked attempts are not recorded, so they do not extend the block');

    showStep('Repeat the wrong guesses for a username that does not exist.');
    $unknownName = 'unknown_' . $prefix;
    $usedIdentifiers[] = $unknownName;
    for ($attempt = 1; $attempt <= $limits['account_max_failures']; $attempt++) {
        submitLogin($client, $baseUrl, $unknownName, 'incorrect-' . $attempt);
    }
    $response = submitLogin($client, $baseUrl, $unknownName, 'incorrect-again');
    check($response['status'] === 429
        && strpos($response['body'], 'Too many login attempts.') !== false, 'Unknown usernames are blocked exactly like real ones');

    showStep('Age the account failures past the window, then log in successfully.');
    // move only this account's rows back in time by one full window, like the session suite ages its own timestamps
    $statement = $pdo->prepare('UPDATE failed_logins SET attempted_at = attempted_at - :window WHERE account_key = :account_key');
    $statement->execute(['window' => $limits['window_seconds'], 'account_key' => LoginThrottle::accountKey($username)]);
    $response = submitLogin($client, $baseUrl, $username, $password);
    check($response['status'] === 303, 'Login works again once the failures have expired');
    check(countAccountRows($pdo, $username) === 0, 'Successful login cleared the account counter');
    $admin = requestPage($client, $baseUrl, 'admin.php');
    check($admin['status'] === 200, 'Protected page opens after the block ends');
    $response = requestPage($client, $baseUrl, 'logout.php', ['csrf_token' => tokenFrom($admin)]);
    check($response['status'] === 303, 'Logout succeeds');
} catch (Throwable $exception) {
    $failed = true;
    if ($exception instanceof PDOException) {
        reportFailure($exception); // keep unexpected database details in the private error handler
    } else {
        fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    }
} finally {
    // always remove only this script's exact synthetic account and rows; preserve everything else
    echo "\nCLEANUP: Remove this script's temporary account and the failed-login rows it created.\n";
    putenv('SCHOLAR_ADMIN_PASSWORD');
    // audit history rows point at the account (login/logout events), so remove this account's rows first;
    // otherwise the foreign key would refuse to delete the account
    $statement = $pdo->prepare("
        DELETE FROM audit_logs
        WHERE actor_user_id IN (SELECT id FROM users WHERE username = :actor_username)
           OR (entity_type = 'user' AND entity_id IN (SELECT id FROM users WHERE username = :entity_username))
    ");
    $statement->execute(['actor_username' => $username, 'entity_username' => $username]);
    $statement = $pdo->prepare('DELETE FROM users WHERE username = :username AND email = :email');
    $statement->execute(['username' => $username, 'email' => $email]);
    $statement = $pdo->prepare('DELETE FROM failed_logins WHERE account_key = :account_key');
    foreach ($usedIdentifiers as $usedIdentifier) {
        $statement->execute(['account_key' => LoginThrottle::accountKey($usedIdentifier)]);
    }
    // the 192.0.2.x addresses are fictional, so every row with those address keys belongs to this script
    $statement = $pdo->prepare('DELETE FROM failed_logins WHERE address_key = :address_key');
    foreach ($usedAddresses as $usedAddress) {
        $statement->execute(['address_key' => LoginThrottle::addressKey($usedAddress)]);
    }
    unset($client); // PHP releases CurlHandle objects when no references remain
}
if ($failed) {
    exit(1); // terminal exit code 1 means the suite failed
}
echo "\nRESULT: Failed-login limit tests passed.\n";
exit(0);
