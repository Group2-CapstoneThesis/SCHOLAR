<?php
// WHAT THIS FILE DOES: automatically checks the Research analytics page (M13, FB-05).
// It sends requests to our local Apache like a browser, using made up accounts and made up records only.

// STEP 1: Allow terminal use only, load the application, and connect to the development database.
// STEP 2: Create a temporary RDPO administrator, a no-permission account, and five made-up records in two departments
//         that only this script uses: one archived, one published, one defended a year after its thesis year.
// STEP 3: Without logging in, as the no-permission account, and with the permission switched off, the page is refused.
// STEP 4: As the RDPO administrator, check the per-department, per-year and year-by-department numbers.
// STEP 5: Count by final-defense year: the late-defended record moves to the next year.
// STEP 6: Published records only: only the published record is counted.
// STEP 7: Limit the years: a single year, a start or end only, years the wrong way round, and the CSV for a range.
// STEP 8: The Year column of the year-by-department table is frozen while the department columns scroll.
// STEP 9: Download the CSV; check its headings and the test departments' numbers. A POST is refused.
// STEP 10: In cleanup, remove only this script's records, permission switch, accounts, made-up role and history.

// Each check prints PASS; a failed check stops the remaining checks and reports failure.
// Cleanup is attempted in finally even when a check fails after preparation starts.
// The terminal exit code is 0 for success or 1 for a caught test failure.

declare(strict_types=1); // reject incorrect types passed into the test helpers

use Scholar\Auth\LoginThrottle;
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

