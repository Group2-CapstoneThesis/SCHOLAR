<?php
// WHAT THIS FILE DOES: automatically checks semantic (meaning-based) search end to end (M14), with the real
// Ollama (bge-m3) and Qdrant services. It sends requests to our local Apache like a browser, using a made up account
// and one made up record about a beehive temperature logger.

// STEP 1: Allow terminal use only, load the application, and check that Ollama and Qdrant answer (exit 2 if not).
// STEP 2: Create a temporary RDPO administrator and log in.
// STEP 3: Create the made-up record through the New record form; it must be put into the search index on save.
// STEP 4: RDPO search for "insect farm climate sensors" (no word in common with the record) finds it as a
//         "Similar topic"; a search with a word from its title finds it as a plain word match.
// STEP 5: The public search does not find it while it is unpublished, and does after Publish.
// STEP 6: After Archive, neither the public search nor RDPO's default list finds it; RDPO's "Archived" filter does.
// STEP 7: With Ollama unreachable, saving marks the record "not included yet" and searching falls back to words only;
//         with Ollama back, the record is indexed again.
// STEP 8: In cleanup, remove the record (and its search entry), the account, history and failed-login counters.

// Each check prints PASS; a failed check stops the remaining checks and reports failure.
// Cleanup is attempted in finally even when a check fails after preparation starts.
// The terminal exit code is 0 for success, 1 for a caught test failure, or 2 when Ollama or Qdrant is not running.

declare(strict_types=1); // reject incorrect types passed into the test helpers

use Scholar\Auth\LoginThrottle;
use Scholar\Search\EmbeddingClient;
use Scholar\Search\QdrantClient;
use Scholar\Search\SearchIndex;
use Scholar\Search\SearchUnavailable;
use Scholar\Theses\ThesisSearch;

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
        CURLOPT_TIMEOUT => 60, // saving a record now also embeds it
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

// how a record appears in a results page: 'missing', 'words' (listed, no label) or 'meaning' (listed as "Similar topic")
// $linkStart is the start of the record's title link, e.g. 'href="thesis.php?id=12"'
function howListed(array $response, string $linkStart): string {
    $position = strpos($response['body'], $linkStart);
    if ($position === false) {
        return 'missing';
    }
    // the label is inside the same result card as the title link, so look up to the end of that card
    $cardEnd = strpos($response['body'], '</article>', $position);
    if ($cardEnd === false) {
        $cardEnd = strlen($response['body']);
    }
    $nearby = substr($response['body'], $position, $cardEnd - $position);
    if (strpos($nearby, 'data-match="meaning"') !== false) {
        return 'meaning';
    }
    return 'words';
}

// when the record was last indexed (null = not included in the search index)
function indexedAt(PDO $pdo, int $thesisId): ?string {
    $statement = $pdo->prepare('SELECT search_indexed_at FROM theses WHERE id = :id');
    $statement->execute(['id' => $thesisId]);
    $value = $statement->fetchColumn();
    if ($value === false || $value === null) {
        return null;
    }
    return (string) $value;
}

$baseUrl = 'http://scholar.localhost';
if (isset($argv[1])) {
    $baseUrl = $argv[1];
}
$baseUrl = rtrim($baseUrl, '/') . '/';
$pdo = connectDatabase();

// STEP 1 happens before anything is created: without the services there is nothing to test
$searchIndex = SearchIndex::create($pdo);
if ($searchIndex === null) {
    fwrite(STDERR, "Semantic search is switched off (SEARCH_SEMANTIC_ENABLED=false); nothing to test.\n");
    exit(2);
}
try {
    $searchIndex->status();
} catch (SearchUnavailable $exception) {
    fwrite(STDERR, "Ollama or Qdrant is not answering. Start both, run 'bin/console.php reindex-search', then run this test again.\n");
    exit(2);
}

