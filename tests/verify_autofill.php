<?php
// WHAT THIS FILE DOES: automatically checks "Fill in from the thesis PDF" on the New record form through our local Apache.
// It uses made up accounts and small generated PDFs in the WCC layout.

// STEP 1: Allow terminal use only, load the application, and connect to the development database.
// STEP 2: Create a temporary RDPO administrator and a temporary account whose made-up role has no permissions.
// STEP 3: The no-permission account tries "Read details"; it must be refused.
// STEP 4: As RDPO, send a text file named .pdf; it must be refused with a red message and nothing kept.
// STEP 5: Send a PDF with no text (like a scan); the form must say nothing could be read but still offer to attach it.
// STEP 6: Send a generated thesis PDF; check the filled fields, the tint on them, and the "Attach" checkbox.
// STEP 7: Save with a token that does not match the waiting PDF; the record is created but nothing is attached.
// STEP 8: Read again and save with "Attach" ticked; the record gets exactly that PDF and the waiting copy is removed.
// STEP 9: Read again and save with "Attach" unticked; no PDF is attached and the waiting copy is removed.
// STEP 10: In cleanup, remove only this script's records, stored and waiting files, rows, accounts and made-up role.

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
// $form is null for GET, an array of fields for a normal form, or (with $isUpload) an array containing a CURLFile
function requestPage(CurlHandle $client, string $baseUrl, string $page, $form = null, bool $isUpload = false): array {
    $isPost = false;
    if ($form !== null) {
        $isPost = true;
    }
    curl_setopt_array($client, [
        CURLOPT_URL => $baseUrl . $page,
        CURLOPT_POST => $isPost,
        CURLOPT_RETURNTRANSFER => true, // return the response instead of printing page HTML
        CURLOPT_HEADER => true, // include headers so redirects can be inspected
        CURLOPT_FOLLOWLOCATION => false, // check the original redirect before following it
        CURLOPT_TIMEOUT => 20,
        CURLOPT_PROXY => '', // send local test requests directly to Apache
    ]);
    if ($form !== null) {
        if ($isUpload) {
            curl_setopt($client, CURLOPT_POSTFIELDS, $form); // sent as multipart, like a file form
        } else {
            curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($form)); // encode fields like a browser form
        }
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

// the hidden token that ties the main form to the waiting PDF ('' when no PDF is waiting)
function pendingTokenFrom(array $response): string {
    if (!preg_match('/name="pending_token" value="([a-f0-9]{32})"/', $response['body'], $matches)) {
        return '';
    }
    return $matches[1];
}

// the value= of one text input in the returned form, decoded back to plain text
function inputValue(array $response, string $field): string {
    if (!preg_match('/id="' . $field . '"[^>]*value="([^"]*)"/s', $response['body'], $matches)) {
        return '';
    }
    return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
    curl_setopt($client, CURLOPT_COOKIEFILE, '');
    curl_setopt($client, CURLOPT_RESOLVE, ['scholar.localhost:80:127.0.0.1']);
    return $client;
}

// sends a file through the "Read details" form with a fresh security token
function readDetails(CurlHandle $client, string $baseUrl, string $localPath, string $uploadName): array {
    $form = requestPage($client, $baseUrl, 'admin_thesis_form.php');
    return requestPage($client, $baseUrl, 'admin_thesis_form.php', [
        'csrf_token' => tokenFrom($form),
        'form_action' => 'read_pdf',
        'thesis_pdf' => new CURLFile($localPath, 'application/pdf', $uploadName),
    ], true);
}

// builds a small but complete PDF; each page is a list of [x, y, text] pieces drawn in Helvetica (same as unit_autofill_rules.php)
function makePdf(array $pages): string {
    $objects = [];
    $objects[1] = '<</Type/Catalog/Pages 2 0 R>>';
    $objects[3] = '<</Type/Font/Subtype/Type1/BaseFont/Helvetica/Encoding/WinAnsiEncoding>>';
    $pageIds = [];
    $nextId = 4;
    foreach ($pages as $pieces) {
        $stream = '';
        foreach ($pieces as $piece) {
            $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $piece[2]);
            $stream .= 'BT /F1 11 Tf ' . $piece[0] . ' ' . $piece[1] . ' Td (' . $text . ") Tj ET\n";
        }
        $contentId = $nextId;
        $pageId = $nextId + 1;
        $nextId += 2;
        $objects[$contentId] = '<</Length ' . strlen($stream) . ">>\nstream\n" . $stream . 'endstream';
        $objects[$pageId] = '<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Resources<</Font<</F1 3 0 R>>>>/Contents ' . $contentId . ' 0 R>>';
        $pageIds[] = $pageId . ' 0 R';
    }
    $objects[2] = '<</Type/Pages/Kids[' . implode(' ', $pageIds) . ']/Count ' . count($pageIds) . '>>';
    ksort($objects);
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xrefStart = strlen($pdf);
    $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= 'trailer<</Size ' . (count($objects) + 1) . "/Root 1 0 R>>\nstartxref\n" . $xrefStart . "\n%%EOF\n";
    return $pdf;
}

