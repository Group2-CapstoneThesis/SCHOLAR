<?php
// WHAT THIS FILE DOES: tries to get original thesis files (and other private files) the way an outsider would,
// through our local Apache, and checks that every attempt is refused. It only reads; it creates nothing.

// STEP 1: Allow terminal use only, load the application, and connect to the development database.
// STEP 2: Ask Laragon's default "localhost" site (which serves all of C:/laragon/www) for .env and every file in storage/.
// STEP 3: Repeat with tricks: a different letter case, URL-encoded slashes, a trailing dot, "../" from the public site.
// STEP 4: Open the RDPO PDF and viewer pages without logging in; both must send the visitor to the login page.
// STEP 5: Check the public pages contain no PDF, viewer or download link, and relevance.php never answers with a file.
// STEP 6: Check that unpublished records stay hidden from thesis.php and relevance.php.
// Exit code 0 means every check passed; 1 means a check failed.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

function showStep(string $action): void {
    static $stepNumber = 0;
    $stepNumber++;
    echo "\nSTEPS $stepNumber: $action\n";
}

function check(bool $condition, string $description): void {
    if (!$condition) {
        throw new RuntimeException($description);
    }
    echo "PASS: $description\n";
}

// one GET (or POST) without cookies; returns ['status' => int, 'type' => string, 'location' => string, 'body' => string]
function fetchUrl(string $url, bool $post = false): array {
    $client = curl_init($url);
    curl_setopt_array($client, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_PROXY => '',
        CURLOPT_POST => $post,
        CURLOPT_PATH_AS_IS => true, // send "../" and encoded paths exactly, like an attacker would
    ]);
    $response = (string) curl_exec($client);
    $headerSize = (int) curl_getinfo($client, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $location = '';
    if (preg_match('/^Location:\s*(.+)$/mi', $headers, $match)) {
        $location = trim($match[1]);
    }
    return [
        'status' => (int) curl_getinfo($client, CURLINFO_HTTP_CODE),
        'type' => (string) curl_getinfo($client, CURLINFO_CONTENT_TYPE),
        'location' => $location,
        'body' => substr($response, $headerSize),
    ];
}

$exitCode = 0;
try {
    showStep('Connect to the development database and list the private files.');
    $pdo = connectDatabase();
    $projectRoot = realpath(__DIR__ . '/..');
    $privateFiles = ['.env', 'composer.json', 'config/local.php'];
    $storage = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot . '/storage', FilesystemIterator::SKIP_DOTS));
    foreach ($storage as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($projectRoot) + 1));
        if ($file->getFilename() !== '.htaccess' && count($privateFiles) < 40) {
            $privateFiles[] = $relative;
        }
    }
    check(count($privateFiles) > 3, 'Found private files to try (' . count($privateFiles) . ')');

    showStep('Ask the default localhost site for every private file.');
    foreach ($privateFiles as $relative) {
        $answer = fetchUrl('http://localhost/scholar/' . str_replace('%2F', '/', rawurlencode($relative)));
        check($answer['status'] !== 200 && !str_contains($answer['type'], 'pdf'), "localhost/scholar/$relative is refused (status {$answer['status']})");
    }

    showStep('Try path tricks against storage and the project root.');
    $pdfs = array_values(array_filter($privateFiles, fn ($path) => str_ends_with($path, '.pdf')));
    $samplePdf = $pdfs[0] ?? 'storage/theses/sample.pdf';
    $tricks = [
        'http://localhost/scholar/' . strtoupper(substr($samplePdf, 0, 7)) . substr($samplePdf, 7), // STORAGE/...
        'http://localhost/scholar/' . str_replace('/', '%2F', $samplePdf),
        'http://localhost/scholar/' . $samplePdf . '.',
        'http://localhost/scholar/storage/',
        'http://localhost/scholar/storage/theses/',
        'http://scholar.localhost/../storage/' . substr($samplePdf, 8),
        'http://scholar.localhost/%2e%2e/storage/' . substr($samplePdf, 8),
        'http://scholar.localhost/../.env',
    ];
    foreach ($tricks as $url) {
        $answer = fetchUrl($url);
        check($answer['status'] !== 200 && !str_contains($answer['type'], 'pdf'), "$url is refused (status {$answer['status']})");
    }

    showStep('Open the RDPO PDF pages without logging in.');
    foreach (['admin_thesis_pdf.php?id=1', 'admin_thesis_viewer.php?id=1', 'admin_thesis_upload.php'] as $page) {
        $answer = fetchUrl('http://scholar.localhost/' . $page);
        check(in_array($answer['status'], [302, 303], true) && str_contains($answer['location'], 'login.php') && !str_contains($answer['type'], 'pdf'),
            "$page sends a visitor to the login page");
    }

    showStep('Check the public pages offer no file access.');
    $publicPages = ['index.php', 'index.php?q=flood+monitoring', 'thesis.php?id=1'];
    foreach ($publicPages as $page) {
        $body = fetchUrl('http://scholar.localhost/' . $page)['body'];
        $bodyWithoutComments = preg_replace('/<!--.*?-->/s', '', $body);
        check(!preg_match('/admin_thesis_pdf|admin_thesis_viewer|\.pdf|storage\/|download|view (paper|file)/i', $bodyWithoutComments),
            "$page has no PDF, viewer, storage or download link");
    }
    $explanation = fetchUrl('http://scholar.localhost/relevance.php?q=flood+monitoring&id=1');
    check(str_contains($explanation['type'], 'application/json') && !preg_match('/\.pdf|storage|filename/i', $explanation['body']),
        'relevance.php answers JSON only, never a file or file location');
    check(fetchUrl('http://scholar.localhost/relevance.php?q=flood&id=1', true)['status'] === 405, 'relevance.php refuses POST');
    check(fetchUrl('http://scholar.localhost/relevance.php?q=&id=1')['status'] === 400, 'relevance.php refuses an empty query');

    showStep('Check unpublished and archived records stay hidden.');
    $hiddenIds = $pdo->query('SELECT id FROM theses WHERE is_public = 0 OR archived_at IS NOT NULL ORDER BY id LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
    check(count($hiddenIds) > 0, 'Found hidden records to try');
    foreach ($hiddenIds as $hiddenId) {
        check(fetchUrl('http://scholar.localhost/thesis.php?id=' . $hiddenId)['status'] === 404, "thesis.php hides record $hiddenId");
        check(fetchUrl('http://scholar.localhost/relevance.php?q=thesis&id=' . $hiddenId)['status'] === 404, "relevance.php will not explain hidden record $hiddenId");
    }
    echo "\nRESULT: all file-protection checks passed.\n";
} catch (Throwable $exception) {
    echo 'FAIL: ' . $exception->getMessage() . "\n";
    $exitCode = 1;
}
exit($exitCode);
