<?php
// WHAT THIS FILE DOES: automatically checks the Registrar completion list page (M12, FB-03).
// It sends requests to our local Apache like a browser, using made up accounts and made up thesis records.

// STEP 1: Allow terminal use only, load the application, and connect to the development database.
// STEP 2: Create a temporary fictional RDPO administrator, a no-permission account, and four made-up records in a
//         department that only this script uses (one of them archived, one from another year).
// STEP 3: Without logging in, open the page and the download; both must send the visitor to login.
// STEP 4: Log in with the no-permission account; the page and the download must answer 403.
// STEP 5: Log in as RDPO and open the list for one year and the test department; check who is listed and the order.
// STEP 6: Check the year rule: the defense year counts first, the thesis year when no defense date was recorded.
// STEP 7: Download the CSV; check its headers, first bytes, the names-only lines ("Last name, Given names" in one cell)
//         and the audit entry.
// STEP 8: Send a POST and a strange year filter; the POST is refused and the strange filter falls back to all years.
// STEP 9: In cleanup, remove only this script's records, audit rows, accounts and made-up role.

// Each check prints PASS; a failed check stops the remaining checks and reports failure.
// Cleanup is attempted in finally even when a check fails after preparation starts.
// The terminal exit code is 0 for success or 1 for a caught test failure.

declare(strict_types=1); // reject incorrect types passed into the test helpers

