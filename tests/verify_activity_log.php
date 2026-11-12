<?php
// WHAT THIS FILE DOES: automatically checks the Activity log page (M12, FB-07) and the deletion of entries older than one year.
// It sends requests to our local Apache like a browser, using made up accounts and made up log entries only.

// STEP 1: Allow terminal use only, load the application, and connect to the development database.
// STEP 2: Create a temporary RDPO administrator, and a temporary head administrator with the "set-role" terminal command.
//         The head administrator gets a made-up authenticator app (two_factor_helper.php) and types its code at login.
// STEP 3: Without logging in, open the log; it must send the visitor to login.
// STEP 4: Log in as the ordinary RDPO administrator; the log answers 403, the delete button is refused, and the menu has no link.
// STEP 5: Log in as the head administrator; the log opens and the filters (who, what, dates, terminal) narrow the list.
// STEP 6: Add 55 made-up recent entries and check the list is split into pages of 50.
// STEP 7: Add three made-up entries dated 400 days ago; the page counts them; a delete without the form token is refused.
// STEP 8: Delete entries older than one year; only the old entries go, the recent ones stay, and the deletion itself is logged.
//         This step runs only when the database has no OTHER entries older than one year, so it never removes real history.
// STEP 9: Press delete again; nothing is deleted and nothing new is logged.
// STEP 10: In cleanup, remove only this script's accounts, log entries and failed-login counters.

// Each check prints PASS; a failed check stops the remaining checks and reports failure.
// Cleanup is attempted in finally even when a check fails after preparation starts.
// The terminal exit code is 0 for success or 1 for a caught test failure.

declare(strict_types=1); // reject incorrect types passed into the test helpers

use Scholar\Auth\LoginThrottle;

