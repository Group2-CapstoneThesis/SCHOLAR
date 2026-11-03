<?php
// WHAT THIS FILE DOES: automatically checks the normal login and logout process in the perspective of a user.
// It sends requests to our local Apache instance like a browser, but does not open a browser window.

// STEP 1: Allow terminal use only, load the application, and connect to the development database.
// STEP 2: Check that all nine migrations are recorded and the sample thesis has two ordered authors.
// STEP 3: Create a temporary fictional administrator through our administrator creation command.
// STEP 4: Check that its saved password is a hash and that the original password verifies correctly.
// STEP 5: Open the login page through the local Apache and check that it loads.
// STEP 6: Submit an incorrect password and check that a general login error appears.
// STEP 7: Open a fresh login form and submit the correct username and password.
// STEP 8: Check the login redirect, changed session identifier, and basic session cookie settings.
// STEP 9: Open the protected administrative page and check for the login success message.
// STEP 10: Submit the logout form, then check that the administrative page is no longer accessible.
// STEP 11: Repeat steps 7 to 10 using the account's email instead of its username.
// STEP 12: In cleanup, remove only this script's temporary account, its failed-login records, and release the HTTP client.

// Each check prints PASS; a failed check stops the remaining checks and reports failure.
// Cleanup is attempted in finally even when a check fails after preparation starts.
// The terminal exit code is 0 for success or 1 for a caught test failure.
// The numbered STEPS printed in the terminal describe the page actions, not this whole overview.

declare(strict_types=1); // reject incorrect types passed into the test helpers

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

// look up the session ID held by our HTTP client to check rotation and logout
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

// use our existing Laragon Apache; an alternative local URL can be the first argument
$baseUrl = 'http://scholar.localhost';
if (isset($argv[1])) {
    $baseUrl = $argv[1];
}
$baseUrl = rtrim($baseUrl, '/') . '/';
$pdo = connectDatabase();
// replace verify_user_ with a separate synthetic account prefix for this script
$username = 'verify_user_' . bin2hex(random_bytes(6));
$email = $username . '@example.invalid';
$password = bin2hex(random_bytes(20)); // never print or commit the temporary password
$client = curl_init();
curl_setopt($client, CURLOPT_COOKIEFILE, ''); // remember cookies like a browser
curl_setopt($client, CURLOPT_RESOLVE, ['scholar.localhost:80:127.0.0.1']);
$failed = false;
try {
    echo "PREPARATION: Check the development database and create this script's temporary account.\n";
    // these are backend setup checks, not thesis screens or user-facing repository actions
    // four foundation migrations plus five from 2026-10-19 (account status, failed logins, thesis fields, audit log, thesis files)
    check((int) $pdo->query('SELECT COUNT(*) FROM phinxlog')->fetchColumn() === 9, 'All nine Phinx migrations recorded');
    $rows = $pdo->query(
        "SELECT t.title, a.author_name, a.author_order FROM theses t
         JOIN thesis_authors a ON a.thesis_id = t.id
         WHERE t.fixture_key = 'synthetic_multi_author' ORDER BY a.author_order"
    )->fetchAll();
    // the two sample author names come from database/seeds/SyntheticFoundation.php
    check(count($rows) === 2 && $rows[0]['author_name'] === 'Maria Clara Santos'
        && $rows[1]['author_name'] === 'Jose Miguel Reyes', 'Sample thesis reads with two ordered authors');

    // invoke the real provisioning command, passing the password through the environment
    putenv('SCHOLAR_ADMIN_PASSWORD=' . $password);
    // proc_open starts a child PHP command; the three pipes are its input/output/error streams
    $process = proc_open([PHP_BINARY, __DIR__ . '/../bin/console.php', 'provision-admin', $username, $email],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]); // no keyboard input is needed for this command
    stream_get_contents($pipes[1]); // consume normal output without exposing it
    stream_get_contents($pipes[2]); // also consume error output without printing credentials
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0, 'CLI provisions temporary administrator without committed password');
    putenv('SCHOLAR_ADMIN_PASSWORD');
    $lookup = $pdo->prepare('SELECT password_hash FROM users WHERE username = :username');
    $lookup->execute(['username' => $username]);
    $hash = $lookup->fetchColumn();
    check(is_string($hash) && $hash !== $password && password_verify($password, $hash), 'Password API hash verifies');

    // ordinary actions are sent as HTTP requests; this simulates a user without opening a graphical browser
    showStep('Open the login page.');
    $login = requestPage($client, $baseUrl, 'login.php');
    check($login['status'] === 200, 'Login page opens through Laragon Apache');

    showStep('Enter an incorrect password and submit the login form.');
    $response = requestPage($client, $baseUrl, 'login.php', [
        'identifier' => $username,
        'password' => 'incorrect',
        'csrf_token' => tokenFrom($login),
    ]);
    check($response['status'] === 401
        && strpos($response['body'], 'Invalid username/email or password.') !== false, 'Incorrect password shows a generic login error');

    // complete the normal login/logout journey with each supported identifier
    foreach ([$username, $email] as $identifier) {
        $identifierType = 'username';
        if ($identifier === $email) {
            $identifierType = 'email';
        }

        showStep('Open the login form before signing in with ' . $identifierType . '.');
        $login = requestPage($client, $baseUrl, 'login.php');
        check($login['status'] === 200, 'Login form available');
        $previousSession = sessionCookie($client);

        showStep('Submit the correct ' . $identifierType . ' and password.');
        $response = requestPage($client, $baseUrl, 'login.php', [
            'identifier' => $identifier,
            'password' => $password,
            'csrf_token' => tokenFrom($login),
        ]);
        check($response['status'] === 303, 'Successful ' . $identifierType . ' login redirects');
        // inspect the security details of this successful user action without exposing the session ID
        check($previousSession !== '' && sessionCookie($client) !== $previousSession, 'Successful login regenerates session identifier');
        check(strpos($response['headers'], 'HttpOnly') !== false
            && strpos($response['headers'], 'SameSite=Lax') !== false, 'Session cookie safeguards present');

        showStep('Follow the redirect to the protected administrative page.');
        $admin = requestPage($client, $baseUrl, 'admin.php');
        check($admin['status'] === 200 && strpos($admin['body'], 'RDPO thesis records') !== false, 'Authenticated administrative page opens');

        showStep('Submit the logout form.');
        $response = requestPage($client, $baseUrl, 'logout.php', ['csrf_token' => tokenFrom($admin)]);
        check($response['status'] === 303, 'Logout succeeds');

        showStep('Revisit the administrative page after logging out.');
        $response = requestPage($client, $baseUrl, 'admin.php');
        check($response['status'] === 302 && strpos($response['body'], 'RDPO thesis records') === false, 'Logout ends authenticated access');
    }
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
    // the deliberate wrong-password step can leave a failed-login row; remove it so repeated test runs
    // do not slowly fill up the per-address limit for 127.0.0.1 and block real local logins
    $statement = $pdo->prepare('DELETE FROM failed_logins WHERE account_key = :account_key');
    foreach ([$username, $email] as $usedIdentifier) {
        $statement->execute(['account_key' => Scholar\Auth\LoginThrottle::accountKey($usedIdentifier)]);
    }
    unset($client); // PHP releases CurlHandle objects when no references remain
}
if ($failed) {
    exit(1); // terminal exit code 1 means the suite failed
}
echo "\nRESULT: User-flow tests passed.\n";
exit(0);