$suffix = bin2hex(random_bytes(6));
$username = 'verify_semantic_' . $suffix;
$password = bin2hex(random_bytes(20)); // never print or commit the temporary password
$marker = 'zqsem' . $suffix; // an unusual word only this record contains
$title = 'Sample Study: Smart Beehive Temperature Logger ' . $marker;
// shares no word with the record, but means nearly the same thing (checked: about 0.59 similarity with bge-m3)
$meaningQuery = 'insect farm climate sensors';
$thesisId = 0;
$client = newClient();
$failed = false;
try {
    echo "PREPARATION: Create this script's temporary account.\n";
    putenv('SCHOLAR_ADMIN_PASSWORD=' . $password);
    check(runConsole(['provision-admin', $username]) === 0, 'CLI provisions temporary RDPO administrator');
    putenv('SCHOLAR_ADMIN_PASSWORD');
    check(submitLogin($client, $baseUrl, $username, $password)['status'] === 303, 'RDPO administrator logs in');

    showStep('Create the made-up record through the New record form.');
    $form = requestPage($client, $baseUrl, 'admin_thesis_form.php');
    $response = requestPage($client, $baseUrl, 'admin_thesis_form.php', [
        'csrf_token' => tokenFrom($form),
        'title' => $title,
        'author_last_name' => ['Verifier'], 'author_given_names' => ['Sample'], 'author_suffix' => [''],
        'publication_year' => '2025', 'program' => 'BS Verification Studies', 'campus' => 'WCC QC',
        'keywords' => 'apiculture, honeybee colony, IoT',
        'abstract' => 'A made-up study of a device that records temperature and humidity inside wooden hives so that beekeepers can protect their honeybee colonies during hot months.',
    ]);
    check($response['status'] === 303 && preg_match('/Location: admin_thesis\.php\?id=(\d+)/', $response['headers'], $matches) === 1, 'Record created');
    $thesisId = (int) $matches[1];
    check(indexedAt($pdo, $thesisId) !== null, 'Saving put it into the search index');
    check(strpos(requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $thesisId)['body'], 'Included (updated') !== false, 'The record page says it is included in search by meaning');

    showStep('Search for it on the RDPO list.');
    $adminLink = 'href="admin_thesis.php?id=' . $thesisId . '"';
    $byMeaning = requestPage($client, $baseUrl, 'admin.php?' . http_build_query(['q' => $meaningQuery]));
    check(howListed($byMeaning, $adminLink) === 'meaning', 'A search sharing no words finds it, labelled "Similar topic"');
    check(strpos($byMeaning['body'], '<option value="" selected>Best match</option>') !== false, 'Typed words are ordered by Best match');
    $byWord = requestPage($client, $baseUrl, 'admin.php?' . http_build_query(['q' => 'beehive ' . $marker]));
    check(howListed($byWord, $adminLink) === 'words', 'A search with words from its title finds it as a word match');
    $byTitleOrder = requestPage($client, $baseUrl, 'admin.php?' . http_build_query(['q' => $meaningQuery, 'sort' => 'title']));
    check(howListed($byTitleOrder, $adminLink) === 'meaning' && strpos($byTitleOrder['body'], '<option value="title" selected>') !== false, 'Another order (Title A-Z) keeps the same matches');
    check(howListed(requestPage($client, $baseUrl, 'admin.php?' . http_build_query(['q' => 'cooking recipes'])), $adminLink) === 'missing', 'An unrelated search does not list it');

    showStep('Search for it on the public page while unpublished, then published.');
    $public = newClient();
    $publicLink = 'href="thesis.php?id=' . $thesisId . '"';
    check(howListed(requestPage($public, $baseUrl, 'index.php?' . http_build_query(['q' => $meaningQuery])), $publicLink) === 'missing', 'Unpublished: the public search does not find it');
    $recordPage = requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $thesisId);
    requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $thesisId, ['csrf_token' => tokenFrom($recordPage), 'action' => 'publish']);
    check(howListed(requestPage($public, $baseUrl, 'index.php?' . http_build_query(['q' => $meaningQuery])), $publicLink) === 'meaning', 'Published: the public search finds it by meaning');
    check(howListed(requestPage($public, $baseUrl, 'index.php?' . http_build_query(['q' => $meaningQuery, 'campus' => 'WCC Antipolo'])), $publicLink) === 'missing', 'A campus filter that does not fit leaves it out');

    showStep('Archive it.');
    $recordPage = requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $thesisId);
    requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $thesisId, ['csrf_token' => tokenFrom($recordPage), 'action' => 'archive']);
    check(howListed(requestPage($public, $baseUrl, 'index.php?' . http_build_query(['q' => $meaningQuery])), $publicLink) === 'missing', 'Archived: the public search does not find it');
    check(howListed(requestPage($client, $baseUrl, 'admin.php?' . http_build_query(['q' => $meaningQuery])), $adminLink) === 'missing', 'Archived: RDPO\'s default list does not find it');
    check(howListed(requestPage($client, $baseUrl, 'admin.php?' . http_build_query(['q' => $meaningQuery, 'status' => 'archived'])), $adminLink) === 'meaning', 'RDPO\'s "Archived" filter finds it by meaning');

    showStep('Make Ollama unreachable for this script, then bring it back.');
    // the terminal side of the code reads $_SERVER before .env, so this address only affects this script
    $_SERVER['SEARCH_OLLAMA_URL'] = 'http://127.0.0.1:9';
    check(SearchIndex::refreshQuietly($pdo, $thesisId) === false && indexedAt($pdo, $thesisId) === null, 'Indexing fails quietly and marks the record "not included yet"');
    $settings = loadConfiguration()['search'];
    $deadIndex = new SearchIndex($pdo, new EmbeddingClient($settings['ollama_url'], $settings['model']),
        new QdrantClient($settings['qdrant_url'], $settings['qdrant_api_key'], $settings['collection']), $settings['min_score']);
    $fallbackFilters = ThesisSearch::filtersFromQuery(['q' => $marker, 'status' => 'all'], true);
    $fallback = (new ThesisSearch($pdo, $deadIndex))->search($fallbackFilters, true);
    check($fallback['mode'] === 'words_fallback' && $fallback['total'] === 1 && (int) $fallback['rows'][0]['id'] === $thesisId, 'Searching falls back to words only and still finds it');
    unset($_SERVER['SEARCH_OLLAMA_URL']);
    check(SearchIndex::refreshQuietly($pdo, $thesisId) === true && indexedAt($pdo, $thesisId) !== null, 'With Ollama back, the record is indexed again');
} catch (Throwable $exception) {
    $failed = true;
    echo 'FAIL: ' . $exception->getMessage() . "\n";
} finally {
    echo "\nCLEANUP: Remove this script's record, its search entry, account, history and failed-login counters.\n";
    unset($_SERVER['SEARCH_OLLAMA_URL']);
    putenv('SCHOLAR_ADMIN_PASSWORD');
    unset($client);
    $thesisIds = $pdo->prepare('SELECT id FROM theses WHERE title = :title');
    $thesisIds->execute(['title' => $title]);
    foreach ($thesisIds->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo->prepare("DELETE FROM audit_logs WHERE entity_type = 'thesis' AND entity_id = :id")->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM thesis_authors WHERE thesis_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM theses WHERE id = :id')->execute(['id' => $id]);
        try {
            $searchIndex->removeThesis((int) $id);
        } catch (Throwable $exception) {
            echo "NOTE: the search entry could not be removed; 'reindex-search --missing' removes it later.\n";
        }
    }
    $pdo->prepare('DELETE FROM failed_logins WHERE account_key = :key')->execute(['key' => LoginThrottle::accountKey($username)]);
    $userId = $pdo->prepare('SELECT id FROM users WHERE username = :username');
    $userId->execute(['username' => $username]);
    $id = $userId->fetchColumn();
    if ($id) {
        $pdo->prepare("DELETE FROM audit_logs WHERE actor_user_id = :actor OR (entity_type = 'user' AND entity_id = :entity)")
            ->execute(['actor' => $id, 'entity' => $id]);
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
    }
    echo "PASS: cleanup finished\n";
}

if ($failed) {
    echo "RESULT: semantic search checks failed.\n";
    exit(1);
}
echo "RESULT: semantic search checks passed.\n";
exit(0);
