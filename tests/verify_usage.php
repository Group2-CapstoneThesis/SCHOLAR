<?php
// WHAT THIS FILE DOES: automatically checks website visit counting and the Visits and logins page (M12, FB-06).
// It sends requests to our local Apache like a browser, using made up accounts, browser names and dates only.

// STEP 1: Allow terminal use only, load the application, and connect to the development database.
// STEP 2: Create a temporary RDPO administrator and a temporary head administrator (with the "set-role" terminal command).
//         The head administrator gets a made-up authenticator app (two_factor_helper.php) and types its code at login.
// STEP 3: Count visits: a browser's first page counts once however many pages follow; another browser counts again;
//         the login page counts; robots, scripts and requests without a browser name are not counted.
// STEP 4: Without logging in, and as the ordinary administrator, the report is refused.
// STEP 5: Add made-up visits and logins in March-April 2001 (a period no real data can be in) and check the
//         daily, weekly (Monday to Sunday) and monthly numbers, the edges of a range, and the logins per account.
// STEP 6: Download the weekly CSV; check its rows and the history entry. A POST is refused.
// STEP 7: In cleanup, remove only this script's visits, made-up entries, accounts and failed-login counters.

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

const BROWSER_NAME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

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
function requestPage(CurlHandle $client, string $baseUrl, string $page, $form = null): array {
    $isPost = false;
    if ($form !== null) {
        $isPost = true;
    }
    curl_setopt_array($client, [
        CURLOPT_URL => $baseUrl . $page,
        CURLOPT_POST => $isPost,
        CURLOPT_HTTPGET => !$isPost,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_PROXY => '',
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
    return requestPage($client, $baseUrl, 'login.php', ['identifier' => $identifier, 'password' => $password, 'csrf_token' => tokenFrom($login)]);
}

// a new HTTP client with its own empty cookie jar, like a separate browser; $browserName '' sends no name at all
function newClient(string $browserName): CurlHandle {
    $client = curl_init();
    curl_setopt($client, CURLOPT_COOKIEFILE, '');
    curl_setopt($client, CURLOPT_RESOLVE, ['scholar.localhost:80:127.0.0.1']);
    if ($browserName !== '') {
        curl_setopt($client, CURLOPT_USERAGENT, $browserName);
    }
    return $client;
}

// runs one bin/console.php command quietly and returns its exit code
function runConsole(array $arguments): int {
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

// how many visits have been recorded after the visit with id $afterId
function visitsAfter(PDO $pdo, int $afterId): int {
    $statement = $pdo->prepare('SELECT COUNT(*) FROM site_visits WHERE id > :id');
    $statement->execute(['id' => $afterId]);
    return (int) $statement->fetchColumn();
}

// the visits and logins shown in one period row of the table, e.g. [2, 1], or null when the row is missing
function periodRow(array $response, string $periodStart): ?array {
    $pattern = '/data-period="' . preg_quote($periodStart, '/') . '">\s*<td[^>]*>[^<]*<\/td>\s*<td class="text-end">([0-9]+)<\/td>\s*<td class="text-end">([0-9]+)<\/td>/';
    if (!preg_match($pattern, $response['body'], $matches)) {
        return null;
    }
    return [(int) $matches[1], (int) $matches[2]];
}

// the totals line: [visits, logins]
function totals(array $response): array {
    preg_match('/data-total-visits="([0-9]+)"/', $response['body'], $visits);
    preg_match('/data-total-logins="([0-9]+)"/', $response['body'], $logins);
    return [(int) $visits[1], (int) $logins[1]];
}

$baseUrl = 'http://scholar.localhost';
if (isset($argv[1])) {
    $baseUrl = $argv[1];
}
$baseUrl = rtrim($baseUrl, '/') . '/';
$pdo = connectDatabase();

$suffix = bin2hex(random_bytes(6));
$headUsername = 'verify_ushead_' . $suffix;
$staffUsername = 'verify_usstaff_' . $suffix;
$password = bin2hex(random_bytes(20)); // never print or commit the temporary password
$lastVisitBefore = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM site_visits')->fetchColumn();
$headClient = newClient(BROWSER_NAME);
$failed = false;
try {
    echo "PREPARATION: Create this script's temporary accounts.\n";
    putenv('SCHOLAR_ADMIN_PASSWORD=' . $password);
    check(runConsole(['provision-admin', $headUsername]) === 0, 'CLI provisions the future head administrator');
    check(runConsole(['provision-admin', $staffUsername]) === 0, 'CLI provisions an ordinary RDPO administrator');
    putenv('SCHOLAR_ADMIN_PASSWORD');
    check(runConsole(['set-role', $headUsername, 'rdpo_head']) === 0, 'CLI set-role makes the head administrator');
    $headId = accountId($pdo, $headUsername);
    $headSecret = linkTestAuthenticator($pdo, $headId); // head administrators must use two-step login
    $staffId = accountId($pdo, $staffUsername);
    $existing2001 = (int) $pdo->query("SELECT COUNT(*) FROM site_visits WHERE visited_at < '2002-01-01'")->fetchColumn()
        + (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE created_at < '2002-01-01'")->fetchColumn();
    check($existing2001 === 0, 'No real visits or logins before 2002, so the made-up ones can be counted exactly');

    showStep('Count website visits.');
    $browserA = newClient(BROWSER_NAME);
    $first = requestPage($browserA, $baseUrl, 'index.php');
    check(visitsAfter($pdo, $lastVisitBefore) === 1, 'A browser\'s first page counts one visit');
    check(preg_match('/^Set-Cookie: scholar_visit=1;[^\r\n]*Max-Age=1800;[^\r\n]*HttpOnly/mi', $first['headers']) === 1, 'The visit cookie lasts 30 minutes and is hidden from scripts');
    requestPage($browserA, $baseUrl, 'index.php?q=sample');
    requestPage($browserA, $baseUrl, 'thesis.php?id=1');
    requestPage($browserA, $baseUrl, 'login.php');
    check(visitsAfter($pdo, $lastVisitBefore) === 1, 'More pages in the same visit do not count again');
    requestPage(newClient(BROWSER_NAME), $baseUrl, 'login.php');
    check(visitsAfter($pdo, $lastVisitBefore) === 2, 'Another browser opening the login page counts a second visit');
    $script = requestPage(newClient(''), $baseUrl, 'index.php');
    requestPage(newClient('curl/8.4.0'), $baseUrl, 'index.php');
    requestPage(newClient('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'), $baseUrl, 'index.php');
    check(visitsAfter($pdo, $lastVisitBefore) === 2 && stripos($script['headers'], 'scholar_visit') === false, 'Requests without a browser name, scripts and robots are not counted');
    $stored = $pdo->query('SELECT * FROM site_visits ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    check(array_keys($stored) === ['id', 'visited_at'], 'A visit stores only its time, nothing about the visitor');
    unset($browserA);

    showStep('Open the report without permission.');
    check(strpos(requestPage(newClient(BROWSER_NAME), $baseUrl, 'admin_usage.php')['headers'], 'login.php') !== false, 'Report redirects visitors to login');
    $staffClient = newClient(BROWSER_NAME);
    check(submitLogin($staffClient, $baseUrl, $staffUsername, $password)['status'] === 303, 'RDPO administrator logs in');
    check(strpos(requestPage($staffClient, $baseUrl, 'admin.php')['body'], 'href="admin_usage.php"') === false, 'Menu has no "Visits and logins" link');
    check(requestPage($staffClient, $baseUrl, 'admin_usage.php')['status'] === 403, 'Report answers 403');
    check(requestPage($staffClient, $baseUrl, 'admin_usage.php?download=csv')['status'] === 403, 'CSV answers 403');
    unset($staffClient);

    showStep('Add made-up visits and logins in 2001 and check the numbers.');
    // Monday 5 March 2001 to Sunday 11 March is one week; Monday 12 March starts the next
    $insertVisit = $pdo->prepare('INSERT INTO site_visits (visited_at) VALUES (:at)');
    foreach (['2001-03-05 10:00:00', '2001-03-11 23:30:00', '2001-03-12 00:10:00', '2001-04-01 09:00:00'] as $moment) {
        $insertVisit->execute(['at' => $moment]);
    }
    $insertLogin = $pdo->prepare("INSERT INTO audit_logs (actor_user_id, action_code, entity_type, entity_id, created_at) VALUES (:id, 'auth.login', 'user', :id2, :at)");
    $insertLogin->execute(['id' => $staffId, 'id2' => $staffId, 'at' => '2001-03-06 08:00:00']);
    $insertLogin->execute(['id' => $staffId, 'id2' => $staffId, 'at' => '2001-03-13 08:00:00']);
    $insertLogin->execute(['id' => $headId, 'id2' => $headId, 'at' => '2001-03-13 09:00:00']);

    $headLogin = answerCodeStep($headClient, $baseUrl, submitLogin($headClient, $baseUrl, $headUsername, $password), $pdo, $headId, $headSecret);
    check($headLogin['status'] === 303 && strpos($headLogin['headers'], 'Location: admin.php') !== false, 'Head administrator logs in with the password and the app code');
    check(strpos(requestPage($headClient, $baseUrl, 'admin.php')['body'], 'href="admin_usage.php"') !== false, 'Menu has a "Visits and logins" link');
    $default = requestPage($headClient, $baseUrl, 'admin_usage.php');
    check($default['status'] === 200 && periodRow($default, date('Y-m-d')) !== null, 'Report opens on the last 14 days, today included');

    $daily = requestPage($headClient, $baseUrl, 'admin_usage.php?' . http_build_query(['group' => 'daily', 'from' => '2001-03-05', 'to' => '2001-03-13']));
    check(totals($daily) === [3, 3], 'Daily, 5-13 March: 3 visits and 3 logins');
    check(periodRow($daily, '2001-03-11') === [1, 0] && periodRow($daily, '2001-03-13') === [0, 2] && periodRow($daily, '2001-03-08') === [0, 0], 'Each day shows its own numbers, empty days as 0');
    $sunday = requestPage($headClient, $baseUrl, 'admin_usage.php?' . http_build_query(['group' => 'daily', 'from' => '2001-03-11', 'to' => '2001-03-11']));
    check(totals($sunday) === [1, 0], 'A one-day range includes a visit at 23:30 that day');
    $middle = requestPage($headClient, $baseUrl, 'admin_usage.php?' . http_build_query(['group' => 'daily', 'from' => '2001-03-07', 'to' => '2001-03-10']));
    check(totals($middle) === [0, 0], 'Days outside the range are left out');

    $weekly = requestPage($headClient, $baseUrl, 'admin_usage.php?' . http_build_query(['group' => 'weekly', 'from' => '2001-03-05', 'to' => '2001-03-18']));
    check(periodRow($weekly, '2001-03-05') === [2, 1] && periodRow($weekly, '2001-03-12') === [1, 2], 'Weekly: Monday-to-Sunday weeks, Sunday 23:30 in the first, Monday 00:10 in the second');
    check(strpos($weekly['body'], 'Mar 5 - Mar 11, 2001') !== false, 'Weeks are labelled with their first and last day');
    check(preg_match('/data-account-logins="' . preg_quote($staffUsername, '/') . '">2</', $weekly['body']) === 1
        && preg_match('/data-account-logins="' . preg_quote($headUsername, '/') . '">1</', $weekly['body']) === 1, 'Logins per account: 2 and 1');

    $monthly = requestPage($headClient, $baseUrl, 'admin_usage.php?' . http_build_query(['group' => 'monthly', 'from' => '2001-03-20', 'to' => '2001-04-02']));
    check(periodRow($monthly, '2001-03-01') === [3, 3] && periodRow($monthly, '2001-04-01') === [1, 0], 'Monthly: dates inside a month count for the whole month');

    showStep('Download the weekly CSV.');
    $csv = requestPage($headClient, $baseUrl, 'admin_usage.php?' . http_build_query(['group' => 'weekly', 'from' => '2001-03-05', 'to' => '2001-03-18', 'download' => 'csv']));
    check($csv['status'] === 200 && stripos($csv['headers'], 'Content-Type: text/csv; charset=utf-8') !== false, 'Download is a UTF-8 CSV file');
    check(strpos($csv['headers'], 'filename="visits_and_logins_weekly_2001-03-05_to_2001-03-18.csv"') !== false, 'File name names the grouping and dates');
    $lines = explode("\r\n", rtrim(substr($csv['body'], 3), "\r\n"));
    $headings = str_getcsv($lines[0], ',', '"', '');
    $staffColumn = array_search('Logins: ' . $staffUsername, $headings, true);
    $firstWeek = str_getcsv($lines[1], ',', '"', '');
    $secondWeek = str_getcsv($lines[2], ',', '"', '');
    check(count($lines) === 3 && array_slice($firstWeek, 0, 4) === ['2001-03-05', '2001-03-11', '2', '1'] && array_slice($secondWeek, 0, 4) === ['2001-03-12', '2001-03-18', '1', '2'], 'One row per week, oldest first, with visits and logins');
    check($staffColumn !== false && $firstWeek[$staffColumn] === '1' && $secondWeek[$staffColumn] === '1', 'Each account has its own logins column');
    $audit = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action_code = 'report.usage_exported' AND actor_user_id = :id");
    $audit->execute(['id' => $headId]);
    check((int) $audit->fetchColumn() === 1, 'History records the download');
    check(requestPage($headClient, $baseUrl, 'admin_usage.php', ['x' => '1'])['status'] === 405, 'A POST is refused');
} catch (Throwable $exception) {
    $failed = true;
    echo 'FAIL: ' . $exception->getMessage() . "\n";
} finally {
    echo "\nCLEANUP: Remove this script's visits, made-up entries, accounts and failed-login counters.\n";
    putenv('SCHOLAR_ADMIN_PASSWORD');
    unset($headClient);
    // the visits this script made: the made-up 2001 ones, and the real ones its browsers made while it ran
    $pdo->prepare('DELETE FROM site_visits WHERE id > :id')->execute(['id' => $lastVisitBefore]);
    foreach ([$headUsername, $staffUsername] as $username) {
        $pdo->prepare('DELETE FROM failed_logins WHERE account_key = :key')->execute(['key' => LoginThrottle::accountKey($username)]);
        $userId = accountId($pdo, $username);
        if ($userId > 0) {
            $pdo->prepare("DELETE FROM audit_logs WHERE actor_user_id = :actor OR (entity_type = 'user' AND entity_id = :entity)")
                ->execute(['actor' => $userId, 'entity' => $userId]);
            forgetTestTwoFactor($pdo, [(int) $userId]); // backup codes and remembered devices go before the account
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
        }
    }
    $leftVisits = (int) $pdo->query("SELECT COUNT(*) FROM site_visits WHERE visited_at < '2002-01-01'")->fetchColumn();
    if (accountId($pdo, $headUsername) !== 0 || accountId($pdo, $staffUsername) !== 0 || $leftVisits !== 0) {
        $failed = true;
        echo "FAIL: cleanup left accounts or made-up visits behind\n";
    } else {
        echo "PASS: temporary accounts, visits and entries removed\n";
    }
}

if ($failed) {
    echo "RESULT: visits and logins checks failed.\n";
    exit(1);
}
echo "RESULT: visits and logins checks passed.\n";
exit(0);
