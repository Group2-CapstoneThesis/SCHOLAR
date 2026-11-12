<?php
// WHAT THIS FILE DOES: automatically checks backups (M12, FB-04): the Backups page, downloads, the terminal commands,
// and a TESTED RESTORE into a separate temporary database, compared table by table with the development database.
// It sends requests to our local Apache like a browser, using made up accounts and one made-up record only.

// STEP 1: Allow terminal use only, read the database administrator from environment variables (needed to create the
//         temporary database and for restore-backup), load the application and connect.
// STEP 2: Create a temporary RDPO administrator, a temporary head administrator, and one made-up record whose abstract
//         has line breaks, quotes, a backslash and "ñ" (the hardest text for a backup to keep exactly).
//         The head administrator gets a made-up authenticator app (two_factor_helper.php) and types its code at login.
// STEP 3: Without logging in, and as the ordinary administrator, the page and downloads are refused.
// STEP 4: As the head administrator, make a backup on the page; it is listed and the history records it.
// STEP 5: Download it; check the headers, that the bytes equal the file, and that odd names such as "../.env" are refused.
// STEP 6: Terminal: verify-backup passes; a copy with a changed database part fails; restore-backup refuses without
//         --confirm, refuses without the administrator, and refuses the changed copy without touching the database.
// STEP 7: Restore the backup into a temporary database and folder; every table has the same structure and rows
//         (the activity log is compared by count, because this test itself adds to it), the made-up abstract is
//         exactly the same, and every PDF has the same fingerprint.
// STEP 8: In cleanup, remove the temporary database and folder, this script's backups, record, accounts and history.

// Each check prints PASS; a failed check stops the remaining checks and reports failure.
// Cleanup is attempted in finally even when a check fails after preparation starts.
// The terminal exit code is 0 for success, 1 for a caught test failure, or 2 when the administrator is not supplied.

declare(strict_types=1); // reject incorrect types passed into the test helpers

use Scholar\Auth\LoginThrottle;
use Scholar\Backup;
use Scholar\Theses\ThesisRecords;

// run this script from the terminal; it is not a browser page
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/two_factor_helper.php'; // head administrators must type an app code at login

// the temporary database needs an account that may create databases; never assume one
$adminUser = getenv('SCHOLAR_DB_ADMIN_USER');
$adminPassword = getenv('SCHOLAR_DB_ADMIN_PASSWORD');
if ($adminUser === false || trim($adminUser) === '') {
    fwrite(STDERR, "Supply SCHOLAR_DB_ADMIN_USER (and SCHOLAR_DB_ADMIN_PASSWORD) temporarily, as for database_foundation.php.\n");
    exit(2);
}
if ($adminPassword === false) {
    $adminPassword = '';
}

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
        CURLOPT_TIMEOUT => 120, // making a backup copies every PDF
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

