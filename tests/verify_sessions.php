<?php
// WHAT THIS FILE DOES: checks session expiry and renewal through our existing local Apache.
// STEP 1: Create a fictional temporary account and log in normally.
// STEP 2: Check saved login/activity/renewal timestamps and normal protected access.
// STEP 3: Age only this test account's private session timestamps; no timer settings are changed.
// STEP 4: Request the protected page and check inactivity expiry, feedback, and old-cookie rejection.
// STEP 5: Repeat for maximum lifetime while keeping the recent-activity timestamp fresh.
// STEP 6: Force renewal due; check changed ID, continued access, and denial of the retired ID.
// STEP 7: Submit the pre-renewal logout token; forms must remain usable across renewal.
// STEP 8: Remove required timestamps and check that the old/incomplete session is rejected.
// STEP 9: Test expiry on a logout POST, then remove only this test's account and captured session files.
// These are integration/system checks using synthetic data, not UAT or a full security audit.
// Run only against the local development application; cookies/passwords are never printed.
declare(strict_types=1);
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

// the child command edits only the session belonging to this exact temporary user
// -r runs PHP code directly; arguments are passed as an array, without a shell command
function adjustSessionTimes(string $sessionId, int $userId, int $loginAt, int $activityAt, int $renewedAt, bool $removeTimes = false): void {
    if (!preg_match('/\A[a-zA-Z0-9,-]{16,128}\z/', $sessionId)) {
        throw new RuntimeException('Unexpected test session identifier.');
    }
    $code = <<<'PHP'
require $argv[1];
session_name('scholar_session');
session_save_path(dirname($argv[1]) . '/../storage/sessions');
ini_set('session.use_strict_mode', '1');
session_id($argv[2]);
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] !== (int) $argv[3]) { exit(2); }
if ($argv[7] === 'remove') {
    unset($_SESSION['login_at'], $_SESSION['last_activity_at'], $_SESSION['renewed_at']);
} else {
    $_SESSION['login_at'] = (int) $argv[4];
    $_SESSION['last_activity_at'] = (int) $argv[5];
    $_SESSION['renewed_at'] = (int) $argv[6];
}
session_write_close();
PHP;
    $mode = 'set';
    if ($removeTimes) {
        $mode = 'remove';
    }
    $process = proc_open([PHP_BINARY, '-r', $code, __DIR__ . '/../app/bootstrap.php', $sessionId,
        (string) $userId, (string) $loginAt, (string) $activityAt, (string) $renewedAt, $mode],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0, 'Only the temporary account session timestamps were adjusted');
}

function signInForSessionTest(CurlHandle $client, string $baseUrl, string $username, string $password): array {
    $login = requestPage($client, $baseUrl, 'login.php');
    check($login['status'] === 200, 'Login form available');
    $response = requestPage($client, $baseUrl, 'login.php', [
        'identifier' => $username, 'password' => $password, 'csrf_token' => tokenFrom($login),
    ]);
    check($response['status'] === 303, 'Temporary account signed in');
    $admin = requestPage($client, $baseUrl, 'admin.php');
    check($admin['status'] === 200, 'Unexpired session opens the administrative page');
    return $admin;
}

function checkOldSessionDenied(string $oldSession, string $baseUrl): void {
    $replay = curl_init();
    try {
        curl_setopt($replay, CURLOPT_COOKIE, 'scholar_session=' . $oldSession);
        curl_setopt($replay, CURLOPT_RESOLVE, ['scholar.localhost:80:127.0.0.1']);
        $response = requestPage($replay, $baseUrl, 'admin.php');
        check($response['status'] === 302 && strpos($response['body'], 'RDPO thesis records') === false,
            'Old session cookie does not restore protected access');
    } finally {
        unset($replay);
    }
}