// run this script from the terminal; it is not a browser page
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/two_factor_helper.php'; // head administrators must type an app code at login

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
    curl_setopt_array($client, [
        CURLOPT_URL => $baseUrl . $page,
        CURLOPT_POST => $isPost,
        CURLOPT_HTTPGET => !$isPost, // switch the same handle back to GET after a POST
        CURLOPT_RETURNTRANSFER => true, // return the response instead of printing it
        CURLOPT_HEADER => true, // include headers so redirects can be inspected
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_PROXY => '', // send local test requests directly to Apache
    ]);
    if ($form !== null) {
        curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $response = curl_exec($client);
    if ($response === false) {
        throw new RuntimeException('HTTP request failed.');
    }
    $headerLength = curl_getinfo($client, CURLINFO_HEADER_SIZE);
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

// a new HTTP client with its own empty cookie jar, like a separate browser
function newClient(): CurlHandle {
    $client = curl_init();
    curl_setopt($client, CURLOPT_COOKIEFILE, ''); // remember cookies like a browser
    curl_setopt($client, CURLOPT_RESOLVE, ['scholar.localhost:80:127.0.0.1']);
    return $client;
}

// runs one bin/console.php command quietly and returns its exit code (output is never printed)
function runConsole(array $arguments): int {
    // proc_open starts a child PHP command; the three pipes are its input/output/error streams
    $process = proc_open(array_merge([PHP_BINARY, __DIR__ . '/../bin/console.php'], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($process);
}

// the id of one account, read straight from the database
function accountId(PDO $pdo, string $username): int {
    $statement = $pdo->prepare('SELECT id FROM users WHERE username = :username');
    $statement->execute(['username' => $username]);
    return (int) $statement->fetchColumn();
}

// the number shown in data-old-count="..." on the page (the entries older than one year), or -1 when it is missing
function oldCountOn(array $response): int {
    if (preg_match('/data-old-count="([0-9]+)"/', $response['body'], $matches)) {
        return (int) $matches[1];
    }
    return -1;
}

// use our existing Laragon Apache; an alternative local URL can be the first argument
$baseUrl = 'http://scholar.localhost';
if (isset($argv[1])) {
    $baseUrl = $argv[1];
}
$baseUrl = rtrim($baseUrl, '/') . '/';
$pdo = connectDatabase();

// separate synthetic names so this script never touches another suite's data
$suffix = bin2hex(random_bytes(6));
$headUsername = 'verify_loghead_' . $suffix;
$adminUsername = 'verify_logstaff_' . $suffix;
$usernames = [$headUsername, $adminUsername];
$password = bin2hex(random_bytes(20)); // never print or commit the temporary password
$headClient = newClient();
$failed = false;
try {
    echo "PREPARATION: Create this script's temporary accounts.\n";
    putenv('SCHOLAR_ADMIN_PASSWORD=' . $password);
    check(runConsole(['provision-admin', $headUsername]) === 0, 'CLI provisions the future head administrator');
    check(runConsole(['provision-admin', $adminUsername]) === 0, 'CLI provisions an ordinary RDPO administrator');
    putenv('SCHOLAR_ADMIN_PASSWORD');
    check(runConsole(['set-role', $headUsername, 'rdpo_head']) === 0, 'CLI set-role makes the head administrator');
    $headId = accountId($pdo, $headUsername);
    $headSecret = linkTestAuthenticator($pdo, $headId); // head administrators must use two-step login
    $adminId = accountId($pdo, $adminUsername);

    showStep('Open the log without logging in.');
    $anonymous = newClient();
    $response = requestPage($anonymous, $baseUrl, 'admin_activity_log.php');
    check($response['status'] === 302 && strpos($response['headers'], 'login.php') !== false, 'Log redirects visitors to login');
    unset($anonymous);

    showStep('Log in as an ordinary RDPO administrator.');
    $adminClient = newClient();
    check(submitLogin($adminClient, $baseUrl, $adminUsername, $password)['status'] === 303, 'RDPO administrator logs in');
    check(strpos(requestPage($adminClient, $baseUrl, 'admin.php')['body'], 'href="admin_activity_log.php"') === false, 'Menu has no "Activity log" link');
    check(requestPage($adminClient, $baseUrl, 'admin_activity_log.php')['status'] === 403, 'Log answers 403');
    $records = requestPage($adminClient, $baseUrl, 'admin.php');
    check(requestPage($adminClient, $baseUrl, 'admin_activity_log.php', ['action' => 'delete_old', 'csrf_token' => tokenFrom($records)])['status'] === 403, 'Delete button refused');
    unset($adminClient);

    showStep('Log in as the head administrator and filter the log.');
    $headLogin = answerCodeStep($headClient, $baseUrl, submitLogin($headClient, $baseUrl, $headUsername, $password), $pdo, $headId, $headSecret);
    check($headLogin['status'] === 303 && strpos($headLogin['headers'], 'Location: admin.php') !== false, 'Head administrator logs in with the password and the app code');
    check(strpos(requestPage($headClient, $baseUrl, 'admin.php')['body'], 'href="admin_activity_log.php"') !== false, 'Menu has an "Activity log" link');
    $page = requestPage($headClient, $baseUrl, 'admin_activity_log.php');
    check($page['status'] === 200 && strpos($page['body'], 'Activity log') !== false, 'Log opens');
    $mine = requestPage($headClient, $baseUrl, 'admin_activity_log.php?' . http_build_query(['account' => (string) $headId, 'action' => 'auth.login']));
    check(strpos($mine['body'], 'Showing 1-1 of 1') !== false && strpos($mine['body'], $headUsername) !== false, 'Who + What: exactly the head administrator\'s one login');
    $staffLogins = requestPage($headClient, $baseUrl, 'admin_activity_log.php?' . http_build_query(['account' => (string) $adminId]));
    check(strpos($staffLogins['body'], 'Showing 1-1 of 1') !== false && strpos($staffLogins['body'], 'Logged in') !== false, 'Who: the staff account has one entry, its login');
    $terminal = requestPage($headClient, $baseUrl, 'admin_activity_log.php?' . http_build_query(['account' => 'terminal', 'action' => 'account.role_changed']));
    check(strpos($terminal['body'], 'Account ' . $headUsername) !== false && strpos($terminal['body'], '>terminal<') !== false, 'Terminal filter shows the set-role change, linked to the account');
    $future = requestPage($headClient, $baseUrl, 'admin_activity_log.php?' . http_build_query(['from' => '2099-01-01']));
    check(strpos($future['body'], 'No activity matches these filters.') !== false, 'A date range with no entries shows the empty message');
    $today = date('Y-m-d');
    $todayOnly = requestPage($headClient, $baseUrl, 'admin_activity_log.php?' . http_build_query(['account' => (string) $headId, 'from' => $today, 'to' => $today]));
    check(strpos($todayOnly['body'], 'Logged in') !== false, '"From" and "To" on the same day include that whole day');
    $odd = requestPage($headClient, $baseUrl, 'admin_activity_log.php?' . http_build_query(['action' => 'made.up', 'from' => '2026-02-30', 'account' => '1 OR 1=1', 'page' => '-1']));
    check($odd['status'] === 200 && strpos($odd['body'], 'value="2026-02-30"') === false, 'Strange filter values are ignored, not an error');

    showStep('Add 55 made-up recent entries and check the pages.');
    $insert = $pdo->prepare("INSERT INTO audit_logs (actor_user_id, action_code, entity_type, entity_id, created_at)
                             VALUES (:actor, 'thesis.pdf_viewed', 'thesis', NULL, NOW() - INTERVAL :minutes MINUTE)");
    for ($count = 1; $count <= 55; $count++) {
        $insert->execute(['actor' => $adminId, 'minutes' => $count]);
    }
    $firstPage = requestPage($headClient, $baseUrl, 'admin_activity_log.php?' . http_build_query(['account' => (string) $adminId]));
    check(strpos($firstPage['body'], 'Showing 1-50 of 56') !== false && strpos($firstPage['body'], 'Page 1 of 2') !== false, 'First page shows 50 of 56');
    check(strpos($firstPage['body'], 'page=2') !== false && strpos($firstPage['body'], '>Older</a>') !== false, 'An "Older" link keeps the filter');
    $secondPage = requestPage($headClient, $baseUrl, 'admin_activity_log.php?' . http_build_query(['account' => (string) $adminId, 'page' => '2']));
    check(strpos($secondPage['body'], 'Showing 51-56 of 56') !== false && strpos($secondPage['body'], '>Newer</a>') !== false, 'Second page shows the last 6');
    $pastEnd = requestPage($headClient, $baseUrl, 'admin_activity_log.php?' . http_build_query(['account' => (string) $adminId, 'page' => '9']));
    check(strpos($pastEnd['body'], 'Showing 51-56 of 56') !== false, 'A page past the end shows the last page');

    showStep('Add three made-up entries from 400 days ago.');
    $otherOld = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs WHERE created_at < NOW() - INTERVAL 1 YEAR')->fetchColumn();
    $oldInsert = $pdo->prepare("INSERT INTO audit_logs (actor_user_id, action_code, entity_type, entity_id, created_at)
                                VALUES (:actor, 'auth.login', 'user', :entity, NOW() - INTERVAL 400 DAY)");
    for ($count = 1; $count <= 3; $count++) {
        $oldInsert->execute(['actor' => $adminId, 'entity' => $adminId]);
    }
    $page = requestPage($headClient, $baseUrl, 'admin_activity_log.php');
    check(oldCountOn($page) === $otherOld + 3, 'Page counts the entries older than one year');
    check(strpos($page['body'], 'Delete entries older than one year') !== false, 'Delete button shown');
    $noToken = requestPage($headClient, $baseUrl, 'admin_activity_log.php', ['action' => 'delete_old']);
    check($noToken['status'] === 403 && oldCountOn(requestPage($headClient, $baseUrl, 'admin_activity_log.php')) === $otherOld + 3, 'A delete without the form token is refused');
    $wrongAction = requestPage($headClient, $baseUrl, 'admin_activity_log.php', ['action' => 'delete_all', 'csrf_token' => tokenFrom($page)]);
    check($wrongAction['status'] === 422, 'An unknown action is refused');

    showStep('Delete entries older than one year.');
    if ($otherOld !== 0) {
        // never delete real history just to run a test
        echo "SKIP: this database already has $otherOld entries older than one year that this script did not make; deletion not tested.\n";
    } else {
        $deleted = requestPage($headClient, $baseUrl, 'admin_activity_log.php', ['action' => 'delete_old', 'csrf_token' => tokenFrom($page)]);
        check($deleted['status'] === 303, 'Delete accepted');
        $after = requestPage($headClient, $baseUrl, 'admin_activity_log.php');
        check(strpos($after['body'], 'Deleted 3 entries older than one year.') !== false && oldCountOn($after) === 0, 'Three entries deleted, none older than one year left');
        $recent = $pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE actor_user_id = :id');
        $recent->execute(['id' => $adminId]);
        check((int) $recent->fetchColumn() === 56, 'The 56 recent entries of the staff account are still there');
        $logged = $pdo->prepare("SELECT changed_fields FROM audit_logs WHERE action_code = 'audit.old_entries_deleted' AND actor_user_id = :id");
        $logged->execute(['id' => $headId]);
        $loggedRows = $logged->fetchAll(PDO::FETCH_COLUMN);
        check(count($loggedRows) === 1 && preg_match('/\A3 entries before [0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $loggedRows[0]) === 1, 'The deletion is logged with how many and up to which date');
        check(strpos($after['body'], 'Old activity entries deleted') !== false, 'The deletion appears in the log');

        showStep('Press delete again.');
        $again = requestPage($headClient, $baseUrl, 'admin_activity_log.php', ['action' => 'delete_old', 'csrf_token' => tokenFrom($after)]);
        $againPage = requestPage($headClient, $baseUrl, 'admin_activity_log.php');
        $logged->execute(['id' => $headId]);
        check($again['status'] === 303 && strpos($againPage['body'], 'Nothing was deleted.') !== false && count($logged->fetchAll()) === 1, 'Nothing deleted and nothing new logged');
    }
} catch (Throwable $exception) {
    $failed = true;
    echo 'FAIL: ' . $exception->getMessage() . "\n";
} finally {
    // remove only what this script created, in the order the foreign keys require: log entries -> accounts
    echo "\nCLEANUP: Remove this script's accounts, log entries and failed-login counters.\n";
    putenv('SCHOLAR_ADMIN_PASSWORD');
    unset($headClient);
    foreach ($usernames as $username) {
        $pdo->prepare('DELETE FROM failed_logins WHERE account_key = :account_key')->execute(['account_key' => LoginThrottle::accountKey($username)]);
        $userId = accountId($pdo, $username);
        if ($userId > 0) {
            $pdo->prepare("DELETE FROM audit_logs WHERE actor_user_id = :actor_id OR (entity_type = 'user' AND entity_id = :entity_id)")
                ->execute(['actor_id' => $userId, 'entity_id' => $userId]);
            forgetTestTwoFactor($pdo, [(int) $userId]); // backup codes and remembered devices go before the account
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
        }
    }
    if (accountId($pdo, $headUsername) !== 0 || accountId($pdo, $adminUsername) !== 0) {
        $failed = true;
        echo "FAIL: cleanup left temporary accounts behind\n";
    } else {
        echo "PASS: temporary accounts removed\n";
    }
}

if ($failed) {
    echo "RESULT: activity log checks failed.\n";
    exit(1);
}
echo "RESULT: activity log checks passed.\n";
exit(0);