use Scholar\Theses\AuthorNames;
use Scholar\Theses\ThesisRecords;

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
    curl_setopt_array($client, [
        CURLOPT_URL => $baseUrl . $page,
        CURLOPT_POST => $isPost,
        CURLOPT_RETURNTRANSFER => true, // return the response instead of printing it
        CURLOPT_HEADER => true, // include headers so redirects and download headers can be inspected
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

// the values ThesisRecords::createThesis() expects, for one made-up record
// $authors is a list of [given names, last name, suffix or null]
function recordValues(string $title, int $year, ?string $defenseDate, string $department, array $authors): array {
    $authorValues = [];
    foreach ($authors as $author) {
        $authorValues[] = [
            'given_names' => $author[0],
            'last_name' => $author[1],
            'suffix' => $author[2],
            'full_name' => AuthorNames::fullName($author[0], $author[1], $author[2]),
        ];
    }
    return [
        'title' => $title, 'authors' => $authorValues, 'publication_year' => $year,
        'program' => 'BS Verification Studies', 'department' => $department, 'campus' => 'WCC QC', 'adviser' => null,
        'abstract' => null, 'keywords' => null, 'date_submitted' => null,
        'final_defense_date' => $defenseDate, 'page_count' => null, 'is_public' => false,
    ];
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
$username = 'verify_completion_' . $suffix;
$email = $username . '@example.invalid';
$password = bin2hex(random_bytes(20)); // never print or commit the temporary password
$noRoleUsername = 'verify_cnorole_' . $suffix;
$noRoleCode = 'verify_cnorole_' . $suffix;
$department = 'Verify Department ' . $suffix; // only this script's records have this department
$listPage = 'admin_completion_list.php?' . http_build_query(['year' => '2025', 'department' => $department]);
$downloadPage = $listPage . '&download=csv';
$client = newClient();
$failed = false;
try {
    echo "PREPARATION: Create this script's temporary accounts and records.\n";
    putenv('SCHOLAR_ADMIN_PASSWORD=' . $password);
    // proc_open starts a child PHP command; the three pipes are its input/output/error streams
    $process = proc_open([PHP_BINARY, __DIR__ . '/../bin/console.php', 'provision-admin', $username],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]); // consume output without exposing it
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0, 'CLI provisions temporary RDPO administrator');
    putenv('SCHOLAR_ADMIN_PASSWORD');
    $lookup = $pdo->prepare('SELECT id FROM users WHERE username = :username');
    $lookup->execute(['username' => $username]);
    $adminId = (int) $lookup->fetchColumn();

    // a made-up role that Access::ROLE_PERMISSIONS does not list, so it may do nothing
    $statement = $pdo->prepare('INSERT INTO roles (code, label) VALUES (:code, :label)');
    $statement->execute(['code' => $noRoleCode, 'label' => 'Temporary test role without permissions']);
    $noRoleId = (int) $pdo->lastInsertId();
    $statement = $pdo->prepare('INSERT INTO users (role_id, username, password_hash) VALUES (:role_id, :username, :password_hash)');
    $statement->execute([
        'role_id' => $noRoleId,
        'username' => $noRoleUsername,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    // four made-up records, created through the real record class:
    //   A: thesis year 2024 but defended in 2025 -> counted in 2025
    //   B: thesis year 2025, no defense date     -> counted in 2025; its title looks like a spreadsheet formula
    //   C: defended in 2025 but archived         -> never listed
    //   D: defended in 2023                      -> only in "all years"
    $records = new ThesisRecords($pdo);
    $records->createThesis(recordValues('Verify Completion A ' . $suffix, 2024, '2025-03-15', $department,
        [['Zed Sample', 'Verifier', null], ['Ana Sample', 'Dela Prueba', 'Jr.']]), $adminId);
    $records->createThesis(recordValues('=SUM(1+1) Verify Completion B ' . $suffix, 2025, null, $department,
        [['Bea Sample', 'Aaron-Test', null]]), $adminId);
    $archivedId = $records->createThesis(recordValues('Verify Completion C ' . $suffix, 2025, '2025-02-01', $department,
        [['Cara Sample', 'Archivedperson', null]]), $adminId);
    $records->setArchived($archivedId, true, $adminId);
    $records->createThesis(recordValues('Verify Completion D ' . $suffix, 2023, '2023-05-01', $department,
        [['Dan Sample', 'Olderyear', null]]), $adminId);
    check($adminId > 0 && $noRoleId > 0 && $archivedId > 0, 'Temporary accounts and records prepared');

    showStep('Open the list and the download without logging in.');
    $anonymous = newClient();
    try {
        $response = requestPage($anonymous, $baseUrl, $listPage);
        check($response['status'] === 302 && strpos($response['headers'], 'login.php') !== false, 'List redirects visitors to login');
        $response = requestPage($anonymous, $baseUrl, $downloadPage);
        check($response['status'] === 302 && strpos($response['body'], 'Verifier') === false, 'Download redirects visitors to login and sends no names');
    } finally {
        unset($anonymous); // PHP releases the handle object without deprecated curl_close
    }

    showStep('Log in with the no-permission account and open the list and the download.');
    $noRoleClient = newClient();
    try {
        check(submitLogin($noRoleClient, $baseUrl, $noRoleUsername, $password)['status'] === 303, 'No-permission account can log in');
        check(requestPage($noRoleClient, $baseUrl, $listPage)['status'] === 403, 'List refused with 403');
        $response = requestPage($noRoleClient, $baseUrl, $downloadPage);
        check($response['status'] === 403 && strpos($response['body'], 'Verifier') === false, 'Download refused with 403 and sends no names');
    } finally {
        unset($noRoleClient);
    }

    showStep('Log in as RDPO and open the 2025 list for the test department.');
    check(submitLogin($client, $baseUrl, $username, $password)['status'] === 303, 'RDPO administrator logs in');
    $records = requestPage($client, $baseUrl, 'admin.php');
    check(strpos($records['body'], 'href="admin_completion_list.php"') !== false, 'Records page links to the completion list');
    $page = requestPage($client, $baseUrl, $listPage);
    check($page['status'] === 200 && strpos($page['body'], 'Registrar completion list') !== false, 'List opens');
    check(strpos($page['body'], '3 students') !== false && strpos($page['body'], 'from 2 theses') !== false, 'Summary counts 3 students from 2 theses');
    $first = strpos($page['body'], 'Aaron-Test');
    $second = strpos($page['body'], 'Dela Prueba');
    $third = strpos($page['body'], 'Verifier');
    check($first !== false && $second !== false && $third !== false && $first < $second && $second < $third, 'Students sorted by last name');
    check(strpos($page['body'], 'Ana Sample, Jr.') !== false, 'Suffix shown after the given names');
    check(strpos($page['body'], 'Archivedperson') === false, 'Archived record left out');
    check(strpos($page['body'], '=SUM(1+1)') !== false, 'Formula-looking title shown as plain text on the page');

    showStep('Check which year each student is counted in.');
    check(strpos($page['body'], 'Olderyear') === false, 'A 2023 defense is not in the 2025 list');
    $allYears = requestPage($client, $baseUrl, 'admin_completion_list.php?' . http_build_query(['department' => $department]));
    check(strpos($allYears['body'], 'Olderyear') !== false && strpos($allYears['body'], '4 students') !== false, 'All years lists the 2023 student too');
    $year2024 = requestPage($client, $baseUrl, 'admin_completion_list.php?' . http_build_query(['year' => '2024', 'department' => $department]));
    check(strpos($year2024['body'], 'No students match these filters.') !== false, 'A 2024 thesis defended in 2025 counts in 2025, not 2024');
    check(strpos($page['body'], 'value="' . htmlspecialchars($department, ENT_QUOTES) . '" selected') !== false, 'Test department offered and selected in the dropdown');

    showStep('Download the CSV.');
    $download = requestPage($client, $baseUrl, $downloadPage);
    $expectedName = 'completion_list_2025_verify_department_' . $suffix . '.csv';
    check($download['status'] === 200 && stripos($download['headers'], 'Content-Type: text/csv; charset=utf-8') !== false, 'Download is a UTF-8 CSV file');
    check(strpos($download['headers'], 'attachment; filename="' . $expectedName . '"') !== false, 'File name names the year and department');
    check(stripos($download['headers'], 'Cache-Control: no-store') !== false, 'Browser is told not to cache the names');
    check(strncmp($download['body'], "\xEF\xBB\xBF", 3) === 0, 'File starts with the UTF-8 mark for Excel');
    $lines = explode("\r\n", rtrim(substr($download['body'], 3), "\r\n"));
    // names only: each line is one quoted cell "Last name, Given names", with no heading row and no other columns
    check($lines === ['"Aaron-Test, Bea Sample"', '"Dela Prueba, Ana Sample Jr."', '"Verifier, Zed Sample"'], 'Three names sorted by last name, each "Last name, Given names" in one cell');
    check(strpos($download['body'], 'Verify Completion') === false && strpos($download['body'], 'BS Verification Studies') === false
        && strpos($download['body'], $department) === false && strpos($download['body'], '2025') === false, 'No titles, programs, departments or dates in the file');
    $audit = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE actor_user_id = :id AND action_code = 'report.completion_list_exported' AND changed_fields IS NULL");
    $audit->execute(['id' => $adminId]);
    check((int) $audit->fetchColumn() === 1, 'Download recorded once in the audit log, without names');

    showStep('Send a POST and a strange year filter.');
    $response = requestPage($client, $baseUrl, $listPage, ['csrf_token' => tokenFrom($page)]);
    check($response['status'] === 405, 'POST refused with 405');
    $strange = requestPage($client, $baseUrl, 'admin_completion_list.php?' . http_build_query(['year' => '2025 OR 1=1', 'department' => $department]));
    check($strange['status'] === 200 && strpos($strange['body'], '4 students') !== false, 'Strange year falls back to all years');

    $response = requestPage($client, $baseUrl, 'logout.php', ['csrf_token' => tokenFrom($page)]);
    check($response['status'] === 303, 'Logout succeeds');
} catch (Throwable $exception) {
    $failed = true;
    if ($exception instanceof PDOException) {
        reportFailure($exception); // keep unexpected database details in the private error handler
    } else {
        fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    }
} finally {
    // remove only what this script created, in the order the foreign keys require:
    // audit rows -> authors -> records -> accounts -> made-up role
    echo "\nCLEANUP: Remove this script's records, audit rows, accounts and made-up role.\n";
    putenv('SCHOLAR_ADMIN_PASSWORD');
    $userIds = $pdo->prepare('SELECT id FROM users WHERE username IN (:admin_username, :norole_username)');
    $userIds->execute(['admin_username' => $username, 'norole_username' => $noRoleUsername]);
    foreach ($userIds->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        // records made by this account in this script's own department
        $thesisIds = $pdo->prepare('SELECT id FROM theses WHERE created_by = :user_id AND department = :department');
        $thesisIds->execute(['user_id' => $userId, 'department' => $department]);
        foreach ($thesisIds->fetchAll(PDO::FETCH_COLUMN) as $thesisId) {
            $pdo->prepare("DELETE FROM audit_logs WHERE entity_type = 'thesis' AND entity_id = :id")->execute(['id' => $thesisId]);
            $pdo->prepare('DELETE FROM thesis_authors WHERE thesis_id = :id')->execute(['id' => $thesisId]);
            $pdo->prepare('DELETE FROM theses WHERE id = :id')->execute(['id' => $thesisId]);
        }
        $pdo->prepare("DELETE FROM audit_logs WHERE actor_user_id = :actor_id OR (entity_type = 'user' AND entity_id = :entity_id)")
            ->execute(['actor_id' => $userId, 'entity_id' => $userId]);
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
    }
    $pdo->prepare('DELETE FROM roles WHERE code = :code')->execute(['code' => $noRoleCode]);
    unset($client); // PHP releases CurlHandle objects when no references remain
}
if ($failed) {
    exit(1); // terminal exit code 1 means the suite failed
}
echo "\nRESULT: Completion list tests passed.\n";
exit(0);
