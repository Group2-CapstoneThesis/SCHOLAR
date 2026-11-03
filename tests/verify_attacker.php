<?php
// WHAT THIS FILE DOES: automatically checks rejected requests and basic security safeguards.
// It sends test requests only to our local development application using made up account data.

// STEP 1: Allow terminal use only, load the application, and connect to the development database.
// STEP 2: Try adding an author without an existing thesis, check rejection, and roll back the attempt. This uses our test database connection; it does not mean an attacker gained database access.
// STEP 3: Create a temporary fictional administrator and check its saved password hash.
// STEP 4: Request the administrative page without logging in and check that access is denied.
// STEP 5: Open the login form and read its hidden security token (CSRF token).
// STEP 6: Submit valid credentials without that token and check that login is rejected.
// STEP 7: Submit lists instead of normal username/password text and check that input is rejected.
// STEP 8: Compare incorrect logins for existing and unknown accounts; both must show a general error.
// STEP 9: Submit SQL attack text as the username and check that it cannot bypass login.
// STEP 10: Submit script text as the username and check that the returned HTML escapes it safely. This inspects page source; it does not run JavaScript in a graphical browser.
// STEP 11: Submit a password of 73 ordinary letters and check that it is rejected as too long.
// STEP 12: Log in correctly to prepare the logout checks; this is not a successful login bypass.
// STEP 13: Try logout with a direct GET request and with a wrong security token; both must be rejected. After the wrong-token attempt, check that the legitimate user is still logged in.
// STEP 14: Log out correctly, then try the old session cookie in a separate HTTP client. Check that neither the logged-out client nor the old cookie can access the admin page.
// STEP 15: Repeat steps 12 to 14 using email instead of username.
// STEP 16: Request private configuration, library, application, log, and credential-file paths. Check that both the default Apache address and application URL refuse to serve them.
// STEP 17: In cleanup, remove only this script's temporary account, the failed-login records its attacks created, and release the HTTP client.