// runs one bin/console.php command quietly and returns its exit code; $environment adds temporary variables
function runConsole(array $arguments, array $environment = []): int {
    $variables = getenv(); // start from this process's variables, so PATH and the like are kept
    foreach ($environment as $name => $value) {
        $variables[$name] = $value;
    }
    $process = proc_open(array_merge([PHP_BINARY, __DIR__ . '/../bin/console.php'], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $variables);
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

// a description of one database's table structures, built from information_schema, so two databases can be compared
// (SHOW CREATE TABLE text is not used, because MySQL prints the same column differently depending on how it was made)
function structureOf(PDO $admin, string $database): array {
    $structure = [];
    $queries = [
        'columns' => 'SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME
                      FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db ORDER BY TABLE_NAME, ORDINAL_POSITION',
        'indexes' => 'SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE
                      FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = :db ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
        'foreign_keys' => 'SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                      FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = :db AND REFERENCED_TABLE_NAME IS NOT NULL
                      ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION',
    ];
    foreach ($queries as $part => $sql) {
        $statement = $admin->prepare($sql);
        $statement->execute(['db' => $database]);
        $structure[$part] = $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    return $structure;
}

$baseUrl = 'http://scholar.localhost';
if (isset($argv[1])) {
    $baseUrl = $argv[1];
}
$baseUrl = rtrim($baseUrl, '/') . '/';
$pdo = connectDatabase();
$databaseSettings = loadConfiguration()['database'];
$backup = Backup::create($pdo);
$backupsBefore = array_column($backup->listBackups(), 'file'); // only backups made after this are this script's own

$suffix = bin2hex(random_bytes(6));
$headUsername = 'verify_bkhead_' . $suffix;
$adminUsername = 'verify_bkstaff_' . $suffix;
$password = bin2hex(random_bytes(20)); // never print or commit the temporary password
$hardAbstract = "Line one of a made-up abstract.\nLine two with 'single' and \"double\" quotes, a back\\slash and ñ.\r\nEnd.";
$thesisTitle = 'Verify Backups ' . $suffix;
$temporaryDatabase = 'scholar_verify_' . bin2hex(random_bytes(8));
$temporaryFolder = __DIR__ . '/../storage/test-results/restore_' . $suffix;
$tamperedPath = __DIR__ . '/../storage/test-results/tampered_' . $suffix . '.tar';
$adminConnection = null;
$databaseCreated = false;
$headClient = newClient();
$failed = false;
try {
    echo "PREPARATION: Create this script's temporary accounts and made-up record.\n";
    putenv('SCHOLAR_ADMIN_PASSWORD=' . $password);
    check(runConsole(['provision-admin', $headUsername]) === 0, 'CLI provisions the future head administrator');
    check(runConsole(['provision-admin', $adminUsername]) === 0, 'CLI provisions an ordinary RDPO administrator');
    putenv('SCHOLAR_ADMIN_PASSWORD');
    check(runConsole(['set-role', $headUsername, 'rdpo_head']) === 0, 'CLI set-role makes the head administrator');
    $headId = accountId($pdo, $headUsername);
    $headSecret = linkTestAuthenticator($pdo, $headId); // head administrators must use two-step login
    $records = new ThesisRecords($pdo);
    $thesisId = $records->createThesis([
        'title' => $thesisTitle, 'authors' => [['given_names' => 'Sample', 'last_name' => 'Verifier', 'suffix' => null, 'full_name' => 'Sample Verifier']],
        'publication_year' => 2025, 'program' => 'BS Verification Studies', 'department' => null, 'campus' => 'WCC QC', 'adviser' => null,
        'abstract' => $hardAbstract, 'keywords' => null, 'date_submitted' => null, 'final_defense_date' => null, 'page_count' => null, 'is_public' => false,
    ], $headId);
    check($thesisId > 0, 'Made-up record with a multi-line abstract created');

    showStep('Open the page and a download without permission.');
    $anonymous = newClient();
    check(strpos(requestPage($anonymous, $baseUrl, 'admin_backups.php')['headers'], 'login.php') !== false, 'Page redirects visitors to login');
    $someBackup = 'scholar_backup_2026-01-01_000000_abcdef.tar';
    check(strpos(requestPage($anonymous, $baseUrl, 'admin_backups.php?download=' . $someBackup)['headers'], 'login.php') !== false, 'Download redirects visitors to login');
    unset($anonymous);
    $staffClient = newClient();
    check(submitLogin($staffClient, $baseUrl, $adminUsername, $password)['status'] === 303, 'RDPO administrator logs in');
    check(strpos(requestPage($staffClient, $baseUrl, 'admin.php')['body'], 'href="admin_backups.php"') === false, 'Menu has no "Backups" link');
    check(requestPage($staffClient, $baseUrl, 'admin_backups.php')['status'] === 403, 'Page answers 403');
    check(requestPage($staffClient, $baseUrl, 'admin_backups.php?download=' . $someBackup)['status'] === 403, 'Download answers 403');
    unset($staffClient);

    showStep('Make a backup on the page as the head administrator.');
    $headLogin = answerCodeStep($headClient, $baseUrl, submitLogin($headClient, $baseUrl, $headUsername, $password), $pdo, $headId, $headSecret);
    check($headLogin['status'] === 303 && strpos($headLogin['headers'], 'Location: admin.php') !== false, 'Head administrator logs in with the password and the app code');
    check(strpos(requestPage($headClient, $baseUrl, 'admin.php')['body'], 'href="admin_backups.php"') !== false, 'Menu has a "Backups" link');
    $page = requestPage($headClient, $baseUrl, 'admin_backups.php');
    check($page['status'] === 200 && strpos($page['body'], 'Make a backup now') !== false, 'Page opens');
    check(requestPage($headClient, $baseUrl, 'admin_backups.php', ['action' => 'make_backup'])['status'] === 403, 'Make backup without the form token is refused');
    $made = requestPage($headClient, $baseUrl, 'admin_backups.php', ['action' => 'make_backup', 'csrf_token' => tokenFrom($page)]);
    check($made['status'] === 303, 'Make backup accepted');
    $newBackups = array_values(array_diff(array_column($backup->listBackups(), 'file'), $backupsBefore));
    check(count($newBackups) === 1, 'Exactly one new backup file');
    $backupFile = $newBackups[0];
    $backupPath = $backup->pathOf($backupFile);
    $after = requestPage($headClient, $baseUrl, 'admin_backups.php');
    check(strpos($after['body'], 'Backup made: ' . $backupFile) !== false && strpos($after['body'], 'download=' . $backupFile) !== false, 'Message names it and the list offers it');
    $audit = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action_code = :code AND actor_user_id = :id AND changed_fields = :file");
    $audit->execute(['code' => 'backup.created', 'id' => $headId, 'file' => $backupFile]);
    check((int) $audit->fetchColumn() === 1, 'History records who made it');

    showStep('Download the backup.');
    $download = requestPage($headClient, $baseUrl, 'admin_backups.php?' . http_build_query(['download' => $backupFile]));
    check($download['status'] === 200 && stripos($download['headers'], 'Content-Type: application/x-tar') !== false, 'Download is a .tar file');
    check(strpos($download['headers'], 'attachment; filename="' . $backupFile . '"') !== false && stripos($download['headers'], 'Cache-Control: no-store') !== false, 'Saved under its own name, not cached');
    check(hash('sha256', $download['body']) === hash_file('sha256', $backupPath), 'Downloaded bytes equal the backup file');
    check(substr($download['body'], 257, 5) === 'ustar', 'It is a real tar archive');
    $audit->execute(['code' => 'backup.downloaded', 'id' => $headId, 'file' => $backupFile]);
    check((int) $audit->fetchColumn() === 1, 'History records the download');
    foreach (['../../.env', '..\\..\\.env', 'scholar_backup_2099-01-01_000000_abcdef.tar', $backupFile . '/../x'] as $oddName) {
        check(requestPage($headClient, $baseUrl, 'admin_backups.php?' . http_build_query(['download' => $oddName]))['status'] === 404, 'Download of "' . $oddName . '" refused');
    }
    check(requestPage($headClient, $baseUrl, 'admin_backups.php?download[]=x')['status'] === 404, 'Download name sent as an array refused');

    showStep('Check and restore from the terminal.');
    check(runConsole(['verify-backup', $backupFile]) === 0, 'verify-backup passes');
    copy($backupPath, $tamperedPath);
    $tampered = new PharData($tamperedPath);
    $tampered->addFromString('database.sql.gz', gzencode("DROP TABLE users;\n")); // replaces the database part
    unset($tampered);
    check(runConsole(['verify-backup', $tamperedPath]) === 1, 'verify-backup fails on a copy whose database part was changed');
    check(runConsole(['restore-backup', $backupFile]) === 2, 'restore-backup without --confirm only explains and stops');
    check(runConsole(['restore-backup', $backupFile, '--confirm'], ['SCHOLAR_DB_ADMIN_USER' => '']) === 2, 'restore-backup without the database administrator stops');
    $thesisChecksum = $pdo->query('CHECKSUM TABLE theses')->fetch(PDO::FETCH_NUM)[1];
    $usersBefore = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    check(runConsole(['restore-backup', $tamperedPath, '--confirm'], ['SCHOLAR_DB_ADMIN_USER' => $adminUser, 'SCHOLAR_DB_ADMIN_PASSWORD' => $adminPassword]) === 1, 'restore-backup refuses the changed copy');
    check($pdo->query('CHECKSUM TABLE theses')->fetch(PDO::FETCH_NUM)[1] === $thesisChecksum
        && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $usersBefore, 'The live database was not touched');
    check(count(array_diff(array_column($backup->listBackups(), 'file'), $backupsBefore)) === 1, 'No safety backup was made for a refused restore');

    showStep('Restore into a temporary database and compare.');
    $adminConnection = new PDO('mysql:host=' . $databaseSettings['host'] . ';port=' . $databaseSettings['port'] . ';charset=utf8mb4',
        $adminUser, $adminPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $adminConnection->exec('CREATE DATABASE ' . $temporaryDatabase . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $databaseCreated = true;
    $target = new PDO('mysql:host=' . $databaseSettings['host'] . ';port=' . $databaseSettings['port'] . ';dbname=' . $temporaryDatabase . ';charset=utf8mb4',
        $adminUser, $adminPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $restored = Backup::restore($target, $backupPath, $temporaryFolder);
    $manifest = Backup::check($backupPath)['manifest'];
    check($restored['tables'] === count($manifest['tables']), 'Every table restored');
    check(structureOf($adminConnection, $databaseSettings['name']) === structureOf($adminConnection, $temporaryDatabase), 'Columns, indexes and links are the same as the development database');
    foreach ($manifest['tables'] as $table => $rowCount) {
        $restoredCount = (int) $target->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        if ($table === 'audit_logs' || $table === 'failed_logins') {
            // these change while the test runs (its own history entries), so only the backed-up count is compared
            check($restoredCount === $rowCount, $table . ': the ' . $rowCount . ' backed-up rows restored');
        } else {
            $same = $pdo->query('CHECKSUM TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1] === $target->query('CHECKSUM TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
            check($same && $restoredCount === $rowCount, $table . ': same ' . $rowCount . ' rows, byte for byte');
        }
    }
    $abstract = $target->prepare('SELECT abstract FROM theses WHERE id = :id');
    $abstract->execute(['id' => $thesisId]);
    check($abstract->fetchColumn() === $hardAbstract, 'The multi-line abstract with quotes, backslash and ñ is exactly the same');
    $pdfsSame = count($manifest['files']) === $restored['pdf_count'];
    foreach (array_keys($manifest['files']) as $pdfName) {
        if (hash_file('sha256', $temporaryFolder . DIRECTORY_SEPARATOR . $pdfName) !== hash_file('sha256', __DIR__ . '/../storage/theses/' . $pdfName)) {
            $pdfsSame = false;
        }
    }
    check($pdfsSame, 'Every restored PDF (' . $restored['pdf_count'] . ') has the same fingerprint as the stored one');
} catch (Throwable $exception) {
    $failed = true;
    echo 'FAIL: ' . $exception->getMessage() . "\n";
} finally {
    echo "\nCLEANUP: Remove the temporary database and folder, this script's backups, record, accounts and history.\n";
    putenv('SCHOLAR_ADMIN_PASSWORD');
    unset($headClient);
    if ($databaseCreated && preg_match('/\Ascholar_verify_[a-f0-9]{16}\z/', $temporaryDatabase)) {
        $adminConnection->exec('DROP DATABASE ' . $temporaryDatabase); // only our own temporary database, never scholar_dev
    }
    if (is_dir($temporaryFolder)) {
        foreach (glob($temporaryFolder . DIRECTORY_SEPARATOR . '*') as $file) {
            unlink($file);
        }
        rmdir($temporaryFolder);
    }
    if (is_file($tamperedPath)) {
        unlink($tamperedPath);
    }
    // backups made while this script ran (they hold a copy of the whole development database)
    foreach (array_diff(array_column($backup->listBackups(), 'file'), $backupsBefore) as $file) {
        unlink($backup->pathOf($file));
        $pdo->prepare("DELETE FROM audit_logs WHERE action_code LIKE 'backup.%' AND changed_fields = :file")->execute(['file' => $file]);
    }
    $thesisIds = $pdo->prepare('SELECT id FROM theses WHERE title = :title');
    $thesisIds->execute(['title' => $thesisTitle]);
    foreach ($thesisIds->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo->prepare("DELETE FROM audit_logs WHERE entity_type = 'thesis' AND entity_id = :id")->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM thesis_authors WHERE thesis_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM theses WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ([$headUsername, $adminUsername] as $username) {
        $pdo->prepare('DELETE FROM failed_logins WHERE account_key = :key')->execute(['key' => LoginThrottle::accountKey($username)]);
        $userId = accountId($pdo, $username);
        if ($userId > 0) {
            $pdo->prepare("DELETE FROM audit_logs WHERE actor_user_id = :actor OR (entity_type = 'user' AND entity_id = :entity)")
                ->execute(['actor' => $userId, 'entity' => $userId]);
            forgetTestTwoFactor($pdo, [(int) $userId]); // backup codes and remembered devices go before the account
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
        }
    }
    if (accountId($pdo, $headUsername) !== 0 || accountId($pdo, $adminUsername) !== 0
        || count(array_diff(array_column($backup->listBackups(), 'file'), $backupsBefore)) !== 0) {
        $failed = true;
        echo "FAIL: cleanup left temporary accounts or backups behind\n";
    } else {
        echo "PASS: temporary accounts, record, database and backups removed\n";
    }
}

if ($failed) {
    echo "RESULT: backup checks failed.\n";
    exit(1);
}
echo "RESULT: backup checks passed.\n";
exit(0);
