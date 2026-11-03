<?php
// WHAT THIS FILE DOES: automatically checks the public search page the way an ordinary visitor (or a curious one) would use it.
// It sends requests to our local Apache like a browser. It only reads; it creates nothing.

// STEP 1: Allow terminal use only, load the application, and connect to the development database.
// STEP 2: Open the search page and compare its total with the number of published, non-archived records in the database.
// STEP 3: Filter by a year and by a program, and check that every result shown matches the filter.
// STEP 4: Ask for a page far past the end; the last page must be shown instead of an error or an empty list.
// STEP 5: Send unusual search text (SQL-looking text, a lone %, very long text) and a made-up sort; the page must stay normal.
// STEP 6: Check that an unpublished seeded record can be found neither by search nor by its public page address.
// STEP 7: Request public thesis pages with malformed ids, and POST to the search page; all must be refused safely.

// The checks compare against whatever records the database holds, so they keep working as RDPO adds real records.
// Each check prints PASS; a failed check stops the remaining checks and reports failure.
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

// reads the "of 23 theses" total from the results line; 0 when the page shows the empty-state message
function totalShown(array $response): int {
    if (preg_match('/of (\d+) (thesis|theses)/', $response['body'], $matches)) {
        return (int) $matches[1];
    }
    return 0;
}

// reads the "2024 &middot; BS Information Technology" line of every result card
// returns a list like [['year' => '2024', 'program' => 'BS Information Technology'], ...]
function resultYearsAndPrograms(array $response): array {
    preg_match_all('/<div class="small text-secondary mb-2">(\d{4}) &middot; ([^<]*)<\/div>/', $response['body'], $matches, PREG_SET_ORDER);
    $results = [];
    foreach ($matches as $match) {
        // the page escapes program names, so turn &amp; and similar back into normal text before comparing
        $results[] = ['year' => $match[1], 'program' => html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')];
    }
    return $results;
}

// use our existing Laragon Apache; an alternative local URL can be the first argument
$baseUrl = 'http://scholar.localhost';
if (isset($argv[1])) {
    $baseUrl = $argv[1];
}
$baseUrl = rtrim($baseUrl, '/') . '/';
$pdo = connectDatabase();
$client = curl_init();
curl_setopt($client, CURLOPT_RESOLVE, ['scholar.localhost:80:127.0.0.1']);
$failed = false;
try {
    // the same rule the application uses: published and not archived
    $visibleCount = (int) $pdo->query('SELECT COUNT(*) FROM theses WHERE is_public = 1 AND archived_at IS NULL')->fetchColumn();
    check($visibleCount > 0, 'At least one published record exists to search (run bin/console.php seed if not)');

    showStep('Open the public search page with no filters.');
    $response = requestPage($client, $baseUrl, 'index.php');
    check($response['status'] === 200, 'Search page opens without logging in');
    check(totalShown($response) === $visibleCount, 'Total shown equals the published, non-archived records in the database');
    check(strpos($response['headers'], 'scholar_session') === false, 'Search page sets no session cookie');

    showStep('Filter by one year, then by one program.');
    $sample = $pdo->query('SELECT publication_year, program FROM theses WHERE is_public = 1 AND archived_at IS NULL ORDER BY id LIMIT 1')->fetch();
    $response = requestPage($client, $baseUrl, 'index.php?year=' . $sample['publication_year']);
    $shown = resultYearsAndPrograms($response);
    $allMatchYear = count($shown) > 0;
    foreach ($shown as $result) {
        if ($result['year'] !== (string) $sample['publication_year']) {
            $allMatchYear = false;
        }
    }
    check($allMatchYear, 'Every result matches the chosen year');
    $response = requestPage($client, $baseUrl, 'index.php?' . http_build_query(['program' => $sample['program']]));
    $shown = resultYearsAndPrograms($response);
    $allMatchProgram = count($shown) > 0;
    foreach ($shown as $result) {
        if ($result['program'] !== $sample['program']) {
            $allMatchProgram = false;
        }
    }
    check($allMatchProgram, 'Every result matches the chosen program');

    showStep('Ask for page 9999 of the results.');
    $response = requestPage($client, $baseUrl, 'index.php?page=9999');
    check($response['status'] === 200 && count(resultYearsAndPrograms($response)) > 0, 'A page past the end shows the last page of results');

    showStep('Send unusual search text and a made-up sort.');
    foreach (["' OR 1=1 --", '%', '_', str_repeat('long ', 300)] as $text) {
        $response = requestPage($client, $baseUrl, 'index.php?' . http_build_query(['q' => $text]));
        check($response['status'] === 200 && totalShown($response) < $visibleCount, 'Unusual text "' . substr($text, 0, 12) . '..." searched literally without error');
    }
    $response = requestPage($client, $baseUrl, 'index.php?sort=id;DROP&year=abcd&page=-1');
    check($response['status'] === 200 && totalShown($response) === $visibleCount, 'Invalid sort, year and page fall back to defaults');
    $response = requestPage($client, $baseUrl, 'index.php?q[]=array');
    check($response['status'] === 200, 'Array-shaped search text is ignored safely');

    showStep('Look for the unpublished seeded record.');
    $hidden = $pdo->query("SELECT id FROM theses WHERE fixture_key = 'synthetic_catalog_12' AND is_public = 0")->fetchColumn();
    if ($hidden === false) {
        echo "NOTE: The unpublished seeded record is not present; this step is skipped.\n";
    } else {
        $response = requestPage($client, $baseUrl, 'index.php?q=Unpublished+Draft'); // words from the seeded record's title
        check(strpos($response['body'], 'No theses match') !== false, 'Unpublished record is not found by search');
        check(requestPage($client, $baseUrl, 'thesis.php?id=' . $hidden)['status'] === 404, 'Unpublished record public page answers 404');
    }

    showStep('Request malformed public page ids and POST to the search page.');
    foreach (['0', '-1', '1abc', '99999999999999999999', ''] as $badId) {
        $response = requestPage($client, $baseUrl, 'thesis.php?id=' . rawurlencode($badId));
        check($response['status'] === 404 && strpos($response['body'], 'Thesis not found') !== false, 'Public page id "' . $badId . '" answers 404');
    }
    check(requestPage($client, $baseUrl, 'index.php', ['q' => 'x'])['status'] === 405, 'POST to the search page refused');
} catch (Throwable $exception) {
    $failed = true;
    if ($exception instanceof PDOException) {
        reportFailure($exception); // keep unexpected database details in the private error handler
    } else {
        fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    }
} finally {
    echo "\nCLEANUP: Nothing was created; release the HTTP client.\n";
    unset($client); // PHP releases CurlHandle objects when no references remain
}
if ($failed) {
    exit(1); // terminal exit code 1 means the suite failed
}
echo "\nRESULT: Public search tests passed.\n";
exit(0);