// a new HTTP client with its own empty cookie jar, like a separate browser
function newClient(): CurlHandle {
    $client = curl_init();
    curl_setopt($client, CURLOPT_COOKIEFILE, '');
    curl_setopt($client, CURLOPT_RESOLVE, ['scholar.localhost:80:127.0.0.1']);
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

// the values ThesisRecords::createThesis() expects, for one made-up record with $authorCount authors
function recordValues(string $title, int $year, ?string $defenseDate, string $department, int $authorCount): array {
    $authors = [];
    for ($number = 1; $number <= $authorCount; $number++) {
        $authors[] = ['given_names' => 'Sample', 'last_name' => 'Analyst' . $number, 'suffix' => null,
            'full_name' => AuthorNames::fullName('Sample', 'Analyst' . $number, null)];
    }
    return [
        'title' => $title, 'authors' => $authors, 'publication_year' => $year, 'program' => 'BS Verification Studies',
        'department' => $department, 'campus' => 'WCC QC', 'adviser' => null, 'abstract' => null, 'keywords' => null, 'date_submitted' => null,
        'final_defense_date' => $defenseDate, 'page_count' => null, 'is_public' => false,
    ];
}

// the number in the year-by-department table for one year and department, or -1 when that cell is missing
function cellOn(array $response, string $year, string $department): int {
    $pattern = '/data-cell="' . preg_quote(htmlspecialchars($year . '|' . $department, ENT_QUOTES), '/') . '">([0-9]+)</';
    if (preg_match($pattern, $response['body'], $matches)) {
        return (int) $matches[1];
    }
    return -1;
}

// [theses, students] in the per-department table for one department, or null when the row is missing
function departmentRow(array $response, string $department): ?array {
    $pattern = '/data-department="' . preg_quote(htmlspecialchars($department, ENT_QUOTES), '/') . '">\s*<th[^>]*>[^<]*<\/th>\s*<td class="text-end">([0-9]+)<\/td>\s*<td class="text-end">([0-9]+)<\/td>/';
    if (!preg_match($pattern, $response['body'], $matches)) {
        return null;
    }
    return [(int) $matches[1], (int) $matches[2]];
}

$baseUrl = 'http://scholar.localhost';
if (isset($argv[1])) {
    $baseUrl = $argv[1];
}
$baseUrl = rtrim($baseUrl, '/') . '/';
$pdo = connectDatabase();

$suffix = bin2hex(random_bytes(6));
$username = 'verify_analytics_' . $suffix;
$noRoleUsername = 'verify_anorole_' . $suffix;
$noRoleCode = 'verify_anorole_' . $suffix;
$password = bin2hex(random_bytes(20)); // never print or commit the temporary password
$departmentA = 'Verify Analytics A ' . $suffix;
$departmentB = '=Verify Analytics B ' . $suffix; // looks like a spreadsheet formula, to check the CSV protection
$titlePrefix = 'Verify Analytics ' . $suffix;
$client = newClient();
$failed = false;
try {
    echo "PREPARATION: Create this script's temporary accounts and records.\n";
    putenv('SCHOLAR_ADMIN_PASSWORD=' . $password);
    check(runConsole(['provision-admin', $username]) === 0, 'CLI provisions temporary RDPO administrator');
    putenv('SCHOLAR_ADMIN_PASSWORD');
    $adminId = accountId($pdo, $username);
    $statement = $pdo->prepare('INSERT INTO roles (code, label) VALUES (:code, :label)');
    $statement->execute(['code' => $noRoleCode, 'label' => 'Temporary test role without permissions']);
    $noRoleId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO users (role_id, username, password_hash) VALUES (:role_id, :username, :hash)')->execute([
        'role_id' => $noRoleId, 'username' => $noRoleUsername, 'hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    // the five made-up records:
    //   A1: department A, thesis year 2024, defended 2025, 2 authors      A2: department A, 2024, 3 authors, published
    //   A3: department A, 2025, 1 author                                   B1: department B, 2025, 2 authors
    //   X:  department A, 2024, archived (never counted)
    $records = new ThesisRecords($pdo);
    $records->createThesis(recordValues($titlePrefix . ' A1', 2024, '2025-02-10', $departmentA, 2), $adminId);
    $publishedId = $records->createThesis(recordValues($titlePrefix . ' A2', 2024, null, $departmentA, 3), $adminId);
    $records->setVisibility($publishedId, true, $adminId);
    $records->createThesis(recordValues($titlePrefix . ' A3', 2025, null, $departmentA, 1), $adminId);
    $records->createThesis(recordValues($titlePrefix . ' B1', 2025, null, $departmentB, 2), $adminId);
    $archivedId = $records->createThesis(recordValues($titlePrefix . ' X', 2024, null, $departmentA, 4), $adminId);
    $records->setArchived($archivedId, true, $adminId);
    check($adminId > 0 && $noRoleId > 0, 'Temporary accounts and records prepared');

    showStep('Open the page without permission.');
    check(strpos(requestPage(newClient(), $baseUrl, 'admin_analytics.php')['headers'], 'login.php') !== false, 'Page redirects visitors to login');
    $noRoleClient = newClient();
    check(submitLogin($noRoleClient, $baseUrl, $noRoleUsername, $password)['status'] === 303, 'No-permission account logs in');
    check(requestPage($noRoleClient, $baseUrl, 'admin_analytics.php')['status'] === 403, 'No-permission account gets 403');
    unset($noRoleClient);
    $pdo->prepare("INSERT INTO user_revoked_permissions (user_id, permission_code) VALUES (:id, 'report.analytics')")->execute(['id' => $adminId]);
    check(submitLogin($client, $baseUrl, $username, $password)['status'] === 303, 'RDPO administrator logs in');
    check(requestPage($client, $baseUrl, 'admin_analytics.php')['status'] === 403, 'With "Research analytics" switched off: 403');
    check(strpos(requestPage($client, $baseUrl, 'admin.php')['body'], 'href="admin_analytics.php"') === false, 'Switched off: no menu link');
    $pdo->prepare('DELETE FROM user_revoked_permissions WHERE user_id = :id')->execute(['id' => $adminId]);

    showStep('Count by thesis year, all active records.');
    check(strpos(requestPage($client, $baseUrl, 'admin.php')['body'], 'href="admin_analytics.php"') !== false, 'Switched back on: the menu links to the analytics');
    $page = requestPage($client, $baseUrl, 'admin_analytics.php');
    check($page['status'] === 200 && strpos($page['body'], 'Research analytics') !== false, 'Page opens');
    check(departmentRow($page, $departmentA) === [3, 6], 'Department A: 3 theses by 6 students (the archived one is not counted)');
    check(departmentRow($page, $departmentB) === [1, 2], 'Department B: 1 thesis by 2 students');
    check(cellOn($page, '2024', $departmentA) === 2 && cellOn($page, '2025', $departmentA) === 1, 'Department A per year: 2 in 2024, 1 in 2025');
    check(cellOn($page, '2024', $departmentB) === 0 && cellOn($page, '2025', $departmentB) === 1, 'Department B per year: 0 in 2024, 1 in 2025');
    check(strpos($page['body'], htmlspecialchars($departmentB, ENT_QUOTES)) !== false, 'A formula-looking department is shown as plain text');

    showStep('Count by final-defense year.');
    $defense = requestPage($client, $baseUrl, 'admin_analytics.php?basis=defense');
    check(cellOn($defense, '2024', $departmentA) === 1 && cellOn($defense, '2025', $departmentA) === 2, 'The record defended in 2025 moves from 2024 to 2025');
    check(strpos($defense['body'], 'counted in its thesis year') !== false, 'The page explains the fallback for records without a defense date');

    showStep('Count published records only.');
    $public = requestPage($client, $baseUrl, 'admin_analytics.php?scope=public');
    check(departmentRow($public, $departmentA) === [1, 3] && departmentRow($public, $departmentB) === null, 'Only the published record (department A, 3 students) is counted');

    showStep('Limit the years.');
    check(strpos($page['body'], '<option value="2024"') !== false && strpos($page['body'], '<option value="2025"') !== false, 'The From/To year dropdowns offer the years that have records');
    $only2025 = requestPage($client, $baseUrl, 'admin_analytics.php?' . http_build_query(['from_year' => '2025', 'to_year' => '2025']));
    check(departmentRow($only2025, $departmentA) === [1, 1] && departmentRow($only2025, $departmentB) === [1, 2], '2025 only: department A has 1 thesis, department B 1');
    check(cellOn($only2025, '2024', $departmentA) === -1 && strpos($only2025['body'], 'data-year-range>in 2025<') !== false, '2024 is left out and the summary says "in 2025"');
    check(strpos($only2025['body'], 'from_year=2025&amp;to_year=2025&amp;download=csv') !== false, 'The download link keeps the year range');
    $defense2025 = requestPage($client, $baseUrl, 'admin_analytics.php?' . http_build_query(['basis' => 'defense', 'from_year' => '2025', 'to_year' => '2025']));
    check(departmentRow($defense2025, $departmentA) === [2, 3], '2025 by defense year: the late-defended record is included');
    $upTo2024 = requestPage($client, $baseUrl, 'admin_analytics.php?' . http_build_query(['to_year' => '2024']));
    check(departmentRow($upTo2024, $departmentA) === [2, 5] && departmentRow($upTo2024, $departmentB) === null, 'Up to 2024: only the two 2024 records of department A');
    $swappedYears = requestPage($client, $baseUrl, 'admin_analytics.php?' . http_build_query(['from_year' => '2025', 'to_year' => '2024']));
    check(departmentRow($swappedYears, $departmentA) === [3, 6], 'Years chosen the wrong way round are swapped, not empty');
    $rangeCsv = requestPage($client, $baseUrl, 'admin_analytics.php?' . http_build_query(['from_year' => '2025', 'to_year' => '2025', 'download' => 'csv']));
    $rangeLines = explode("\r\n", rtrim(substr($rangeCsv['body'], 3), "\r\n"));
    check(strpos($rangeCsv['headers'], 'filename="research_totals_by_thesis_year_2025-2025.csv"') !== false
        && count($rangeLines) === 3 && str_starts_with($rangeLines[1], '2025,'), 'The CSV for 2025 has only the 2025 row and the total, and says so in its name');

    showStep('Check the frozen Year column.');
    check(strpos($page['body'], 'frozen-first-column') !== false && substr_count($page['body'], 'frozen-cell"') >= 3
        && strpos($page['body'], 'position: sticky') !== false, 'The Year heading, each year and the Total row stay fixed while the departments scroll');

    showStep('Download the CSV.');
    $csv = requestPage($client, $baseUrl, 'admin_analytics.php?basis=thesis&scope=active&download=csv');
    check($csv['status'] === 200 && stripos($csv['headers'], 'Content-Type: text/csv; charset=utf-8') !== false, 'Download is a UTF-8 CSV file');
    check(strpos($csv['headers'], 'filename="research_totals_by_thesis_year.csv"') !== false, 'File name says how years were counted');
    $lines = explode("\r\n", rtrim(substr($csv['body'], 3), "\r\n"));
    $headings = str_getcsv($lines[0], ',', '"', '');
    $columnA = array_search($departmentA, $headings, true);
    $columnB = array_search("'" . $departmentB, $headings, true);
    check($headings[0] === 'Year' && $columnA !== false && $columnB !== false, 'Headings: Year and each department; the formula-looking one starts with an apostrophe');
    $rows = [];
    foreach (array_slice($lines, 1) as $line) {
        $cells = str_getcsv($line, ',', '"', '');
        $rows[$cells[0]] = $cells;
    }
    check($rows['2024'][$columnA] === '2' && $rows['2025'][$columnA] === '1' && $rows['2025'][$columnB] === '1' && $rows['2024'][$columnB] === '0', 'Year rows hold the departments\' numbers');
    check($rows['Total'][$columnA] === '3' && $rows['Total'][$columnB] === '1', 'Total row holds the department totals');
    check(requestPage($client, $baseUrl, 'admin_analytics.php', ['x' => '1'])['status'] === 405, 'A POST is refused');
} catch (Throwable $exception) {
    $failed = true;
    echo 'FAIL: ' . $exception->getMessage() . "\n";
} finally {
    echo "\nCLEANUP: Remove this script's records, permission switch, accounts, made-up role and history.\n";
    putenv('SCHOLAR_ADMIN_PASSWORD');
    unset($client);
    $thesisIds = $pdo->prepare('SELECT id FROM theses WHERE title LIKE :prefix');
    $thesisIds->execute(['prefix' => $titlePrefix . ' %']);
    foreach ($thesisIds->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo->prepare("DELETE FROM audit_logs WHERE entity_type = 'thesis' AND entity_id = :id")->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM thesis_authors WHERE thesis_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM theses WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ([$username, $noRoleUsername] as $name) {
        $pdo->prepare('DELETE FROM failed_logins WHERE account_key = :key')->execute(['key' => LoginThrottle::accountKey($name)]);
        $userId = accountId($pdo, $name);
        if ($userId > 0) {
            $pdo->prepare('DELETE FROM user_revoked_permissions WHERE user_id = :id')->execute(['id' => $userId]);
            $pdo->prepare("DELETE FROM audit_logs WHERE actor_user_id = :actor OR (entity_type = 'user' AND entity_id = :entity)")
                ->execute(['actor' => $userId, 'entity' => $userId]);
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
        }
    }
    $pdo->prepare('DELETE FROM roles WHERE code = :code')->execute(['code' => $noRoleCode]);
    if (accountId($pdo, $username) !== 0 || accountId($pdo, $noRoleUsername) !== 0) {
        $failed = true;
        echo "FAIL: cleanup left temporary accounts behind\n";
    } else {
        echo "PASS: temporary records, accounts and role removed\n";
    }
}

if ($failed) {
    echo "RESULT: research analytics checks failed.\n";
    exit(1);
}
echo "RESULT: research analytics checks passed.\n";
exit(0);
