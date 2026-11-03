<?php
// WHAT THIS FILE DOES: automatically checks PDF upload and the secure PDF viewer through our local Apache.
// It uses made up accounts, a made up thesis record, and tiny generated PDFs; no real manuscript is used.

// STEP 1: Allow terminal use only, load the application, and connect to the development database.
// STEP 2: Create a temporary RDPO administrator, a temporary account whose made-up role has no permissions, and one test thesis.
// STEP 3: Without logging in, request the viewer and the PDF address and try an upload; all must be refused.
// STEP 4: Log in with the no-permission account; the viewer, PDF address and upload must answer 403.
// STEP 5: Log in as RDPO and try bad uploads: no security token, a text file named .pdf, a PDF with a .txt name, and no file.
// STEP 6: Upload a real PDF whose name tries to climb folders; check the random storage name, the cleaned display name and the fingerprint.
// STEP 7: Open the secure viewer and the PDF address; check the bytes, the protective headers and the audit entry.
// STEP 8: Try to reach the stored file directly through both web addresses; both must refuse.
// STEP 9: Publish the test thesis and check that its public page never mentions the PDF.
// STEP 10: Replace the PDF; the new file is served, the old one is kept and marked replaced.
// STEP 11: In cleanup, remove only this script's stored files, rows, accounts and made-up role.

// Each check prints PASS; a failed check stops the remaining checks and reports failure.
// Cleanup is attempted in finally even when a check fails after preparation starts.
// The terminal exit code is 0 for success or 1 for a caught test failure.

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
// form is an array of fields (sent like a normal form), a CURLFile-containing array (sent as a file upload), or null for GET
function requestPage(CurlHandle $client, string $baseUrl, string $page, $form = null, bool $isUpload = false): array {
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
        CURLOPT_TIMEOUT => 20,
        CURLOPT_PROXY => '', // send local test requests directly to Apache
    ];
    curl_setopt_array($client, $options);
    if ($form !== null) {
        if ($isUpload) {
            curl_setopt($client, CURLOPT_POSTFIELDS, $form); // an array with a CURLFile is sent as multipart, like a file form
        } else {
            curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($form)); // encode fields like a browser form
        }
    }
    $response = curl_exec($client);
    if ($response === false) {
        throw new RuntimeException('HTTP request failed.');
    }
    $headerLength = curl_getinfo($client, CURLINFO_HEADER_SIZE);
    // separate the status, headers and body so each check has named values to read
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

// uploads a local file through the real form, using a fresh security token from the record page
// $uploadName is the file name the "browser" reports, which can be anything an attacker likes
function uploadThroughForm(CurlHandle $client, string $baseUrl, int $thesisId, string $localPath, string $uploadName, bool $includeToken = true): array {
    $recordPage = requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $thesisId);
    $fields = ['thesis_pdf' => new CURLFile($localPath, 'application/pdf', $uploadName)];
    if ($includeToken) {
        $fields['csrf_token'] = tokenFrom($recordPage);
    }
    return requestPage($client, $baseUrl, 'admin_thesis_upload.php?id=' . $thesisId, $fields, true);
}

// how many PDF rows the test thesis has, and how many of them are current
function fileCounts(PDO $pdo, int $thesisId): array {
    $statement = $pdo->prepare('SELECT COUNT(*) AS total, SUM(replaced_at IS NULL) AS current FROM thesis_files WHERE thesis_id = :id');
    $statement->execute(['id' => $thesisId]);
    $row = $statement->fetch();
    return ['total' => (int) $row['total'], 'current' => (int) $row['current']];
}

// use our existing Laragon Apache; an alternative local URL can be the first argument
$baseUrl = 'http://scholar.localhost';
if (isset($argv[1])) {
    $baseUrl = $argv[1];
}
$baseUrl = rtrim($baseUrl, '/') . '/';
$pdo = connectDatabase();
$storageFolder = __DIR__ . '/../storage/theses';

$suffix = bin2hex(random_bytes(6));
$username = 'verify_files_' . $suffix;
$email = $username . '@example.invalid';
$password = bin2hex(random_bytes(20)); // never print or commit the temporary password
$noRoleUsername = 'verify_filesnorole_' . $suffix;
$noRoleCode = 'verify_filesnorole_' . $suffix;
$thesisId = 0;

// tiny generated sample files in a temporary folder; the two PDFs differ so replacement can be told apart
$workFolder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scholar_files_' . $suffix;
mkdir($workFolder);
$pdfBody = "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
    . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$firstPdf = $workFolder . DIRECTORY_SEPARATOR . 'first.pdf';