// Each check prints PASS; a failed check stops the remaining checks and reports failure.
// Cleanup is attempted in finally even when a check fails after preparation starts.
// The terminal exit code is 0 for success or 1 for a caught test failure.
// The numbered STEPS printed in the terminal describe the HTTP actions, not this whole overview.

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
// replace verify_attacker_ with a separate synthetic account prefix for this script
$username = 'verify_attacker_' . bin2hex(random_bytes(6));
$email = $username . '@example.invalid';
$password = bin2hex(random_bytes(20)); // never print or commit the temporary password
$client = curl_init();
curl_setopt($client, CURLOPT_COOKIEFILE, ''); // remember cookies like a browser
curl_setopt($client, CURLOPT_RESOLVE, ['scholar.localhost:80:127.0.0.1']);
$failed = false;
try {
    echo "PREPARATION: Check the development database and create this script's temporary account.\n";
    // direct database integrity check using our test connection
    // roll back the attempt whether the constraint works or fails
    $pdo->beginTransaction();
    $orphanRejected = false;
    try {
        $pdo->exec("INSERT INTO thesis_authors (thesis_id, author_name, author_order) VALUES (18446744073709551615, 'test', 1)");
    } catch (PDOException $exception) {
        $orphanRejected = $exception->getCode() === '23000';
    } finally {
        $pdo->rollBack();
    }
    check($orphanRejected, 'Backend integrity check: foreign key rejects orphan author');

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

    // every attack below targets our local development app using fictional account data
    showStep('Request admin.php directly without authenticating.');
    $response = requestPage($client, $baseUrl, 'admin.php');
    check($response['status'] === 302 && strpos($response['body'], 'RDPO thesis records') === false, 'Unauthenticated direct admin request denied');

    showStep('Fetch the public login form to obtain a session and CSRF token.');
    $login = requestPage($client, $baseUrl, 'login.php');
    check($login['status'] === 200, 'Public login form available');
    $token = tokenFrom($login);

    showStep('Submit valid credentials while omitting the login CSRF token.');
    $response = requestPage($client, $baseUrl, 'login.php', ['identifier' => $username, 'password' => $password]);
    check($response['status'] === 403, 'Login without CSRF token rejected');

    showStep('Submit arrays instead of ordinary identifier and password strings.');
    $response = requestPage($client, $baseUrl, 'login.php', ['identifier' => ['bad'], 'password' => ['bad'], 'csrf_token' => $token]);
    check($response['status'] === 422, 'Array-shaped login input rejected safely');

    showStep('Compare failed logins for an existing account and an unknown account.');
    $wrong = requestPage($client, $baseUrl, 'login.php', ['identifier' => $username, 'password' => 'incorrect', 'csrf_token' => $token]);
    $unknown = requestPage($client, $baseUrl, 'login.php', ['identifier' => 'unknown_' . $username, 'password' => 'incorrect', 'csrf_token' => $token]);
    check($wrong['status'] === 401 && $unknown['status'] === 401
        && strpos($wrong['body'], 'Invalid username/email or password.') !== false
        && strpos($unknown['body'], 'Invalid username/email or password.') !== false, 'Known and unknown accounts use the same generic credential error');

    showStep('Submit a SQL injection string as the login identifier.');
    $response = requestPage($client, $baseUrl, 'login.php', ['identifier' => "' OR 1=1 --", 'password' => $password, 'csrf_token' => $token]);
    check($response['status'] === 401, 'SQL injection identifier cannot authenticate');
    check(requestPage($client, $baseUrl, 'admin.php')['status'] === 302, 'Rejected login attempts do not grant protected access');

    showStep('Submit script markup as the retained login identifier.');
    $response = requestPage($client, $baseUrl, 'login.php', ['identifier' => '<script>alert(1)</script>', 'password' => '', 'csrf_token' => $token]);
    check(strpos($response['body'], '&lt;script&gt;') !== false
        && strpos($response['body'], '<script>') === false, 'Retained identifier escaped instead of returned as a script element');

    showStep('Submit a password exceeding the supported 72-byte maximum.');
    $response = requestPage($client, $baseUrl, 'login.php', ['identifier' => $username, 'password' => str_repeat('a', 73), 'csrf_token' => $token]);
    check($response['status'] === 422, 'Overlong password rejected before bcrypt verification');

    // a legitimate test login is preparation for attacks against an authenticated session, not a successful bypass
    foreach ([$username, $email] as $identifier) {
        $identifierType = 'username';
        if ($identifier === $email) {
            $identifierType = 'email';
        }
        showStep('Sign in legitimately using the synthetic ' . $identifierType . ' to prepare logout/session attack tests.');
        $login = requestPage($client, $baseUrl, 'login.php');
        $response = requestPage($client, $baseUrl, 'login.php', [
            'identifier' => $identifier,
            'password' => $password,
            'csrf_token' => tokenFrom($login),
        ]);
        check($response['status'] === 303, 'Synthetic authenticated session prepared');
        $admin = requestPage($client, $baseUrl, 'admin.php');
        check($admin['status'] === 200, 'Authenticated page available for logout test preparation');

        showStep('Attempt to log the user out through a GET request.');
        $response = requestPage($client, $baseUrl, 'logout.php');
        check($response['status'] === 405, 'GET logout rejected');

        showStep('Attempt logout using an invalid CSRF token.');
        $response = requestPage($client, $baseUrl, 'logout.php', ['csrf_token' => 'invalid']);
        check($response['status'] === 403, 'Logout with invalid CSRF token rejected');
        check(requestPage($client, $baseUrl, 'admin.php')['status'] === 200, 'Rejected logout attempts preserve authenticated access');

        showStep('Log out legitimately to prepare an old-session replay attempt.');
        $oldSession = sessionCookie($client); // save internally; never print the session value
        check($oldSession !== '', 'Session cookie available for replay test');
        $response = requestPage($client, $baseUrl, 'logout.php', ['csrf_token' => tokenFrom($admin)]);
        check($response['status'] === 303, 'Legitimate logout completed');
        check(requestPage($client, $baseUrl, 'admin.php')['status'] === 302, 'Logged-out client loses access');

        showStep('Replay the old logged-out session cookie from a separate HTTP client.');
        $replay = curl_init();
        try {
            curl_setopt($replay, CURLOPT_COOKIE, 'scholar_session=' . $oldSession);
            curl_setopt($replay, CURLOPT_RESOLVE, ['scholar.localhost:80:127.0.0.1']);
            $response = requestPage($replay, $baseUrl, 'admin.php');
            check($response['status'] === 302 && strpos($response['body'], 'RDPO thesis records') === false, 'Logged-out session cannot be replayed');
        } finally {
            unset($replay); // PHP releases the handle object without deprecated curl_close
        }
    }

    // derive the localhost folder path from this checkout, rather than assuming the old XAMPP directory
    $defaultRootUrl = 'http://127.0.0.1/' . rawurlencode(basename(dirname(__DIR__, 2))) . '/';
    $privatePaths = ['.env', 'config/local.php', 'vendor/autoload.php', 'phinx.php', 'app/bootstrap.php', 'storage/logs/application.log', 'storage/local-browser-login.txt'];
    foreach ($privatePaths as $path) {
        showStep('Attempt to fetch private ' . $path . ' through the default Apache root and application URL.');
        $response = requestPage($client, $defaultRootUrl . 'scholar/', $path);
        check($response['status'] === 403, 'Default Apache root denies private ' . $path);
        $response = requestPage($client, $baseUrl, $path);
        check(in_array($response['status'], [403, 404], true), 'Public app route cannot serve ' . $path);
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
    // the wrong-password, unknown-account and SQL injection steps each leave a failed-login row;
    // remove exactly those rows so repeated runs do not fill up the per-address limit for 127.0.0.1
    $statement = $pdo->prepare('DELETE FROM failed_logins WHERE account_key = :account_key');
    foreach ([$username, $email, 'unknown_' . $username, "' OR 1=1 --"] as $usedIdentifier) {
        $statement->execute(['account_key' => Scholar\Auth\LoginThrottle::accountKey($usedIdentifier)]);
    }
    unset($client); // PHP releases CurlHandle objects when no references remain
}
if ($failed) {
    exit(1); // terminal exit code 1 means the suite failed
}
echo "\nRESULT: Attacker-flow tests passed.\n";
exit(0);