$baseUrl = 'http://scholar.localhost/';
if (isset($argv[1])) {
    $baseUrl = rtrim($argv[1], '/') . '/';
}
$configuration = loadConfiguration();
$settings = $configuration['session'];
$pdo = connectDatabase();
$username = 'verify_sessions_' . bin2hex(random_bytes(6));
$email = $username . '@example.invalid';
$password = bin2hex(random_bytes(20));
$sessionIds = [];
$client = curl_init();
curl_setopt($client, CURLOPT_COOKIEFILE, '');
curl_setopt($client, CURLOPT_RESOLVE, ['scholar.localhost:80:127.0.0.1']);
$failed = false;
try {
    putenv('SCHOLAR_ADMIN_PASSWORD=' . $password);
    $process = proc_open([PHP_BINARY, __DIR__ . '/../bin/console.php', 'provision-admin', $username, $email],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0, 'Temporary session-test administrator created');
    putenv('SCHOLAR_ADMIN_PASSWORD');
    $lookup = $pdo->prepare('SELECT id FROM users WHERE username = :username AND email = :email');
    $lookup->execute(['username' => $username, 'email' => $email]);
    $userId = (int) $lookup->fetchColumn();

    foreach (['idle', 'maximum', 'missing', 'logout'] as $scenario) {
        showStep('Prepare a normal login for the ' . $scenario . ' expiry check.');
        $admin = signInForSessionTest($client, $baseUrl, $username, $password);
        $sessionId = sessionCookie($client);
        $sessionIds[] = $sessionId;
        // verify login really saved the timestamps instead of just assuming their presence
        $saved = file_get_contents(__DIR__ . '/../storage/sessions/sess_' . $sessionId);
        check(strpos($saved, 'login_at|i:') !== false && strpos($saved, 'last_activity_at|i:') !== false
            && strpos($saved, 'renewed_at|i:') !== false, 'Successful login records all three timestamps');
        $now = time();
        $loginAt = $now - $settings['idle_seconds'] - 10;
        $activityAt = $now - $settings['idle_seconds'] - 1;
        if ($scenario === 'maximum') {
            $loginAt = $now - $settings['maximum_seconds'] - 1;
            $activityAt = $now; // fresh activity must not extend the fixed login lifetime
        }
        showStep('Age or remove the temporary session timestamps, then request protected access.');
        adjustSessionTimes($sessionId, $userId, $loginAt, $activityAt, $now, $scenario === 'missing');
        if ($scenario === 'logout') {
            $response = requestPage($client, $baseUrl, 'logout.php', ['csrf_token' => tokenFrom($admin)]);
        } else {
            $response = requestPage($client, $baseUrl, 'admin.php');
        }
        check($response['status'] === 302 && strpos($response['headers'], 'login.php?expired=1') !== false,
            $scenario . ' expiry redirects before granting protected access');
        check(!is_file(__DIR__ . '/../storage/sessions/sess_' . $sessionId), 'Expired server session file removed');
        $login = requestPage($client, $baseUrl, 'login.php?expired=1');
        check($login['status'] === 200 && strpos($login['body'], 'Your session expired. Please log in again.') !== false,
            'Login page explains expiry');
        checkOldSessionDenied($sessionId, $baseUrl);
    }

    showStep('Log in and make periodic renewal due without expiring the session.');
    $admin = signInForSessionTest($client, $baseUrl, $username, $password);
    $oldSession = sessionCookie($client);
    $sessionIds[] = $oldSession;
    $now = time();
    $loginAt = $now - $settings['renewal_seconds'] - 5;
    adjustSessionTimes($oldSession, $userId, $loginAt, $now, $now - $settings['renewal_seconds'] - 1);
    $response = requestPage($client, $baseUrl, 'admin.php');
    check($response['status'] === 200, 'Renewal preserves authenticated access');
    $newSession = sessionCookie($client);
    $sessionIds[] = $newSession;
    check($newSession !== '' && $newSession !== $oldSession, 'Periodic renewal changes the session identifier');
    $saved = file_get_contents(__DIR__ . '/../storage/sessions/sess_' . $newSession);
    check(strpos($saved, 'login_at|i:' . $loginAt . ';') !== false, 'Renewal does not restart maximum lifetime');
    // allow a slow local request without making the test depend on completion within one second
    $foundActivity = preg_match('/last_activity_at\|i:([0-9]+);/', $saved, $matches);
    check($foundActivity === 1 && (int) $matches[1] >= $now && (int) $matches[1] <= time(),
        'Authenticated requests refresh activity');
    checkOldSessionDenied($oldSession, $baseUrl);
    check(requestPage($client, $baseUrl, 'admin.php')['status'] === 200, 'Old-cookie replay does not end the new session');
    showStep('Submit the logout form token from before identifier renewal.');
    $response = requestPage($client, $baseUrl, 'logout.php', ['csrf_token' => tokenFrom($admin)]);
    check($response['status'] === 303, 'Existing form token remains usable after periodic renewal');
    checkOldSessionDenied($newSession, $baseUrl);
} catch (Throwable $exception) {
    $failed = true;
    if ($exception instanceof PDOException) {
        reportFailure($exception);
    } else {
        fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    }
} finally {
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
    // delete only exact identifiers captured from this test's own client; never scan/delete other sessions
    foreach (array_unique($sessionIds) as $sessionId) {
        if (preg_match('/\A[a-zA-Z0-9,-]{16,128}\z/', $sessionId)) {
            $path = __DIR__ . '/../storage/sessions/sess_' . $sessionId;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
    unset($client);
    echo "CLEANUP: Temporary session-test account and captured session files removed.\n";
}
if ($failed) {
    exit(1);
}
echo "RESULT: Session-management tests passed.\n";
exit(0);