$secondPdf = $workFolder . DIRECTORY_SEPARATOR . 'second.pdf';
$textFile = $workFolder . DIRECTORY_SEPARATOR . 'text.pdf';
file_put_contents($firstPdf, "%PDF-1.4\n% first sample " . $suffix . "\n" . $pdfBody);
file_put_contents($secondPdf, "%PDF-1.4\n% second sample " . $suffix . "\n" . $pdfBody);
file_put_contents($textFile, 'Plain text pretending to be a PDF.');

$client = newClient();
$failed = false;
try {
    echo "PREPARATION: Create this script's temporary accounts and test thesis.\n";
    putenv('SCHOLAR_ADMIN_PASSWORD=' . $password);
    // proc_open starts a child PHP command; the three pipes are its input/output/error streams
    $process = proc_open([PHP_BINARY, __DIR__ . '/../bin/console.php', 'provision-admin', $username, $email],
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

    // a made-up role that Access does not list, so it has no permissions at all
    $pdo->prepare('INSERT INTO roles (code, label) VALUES (:code, :label)')->execute(['code' => $noRoleCode, 'label' => 'Temporary test role without permissions']);
    $noRoleId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO users (role_id, username, email, password_hash) VALUES (:role_id, :username, :email, :password_hash)')->execute([
        'role_id' => $noRoleId, 'username' => $noRoleUsername, 'email' => $noRoleUsername . '@example.invalid',
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    // the test thesis is inserted directly; creating records through the form is covered by verify_thesis_records.php
    $pdo->prepare("INSERT INTO theses (title, publication_year, program, abstract, is_public, created_by) VALUES (:title, 2025, 'BS Information Technology', 'Sample abstract for testing.', 0, :created_by)")
        ->execute(['title' => 'PDF Test Thesis ' . $suffix, 'created_by' => $adminId]);
    $thesisId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO thesis_authors (thesis_id, author_name, author_order) VALUES (:id, 'Sample Tester', 1)")->execute(['id' => $thesisId]);
    check($adminId > 0 && $thesisId > 0, 'Temporary accounts and test thesis prepared');

    showStep('Request the viewer and the PDF address, and try an upload, without logging in.');
    $anonymous = newClient();
    try {
        check(requestPage($anonymous, $baseUrl, 'admin_thesis_viewer.php?id=' . $thesisId)['status'] === 302, 'Viewer redirects visitors to login');
        check(requestPage($anonymous, $baseUrl, 'admin_thesis_pdf.php?id=' . $thesisId)['status'] === 302, 'PDF address redirects visitors to login');
        $response = requestPage($anonymous, $baseUrl, 'admin_thesis_upload.php?id=' . $thesisId, ['thesis_pdf' => new CURLFile($firstPdf, 'application/pdf', 'x.pdf')], true);
        check($response['status'] === 302 && fileCounts($pdo, $thesisId)['total'] === 0, 'Anonymous upload refused and nothing stored');
    } finally {
        unset($anonymous); // PHP releases the handle object without deprecated curl_close
    }

    showStep('Log in with the no-permission account and try the viewer, PDF address and upload.');
    $noRoleClient = newClient();
    try {
        check(submitLogin($noRoleClient, $baseUrl, $noRoleUsername, $password)['status'] === 303, 'No-permission account can log in');
        check(requestPage($noRoleClient, $baseUrl, 'admin_thesis_viewer.php?id=' . $thesisId)['status'] === 403, 'Viewer refused with 403');
        check(requestPage($noRoleClient, $baseUrl, 'admin_thesis_pdf.php?id=' . $thesisId)['status'] === 403, 'PDF address refused with 403');
        $response = requestPage($noRoleClient, $baseUrl, 'admin_thesis_upload.php?id=' . $thesisId, ['thesis_pdf' => new CURLFile($firstPdf, 'application/pdf', 'x.pdf')], true);
        check($response['status'] === 403 && fileCounts($pdo, $thesisId)['total'] === 0, 'Upload refused with 403 and nothing stored');
    } finally {
        unset($noRoleClient);
    }

    showStep('Log in as RDPO and try uploads that must be rejected.');
    check(submitLogin($client, $baseUrl, $username, $password)['status'] === 303, 'RDPO administrator logs in');
    $recordPage = requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $thesisId);
    check(strpos($recordPage['body'], 'No PDF uploaded yet.') !== false, 'Record page says no PDF yet');
    check(uploadThroughForm($client, $baseUrl, $thesisId, $firstPdf, 'thesis.pdf', false)['status'] === 403, 'Upload without security token refused');
    uploadThroughForm($client, $baseUrl, $thesisId, $textFile, 'thesis.pdf');
    $recordPage = requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $thesisId);
    check(strpos($recordPage['body'], 'alert-danger') !== false && strpos($recordPage['body'], 'not a real PDF') !== false, 'Text file named .pdf refused with a red message');
    uploadThroughForm($client, $baseUrl, $thesisId, $firstPdf, 'thesis.txt');
    check(strpos(requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $thesisId)['body'], 'Only PDF files') !== false, 'PDF with a .txt name refused');
    $recordPage = requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $thesisId);
    requestPage($client, $baseUrl, 'admin_thesis_upload.php?id=' . $thesisId, ['csrf_token' => tokenFrom($recordPage)], true);
    check(strpos(requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $thesisId)['body'], 'Choose') !== false, 'Upload with no file explained');
    check(fileCounts($pdo, $thesisId)['total'] === 0, 'No rejected upload was stored');

    showStep('Upload a real PDF whose reported name tries to climb out of the storage folder.');
    // (quote removal is covered by unit_pdf_rules.php; cURL itself escapes quotes in upload names before sending)
    $response = uploadThroughForm($client, $baseUrl, $thesisId, $firstPdf, '../../public/evil name.pdf');
    check($response['status'] === 303, 'Valid PDF accepted');
    $statement = $pdo->prepare('SELECT * FROM thesis_files WHERE thesis_id = :id AND replaced_at IS NULL');
    $statement->execute(['id' => $thesisId]);
    $firstRow = $statement->fetch();
    check(preg_match('/\A[a-f0-9]{32}\.pdf\z/', $firstRow['storage_name']) === 1, 'Stored under a random generated name');
    check($firstRow['original_filename'] === 'evil name.pdf', 'Display name keeps only the last part of the reported name');
    check(is_file($storageFolder . '/' . $firstRow['storage_name']) && !is_file(__DIR__ . '/../public/evil name.pdf'), 'File is in storage/theses, not in public/');
    check($firstRow['sha256'] === hash_file('sha256', $firstPdf) && (int) $firstRow['size_bytes'] === filesize($firstPdf), 'Recorded fingerprint and size match the uploaded file');
    check(strpos(requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $thesisId)['body'], 'Open in secure viewer') !== false, 'Record page offers the secure viewer');

    showStep('Open the secure viewer and the PDF address.');
    $viewer = requestPage($client, $baseUrl, 'admin_thesis_viewer.php?id=' . $thesisId);
    check($viewer['status'] === 200 && strpos($viewer['body'], 'src="admin_thesis_pdf.php?id=' . $thesisId . '"') !== false, 'Viewer page frames the protected PDF address');
    $pdf = requestPage($client, $baseUrl, 'admin_thesis_pdf.php?id=' . $thesisId);
    check($pdf['status'] === 200 && $pdf['body'] === file_get_contents($firstPdf), 'PDF address returns exactly the uploaded bytes');
    check(stripos($pdf['headers'], 'Content-Type: application/pdf') !== false
        && stripos($pdf['headers'], 'Content-Disposition: inline') !== false, 'PDF is sent as an inline PDF');
    check(stripos($pdf['headers'], 'no-store') !== false && stripos($pdf['headers'], 'nosniff') !== false
        && stripos($pdf['headers'], 'X-Frame-Options: SAMEORIGIN') !== false, 'No-cache, no-sniff and same-site-frame headers present');
    check(strpos($pdf['headers'], $firstRow['storage_name']) === false, 'The random storage name is never revealed');
    $audit = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'thesis' AND entity_id = :id AND action_code = 'thesis.pdf_viewed' AND actor_user_id = :actor");
    $audit->execute(['id' => $thesisId, 'actor' => $adminId]);
    check((int) $audit->fetchColumn() === 1, 'Opening the PDF is recorded in the audit history');
    check(requestPage($client, $baseUrl, 'admin_thesis_pdf.php?id=999999999999')['status'] === 404, 'A thesis without a PDF answers 404');

    showStep('Try to reach the stored file directly through both web addresses.');
    check(in_array(requestPage($client, $baseUrl, 'storage/theses/' . $firstRow['storage_name'])['status'], [403, 404], true), 'Application address cannot serve the stored file');
    $defaultRootUrl = 'http://127.0.0.1/' . rawurlencode(basename(dirname(__DIR__, 2))) . '/scholar/';
    check(requestPage($client, $defaultRootUrl, 'storage/theses/' . $firstRow['storage_name'])['status'] === 403, 'Default Apache address refuses the stored file');

    showStep('Publish the test thesis and look at its public page.');
    $pdo->prepare('UPDATE theses SET is_public = 1 WHERE id = :id')->execute(['id' => $thesisId]);
    $public = newClient();
    $publicPage = requestPage($public, $baseUrl, 'thesis.php?id=' . $thesisId);
    unset($public);
    check($publicPage['status'] === 200 && strpos($publicPage['body'], 'admin_thesis_pdf') === false
        && strpos($publicPage['body'], 'evil name.pdf') === false && strpos($publicPage['body'], $firstRow['storage_name']) === false, 'Public page never links or names the PDF');

    showStep('Replace the PDF with a second file.');
    check(uploadThroughForm($client, $baseUrl, $thesisId, $secondPdf, 'corrected.pdf')['status'] === 303, 'Replacement accepted');
    $counts = fileCounts($pdo, $thesisId);
    check($counts['total'] === 2 && $counts['current'] === 1, 'Two files on record, exactly one current');
    check(is_file($storageFolder . '/' . $firstRow['storage_name']), 'The replaced file is kept in storage');
    check(requestPage($client, $baseUrl, 'admin_thesis_pdf.php?id=' . $thesisId)['body'] === file_get_contents($secondPdf), 'The PDF address now serves the new file');
    $recordPage = requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $thesisId);
    check(strpos($recordPage['body'], 'corrected.pdf') !== false && strpos($recordPage['body'], 'replaced previous PDF') !== false, 'Record page shows the new file and the replacement in its history');

    check(requestPage($client, $baseUrl, 'logout.php', ['csrf_token' => tokenFrom($recordPage)])['status'] === 303, 'Logout succeeds');
} catch (Throwable $exception) {
    $failed = true;
    if ($exception instanceof PDOException) {
        reportFailure($exception); // keep unexpected database details in the private error handler
    } else {
        fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    }
} finally {
    // remove only what this script created, in the order the foreign keys require
    echo "\nCLEANUP: Remove this script's stored files, rows, accounts and made-up role.\n";
    putenv('SCHOLAR_ADMIN_PASSWORD');
    if ($thesisId > 0) {
        $storedNames = $pdo->prepare('SELECT storage_name FROM thesis_files WHERE thesis_id = :id');
        $storedNames->execute(['id' => $thesisId]);
        foreach ($storedNames->fetchAll(PDO::FETCH_COLUMN) as $storedName) {
            // only names in our own random format, so cleanup can never delete anything else
            if (preg_match('/\A[a-f0-9]{32}\.pdf\z/', $storedName) && is_file($storageFolder . '/' . $storedName)) {
                unlink($storageFolder . '/' . $storedName);
            }
        }
        $pdo->prepare('DELETE FROM thesis_files WHERE thesis_id = :id')->execute(['id' => $thesisId]);
        $pdo->prepare("DELETE FROM audit_logs WHERE entity_type = 'thesis' AND entity_id = :id")->execute(['id' => $thesisId]);
        $pdo->prepare('DELETE FROM thesis_authors WHERE thesis_id = :id')->execute(['id' => $thesisId]);
        $pdo->prepare('DELETE FROM theses WHERE id = :id')->execute(['id' => $thesisId]);
    }
    $userIds = $pdo->prepare('SELECT id FROM users WHERE username IN (:admin_username, :norole_username)');
    $userIds->execute(['admin_username' => $username, 'norole_username' => $noRoleUsername]);
    foreach ($userIds->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        $pdo->prepare("DELETE FROM audit_logs WHERE actor_user_id = :actor_id OR (entity_type = 'user' AND entity_id = :entity_id)")
            ->execute(['actor_id' => $userId, 'entity_id' => $userId]);
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
    }
    $pdo->prepare('DELETE FROM roles WHERE code = :code')->execute(['code' => $noRoleCode]);
    // release the HTTP client first: on Windows, cURL keeps uploaded sample files open until its handle is freed
    unset($client);
    foreach ([$firstPdf, $secondPdf, $textFile] as $sample) {
        if (is_file($sample)) {
            unlink($sample);
        }
    }
    rmdir($workFolder);
}
if ($failed) {
    exit(1); // terminal exit code 1 means the suite failed
}
echo "\nRESULT: Thesis PDF tests passed.\n";
exit(0);