// lines stacked down the page from the top, 16 points apart, plus the right-margin banner letters
function pageWithBanner(array $lines): array {
    $pieces = [];
    $y = 740;
    foreach ($lines as $line) {
        $pieces[] = [72, $y, $line];
        $y -= 16;
    }
    $bannerY = 620;
    foreach (str_split('COLLEGEOFINFORMATIONTECHNOLOGY') as $letter) {
        $pieces[] = [565, $bannerY, $letter];
        $bannerY -= 14;
    }
    return $pieces;
}

// use our existing Laragon Apache; an alternative local URL can be the first argument
$baseUrl = 'http://scholar.localhost';
if (isset($argv[1])) {
    $baseUrl = $argv[1];
}
$baseUrl = rtrim($baseUrl, '/') . '/';
$pdo = connectDatabase();
$storageFolder = __DIR__ . '/../storage/theses';
$pendingFolder = $storageFolder . '/pending';

$suffix = bin2hex(random_bytes(6));
$username = 'verify_autofill_' . $suffix;
$email = $username . '@example.invalid';
$password = bin2hex(random_bytes(20)); // never print or commit the temporary password
$noRoleUsername = 'verify_autofillnorole_' . $suffix;
$noRoleCode = 'verify_autofillnorole_' . $suffix;
$marker = 'zqauto' . $suffix; // only this script's saved records contain this word
$seenPendingTokens = []; // every waiting-PDF token this script saw, so cleanup can remove leftovers

// generated sample files in a temporary folder
$workFolder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scholar_autofill_' . $suffix;
mkdir($workFolder);
$thesisPdf = $workFolder . DIRECTORY_SEPARATOR . 'thesis.pdf';
file_put_contents($thesisPdf, makePdf([
    pageWithBanner(['WORLD CITI COLLEGES', 'Sample Study: Autofill Check', 'for a Fictional Library', 'A Thesis Presented to',
        'the Faculty of College of Information Technology', 'In Partial Fulfillment of the Requirements for the Degree of',
        'Bachelor of Science in Information Technology', 'by', 'Dela Cruz, Maria Clara S.', 'Reyes, Jose Miguel T.', 'August, 2025']),
    pageWithBanner(['WORLD CITI COLLEGES ii', 'Approval Sheet', 'Prof. Sample Adviser', 'Adviser', 'Date of Final Defense: March 3, 2025']),
    pageWithBanner(['WORLD CITI COLLEGES iii', 'Abstract', 'This made-up study checks autofill on the New record form.',
        'Keywords: autofill, fictional library']),
]));
$blankPdf = $workFolder . DIRECTORY_SEPARATOR . 'scan.pdf';
file_put_contents($blankPdf, makePdf([[]])); // a PDF page with no text, like a scanned image
$textFile = $workFolder . DIRECTORY_SEPARATOR . 'notes.pdf';
file_put_contents($textFile, 'Plain text pretending to be a PDF.');

