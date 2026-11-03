<?php
// WHAT THIS FILE DOES: automatically checks what happens when RDPO disables and re-enables an account.
// It uses the real terminal commands and sends requests to our local Apache like a browser, using made up account data.

// STEP 1: Allow terminal use only, load the application, and connect to the development database.
// STEP 2: Check that the new account-status columns exist, then create a temporary fictional administrator.
// STEP 3: Log in normally and open the protected administrative page.
// STEP 4: Disable the account through the terminal command while that browser session is still open.
// STEP 5: Request the administrative page again with the same session; it must sign the person out with a fixed explanation.
// STEP 6: Replay the old session cookie from a separate HTTP client; it must not reach the protected page.
// STEP 7: Log in with the CORRECT password while disabled; it must show the same generic error as a wrong password.
// STEP 8: Disable the account a second time; nothing changes and the original disabled time is kept.
// STEP 9: Re-enable the account and log in again with the same password, then log out.
// STEP 10: Ask the terminal command to disable an account that does not exist; it must report failure.
// STEP 11: In cleanup, remove only this script's temporary account and its failed-login records.

// Each check prints PASS; a failed check stops the remaining checks and reports failure.
// Cleanup is attempted in finally even when a check fails after preparation starts.
// The terminal exit code is 0 for success or 1 for a caught test failure.
// The small HTTP/check helpers are copied from the other suites on purpose so this file runs on its own.

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

// look up the session ID held by our HTTP client so the old cookie can be replayed later
function sessionCookie(CurlHandle $client): string {
    $cookies = curl_getinfo($client, CURLINFO_COOKIELIST);
    foreach ($cookies as $cookie) {
        $parts = explode("\t", $cookie); // cURL stores cookie details separated by tabs
        if ($parts[5] === 'scholar_session') {
            return $parts[6];
        }
    }
    return '';
}

// run one bin/console.php command as a child process and return its exit code and printed text
// these account commands never print passwords, so their output is safe to read in the checks
function runConsole(array $arguments): array {
    $command = array_merge([PHP_BINARY, __DIR__ . '/../bin/console.php'], $arguments);
    // proc_open starts a child PHP command; the three pipes are its input/output/error streams
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]); // no keyboard input is needed for these commands
    $output = stream_get_contents($pipes[1]);
    $errorOutput = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return ['exit' => $exitCode, 'output' => $output . $errorOutput];
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

// read the saved status columns for the temporary account straight from the database
function accountRow(PDO $pdo, string $username): array {
    $statement = $pdo->prepare('SELECT is_active, disabled_at FROM users WHERE username = :username');
    $statement->execute(['username' => $username]);
    $row = $statement->fetch();
    if (!$row) {
        throw new RuntimeException('Temporary account missing.');
    }
    return $row;
}

// use our existing Laragon Apache; an alternative local URL can be the first argument
$baseUrl = 'http://scholar.localhost';
if (isset($argv[1])) {
    $baseUrl = $argv[1];
}
$baseUrl = rtrim($baseUrl, '/') . '/';
$pdo = connectDatabase();
// a separate synthetic account prefix so this script never touches another suite's account
$username = 'verify_status_' . bin2hex(random_bytes(6));
$email = $username . '@example.invalid';
$password = bin2hex(random_bytes(20)); // never print or commit the temporary password
$client = curl_init();
curl_setopt($client, CURLOPT_COOKIEFILE, ''); // remember cookies like a browser
curl_setopt($client, CURLOPT_RESOLVE, ['scholar.localhost:80:127.0.0.1']);
$failed = false;
try {
    echo "PREPARATION: Check the account-status columns and create this script's temporary account.\n";
    // SHOW COLUMNS returns one row per matching column name; zero rows means the migration has not been applied
    $columnCount = count($pdo->query("SHOW COLUMNS FROM users WHERE Field IN ('is_active', 'disabled_at')")->fetchAll());
    check($columnCount === 2, 'Account-status migration applied (is_active and disabled_at exist)');

    // invoke the real provisioning command, passing the password through the environment
    putenv('SCHOLAR_ADMIN_PASSWORD=' . $password);
    $result = runConsole(['provision-admin', $username, $email]);
    putenv('SCHOLAR_ADMIN_PASSWORD');
    check($result['exit'] === 0, 'CLI provisions temporary administrator');
    $row = accountRow($pdo, $username);
    check((int) $row['is_active'] === 1 && $row['disabled_at'] === null, 'New accounts start active with no disabled time');

    showStep('Log in normally and open the protected administrative page.');
    $response = submitLogin($client, $baseUrl, $username, $password);
    check($response['status'] === 303, 'Active account logs in');
    $admin = requestPage($client, $baseUrl, 'admin.php');
    check($admin['status'] === 200 && strpos($admin['body'], 'RDPO thesis records') !== false, 'Protected page opens while active');
    $openSession = sessionCookie($client); // saved internally for the replay step; never printed

    showStep('Disable the account through the terminal while its session is still open.');
    $result = runConsole(['disable-account', $username]);
    check($result['exit'] === 0 && strpos($result['output'], 'Account disabled.') !== false, 'disable-account reports success');
    $disabledRow = accountRow($pdo, $username);
    check((int) $disabledRow['is_active'] === 0 && $disabledRow['disabled_at'] !== null, 'Database marks the account disabled with a time');

    showStep('Request the administrative page again using the same open session.');
    $response = requestPage($client, $baseUrl, 'admin.php');
    check($response['status'] === 302 && strpos($response['body'], 'RDPO thesis records') === false, 'Disabled account loses access on its next request');
    check(strpos($response['headers'], 'Location: login.php?ended=1') !== false, 'Redirect points to the fixed account-ended message');
    $loginPage = requestPage($client, $baseUrl, 'login.php?ended=1');
    check(strpos($loginPage['body'], 'this account is no longer active') !== false, 'Login page explains that the session ended');

    showStep('Replay the old session cookie from a separate HTTP client.');
    $replay = curl_init();
    try {
        curl_setopt($replay, CURLOPT_COOKIE, 'scholar_session=' . $openSession);
        curl_setopt($replay, CURLOPT_RESOLVE, ['scholar.localhost:80:127.0.0.1']);
        $response = requestPage($replay, $baseUrl, 'admin.php');
        check($response['status'] === 302 && strpos($response['body'], 'RDPO thesis records') === false, 'Old session cookie of a disabled account is useless');
    } finally {
        unset($replay); // PHP releases the handle object without deprecated curl_close
    }

    showStep('Log in with the correct password while the account is disabled.');
    $response = submitLogin($client, $baseUrl, $username, $password);
    check($response['status'] === 401
        && strpos($response['body'], 'Invalid username/email or password.') !== false, 'Disabled account gets the same generic error as a wrong password');
    check(requestPage($client, $baseUrl, 'admin.php')['status'] === 302, 'Rejected disabled login grants no protected access');

    showStep('Disable the already-disabled account again.');
    $result = runConsole(['disable-account', $email]); // email works as well as username
    check($result['exit'] === 0 && strpos($result['output'], 'already disabled') !== false, 'Second disable reports nothing changed');
    $secondRow = accountRow($pdo, $username);
    check($secondRow['disabled_at'] === $disabledRow['disabled_at'], 'Original disabled time is kept');

    showStep('Re-enable the account, log in with the same password, then log out.');
    $result = runConsole(['enable-account', $username]);
    check($result['exit'] === 0 && strpos($result['output'], 'Account enabled.') !== false, 'enable-account reports success');
    $enabledRow = accountRow($pdo, $username);
    check((int) $enabledRow['is_active'] === 1 && $enabledRow['disabled_at'] === null, 'Database marks the account active again');
    $response = submitLogin($client, $baseUrl, $username, $password);
    check($response['status'] === 303, 'Re-enabled account logs in with its existing password');
    $admin = requestPage($client, $baseUrl, 'admin.php');
    check($admin['status'] === 200, 'Protected page opens again after re-enabling');
    $response = requestPage($client, $baseUrl, 'logout.php', ['csrf_token' => tokenFrom($admin)]);
    check($response['status'] === 303, 'Logout still works for the re-enabled account');

    showStep('Ask the terminal to disable an account that does not exist.');
    $result = runConsole(['disable-account', 'missing_' . $username]);
    check($result['exit'] === 1 && strpos($result['output'], 'No account matches') !== false, 'Unknown account reported as a failure');
} catch (Throwable $exception) {
    $failed = true;
    if ($exception instanceof PDOException) {
        reportFailure($exception); // keep unexpected database details in the private error handler
    } else {
        fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    }
} finally {
    // always remove only this script's exact synthetic account; preserve the browser-test administrator
    echo "\nCLEANUP: Remove this script's temporary account and its failed-login records.\n";
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
    // the disabled-login step counted as a failed attempt; remove it so 127.0.0.1's address limit stays clear
    $statement = $pdo->prepare('DELETE FROM failed_logins WHERE account_key = :account_key');
    foreach ([$username, $email] as $usedIdentifier) {
        $statement->execute(['account_key' => LoginThrottle::accountKey($usedIdentifier)]);
    }
    unset($client); // PHP releases CurlHandle objects when no references remain
}
if ($failed) {
    exit(1); // terminal exit code 1 means the suite failed
}
echo "\nRESULT: Account-status tests passed.\n";
exit(0);