$client = newClient();
$failed = false;
try {
    echo "PREPARATION: Create this script's temporary accounts.\n";
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
    $pdo->prepare('INSERT INTO roles (code, label) VALUES (:code, :label)')->execute(['code' => $noRoleCode, 'label' => 'Temporary test role without permissions']);
    $noRoleId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO users (role_id, username, email, password_hash) VALUES (:role_id, :username, :email, :password_hash)')->execute([
        'role_id' => $noRoleId, 'username' => $noRoleUsername, 'email' => $noRoleUsername . '@example.invalid',
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    showStep('The no-permission account tries "Read details".');
    $noRoleClient = newClient();
    try {
        check(submitLogin($noRoleClient, $baseUrl, $noRoleUsername, $password)['status'] === 303, 'No-permission account can log in');
        $response = requestPage($noRoleClient, $baseUrl, 'admin_thesis_form.php', [
            'form_action' => 'read_pdf', 'thesis_pdf' => new CURLFile($thesisPdf, 'application/pdf', 'thesis.pdf'),
        ], true);
        check($response['status'] === 403, 'Read details refused with 403');
    } finally {
        unset($noRoleClient); // releases the handle (and on Windows, the open sample file)
    }

    showStep('As RDPO, send a text file named .pdf.');
    check(submitLogin($client, $baseUrl, $username, $password)['status'] === 303, 'RDPO administrator logs in');
    $form = requestPage($client, $baseUrl, 'admin_thesis_form.php');
    check(strpos($form['body'], 'Fill in from the thesis PDF') !== false && pendingTokenFrom($form) === '', 'New record form offers autofill and has no waiting PDF yet');
    $response = readDetails($client, $baseUrl, $textFile, 'notes.pdf');
    check($response['status'] === 422 && strpos($response['body'], 'alert-danger') !== false
        && strpos($response['body'], 'not a real PDF') !== false && pendingTokenFrom($response) === '', 'Fake PDF refused in red and nothing kept');

    showStep('Send a PDF with no text, like a scanned image.');
    $response = readDetails($client, $baseUrl, $blankPdf, 'scan.pdf');
    $scanToken = pendingTokenFrom($response);
    $seenPendingTokens[] = $scanToken;
    check($response['status'] === 200 && strpos($response['body'], 'No text could be read') !== false, 'Unreadable PDF explained');
    check($scanToken !== '' && strpos($response['body'], 'Attach <strong>scan.pdf</strong>') !== false, 'It is still offered for attaching');
    check(is_file($pendingFolder . '/' . $scanToken . '.pdf'), 'The waiting copy is kept in the private pending folder');

    showStep('Send a generated thesis PDF.');
    $response = readDetails($client, $baseUrl, $thesisPdf, 'thesis.pdf');
    $firstToken = pendingTokenFrom($response);
    $seenPendingTokens[] = $firstToken;
    check($response['status'] === 200 && strpos($response['body'], 'Filled 9 fields from') !== false, 'Nine fields filled');
    check(inputValue($response, 'title') === 'Sample Study: Autofill Check for a Fictional Library', 'Title filled without banner letters');
    check(inputValue($response, 'adviser') === 'Prof. Sample Adviser' && inputValue($response, 'final_defense_date') === '2025-03-03'
        && inputValue($response, 'publication_year') === '2025' && inputValue($response, 'page_count') === '3', 'Adviser, defense date, year and page count filled');
    check(strpos($response['body'], "Dela Cruz, Maria Clara S.\nReyes, Jose Miguel T.") !== false, 'Authors filled one per line');
    check(substr_count($response['body'], 'bg-info-subtle') >= 9, 'Filled fields are tinted for review');
    check(!is_file($pendingFolder . '/' . $scanToken . '.pdf') && is_file($pendingFolder . '/' . $firstToken . '.pdf'), 'The newer PDF replaced the earlier waiting one');

    // the fields RDPO would save after checking them; the marker keeps cleanup to this script's records
    $savedFields = [
        'title' => 'Sample Study: Autofill Check ' . $marker,
        'authors' => "Dela Cruz, Maria Clara S.\nReyes, Jose Miguel T.",
        'publication_year' => '2025',
        'program' => 'Bachelor of Science in Information Technology',
        'abstract' => 'This made-up study checks autofill on the New record form.',
    ];

    showStep('Save with a token that does not match the waiting PDF.');
    $response = requestPage($client, $baseUrl, 'admin_thesis_form.php', array_merge($savedFields, [
        'csrf_token' => tokenFrom($response), 'pending_token' => str_repeat('0', 32), 'attach_pdf' => '1',
    ]));
    check($response['status'] === 303 && preg_match('/Location: admin_thesis\.php\?id=(\d+)/', $response['headers'], $matches) === 1, 'Record created');
    $mismatchThesisId = (int) $matches[1];
    $countFiles = $pdo->prepare('SELECT COUNT(*) FROM thesis_files WHERE thesis_id = :id');
    $countFiles->execute(['id' => $mismatchThesisId]);
    check((int) $countFiles->fetchColumn() === 0 && is_file($pendingFolder . '/' . $firstToken . '.pdf'), 'Nothing attached; the waiting PDF is left for its own form');

    showStep('Read again and save with "Attach" ticked.');
    $response = readDetails($client, $baseUrl, $thesisPdf, 'thesis.pdf');
    $attachToken = pendingTokenFrom($response);
    $seenPendingTokens[] = $attachToken;
    $response = requestPage($client, $baseUrl, 'admin_thesis_form.php', array_merge($savedFields, [
        'csrf_token' => tokenFrom($response), 'pending_token' => $attachToken, 'attach_pdf' => '1',
    ]));
    preg_match('/Location: admin_thesis\.php\?id=(\d+)/', $response['headers'], $matches);
    $attachedThesisId = (int) $matches[1];
    $recordPage = requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $attachedThesisId);
    check(strpos($recordPage['body'], 'Record created and PDF attached.') !== false, 'Record page confirms the attachment');
    $fileRow = $pdo->prepare('SELECT storage_name, sha256, original_filename FROM thesis_files WHERE thesis_id = :id AND replaced_at IS NULL');
    $fileRow->execute(['id' => $attachedThesisId]);
    $attached = $fileRow->fetch();
    check($attached !== false && $attached['sha256'] === hash_file('sha256', $thesisPdf) && $attached['original_filename'] === 'thesis.pdf', 'The exact PDF that was read is attached');
    check(!is_file($pendingFolder . '/' . $attachToken . '.pdf') && is_file($storageFolder . '/' . $attached['storage_name']), 'The waiting copy moved into storage');

    showStep('Read again and save with "Attach" unticked.');
    $response = readDetails($client, $baseUrl, $thesisPdf, 'thesis.pdf');
    $skipToken = pendingTokenFrom($response);
    $seenPendingTokens[] = $skipToken;
    $response = requestPage($client, $baseUrl, 'admin_thesis_form.php', array_merge($savedFields, [
        'csrf_token' => tokenFrom($response), 'pending_token' => $skipToken,
    ]));
    preg_match('/Location: admin_thesis\.php\?id=(\d+)/', $response['headers'], $matches);
    $countFiles->execute(['id' => (int) $matches[1]]);
    check((int) $countFiles->fetchColumn() === 0 && !is_file($pendingFolder . '/' . $skipToken . '.pdf'), 'No PDF attached and the waiting copy removed');
    check(pendingTokenFrom(requestPage($client, $baseUrl, 'admin_thesis_form.php')) === '', 'A fresh New record form has no waiting PDF');

    check(requestPage($client, $baseUrl, 'logout.php', ['csrf_token' => tokenFrom($recordPage)])['status'] === 303, 'Logout succeeds');
} catch (Throwable $exception) {
    $failed = true;
    if ($exception instanceof PDOException) {
        reportFailure($exception); // keep unexpected database details in the private error handler
    } else {
        fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    }
} finally {
    echo "\nCLEANUP: Remove this script's records, stored and waiting files, rows, accounts and made-up role.\n";
    putenv('SCHOLAR_ADMIN_PASSWORD');
    unset($client); // release cURL first: on Windows it keeps uploaded sample files open until the handle is freed
    foreach ($seenPendingTokens as $token) {
        if (preg_match('/\A[a-f0-9]{32}\z/', $token) && is_file($pendingFolder . '/' . $token . '.pdf')) {
            unlink($pendingFolder . '/' . $token . '.pdf');
        }
    }
    $userIds = $pdo->prepare('SELECT id FROM users WHERE username IN (:admin_username, :norole_username)');
    $userIds->execute(['admin_username' => $username, 'norole_username' => $noRoleUsername]);
    foreach ($userIds->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        $thesisIds = $pdo->prepare('SELECT id FROM theses WHERE created_by = :user_id AND title LIKE :marker');
        $thesisIds->execute(['user_id' => $userId, 'marker' => '%' . $marker . '%']);
        foreach ($thesisIds->fetchAll(PDO::FETCH_COLUMN) as $thesisId) {
            $storedNames = $pdo->prepare('SELECT storage_name FROM thesis_files WHERE thesis_id = :id');
            $storedNames->execute(['id' => $thesisId]);
            foreach ($storedNames->fetchAll(PDO::FETCH_COLUMN) as $storedName) {
                if (preg_match('/\A[a-f0-9]{32}\.pdf\z/', $storedName) && is_file($storageFolder . '/' . $storedName)) {
                    unlink($storageFolder . '/' . $storedName);
                }
            }
            $pdo->prepare('DELETE FROM thesis_files WHERE thesis_id = :id')->execute(['id' => $thesisId]);
            $pdo->prepare("DELETE FROM audit_logs WHERE entity_type = 'thesis' AND entity_id = :id")->execute(['id' => $thesisId]);
            $pdo->prepare('DELETE FROM thesis_authors WHERE thesis_id = :id')->execute(['id' => $thesisId]);
            $pdo->prepare('DELETE FROM theses WHERE id = :id')->execute(['id' => $thesisId]);
        }
        $pdo->prepare("DELETE FROM audit_logs WHERE actor_user_id = :actor_id OR (entity_type = 'user' AND entity_id = :entity_id)")
            ->execute(['actor_id' => $userId, 'entity_id' => $userId]);
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
    }
    $pdo->prepare('DELETE FROM roles WHERE code = :code')->execute(['code' => $noRoleCode]);
    foreach ([$thesisPdf, $blankPdf, $textFile] as $sample) {
        if (is_file($sample)) {
            unlink($sample);
        }
    }
    rmdir($workFolder);
}
if ($failed) {
    exit(1); // terminal exit code 1 means the suite failed
}
echo "\nRESULT: Autofill tests passed.\n";
exit(0);
