<?php
declare(strict_types=1); // keep typed values strict

use Scholar\Search\RelevanceExplainer;
use Scholar\Search\RelevanceUnavailable;
use Scholar\Search\SearchIndex;
use Scholar\Theses\PublicCatalog;
use Scholar\Theses\ThesisSearch;

// the AI relevance explanation for ONE public search result, as JSON (asked for by public/assets/search.js)
// like index.php there is no login and no session; it can only ever read a PUBLISHED, non-archived thesis
// (PublicCatalog::findPublicThesis), and only its abstract goes to OpenRouter together with the typed query
// answers: {"status":"ok","explanation":...,"matched_concepts":[...],"sufficient":true|false}
//          {"status":"no_abstract"}  {"status":"unavailable"}  {"status":"not_found"}  {"status":"bad_request"}
// it never returns a file, a file name or a file location
require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

// sends one JSON answer and stops
function answer(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    answer(405, ['status' => 'bad_request']);
}

// the same query rules as the search page (trimmed, at most 200 characters, valid UTF-8)
$query = ThesisSearch::filtersFromQuery($_GET, false)['q'];

// ---- the AI overview shown above the results: ?mode=overview&q=...&ids=4,9,2 (the top results, best first) ----
// answers {"status":"ok","overview":...,"most_relevant":[thesis ids],"sufficient":bool} or the same error statuses
if (isset($_GET['mode']) && $_GET['mode'] === 'overview') {
    $overviewIds = [];
    if (isset($_GET['ids']) && is_string($_GET['ids']) && preg_match('/\A[1-9][0-9]{0,18}(,[1-9][0-9]{0,18}){0,9}\z/', $_GET['ids'])) {
        $overviewIds = array_values(array_unique(array_map('intval', explode(',', $_GET['ids']))));
    }
    if ($query === '' || count($overviewIds) === 0) {
        answer(400, ['status' => 'bad_request']);
    }
    $pdo = connectDatabase();
    $catalog = new PublicCatalog($pdo);
    // only published, non-archived records with an abstract; hidden or missing ids are silently skipped
    $usedIds = [];
    $abstracts = [];
    foreach ($overviewIds as $overviewId) {
        $thesis = $catalog->findPublicThesis($overviewId);
        if ($thesis !== null && $thesis['abstract'] !== null && trim($thesis['abstract']) !== '') {
            $usedIds[] = $overviewId;
            $abstracts[] = $thesis['abstract'];
        }
        if (count($abstracts) >= RelevanceExplainer::OVERVIEW_MAX_ABSTRACTS) {
            break;
        }
    }
    if (count($abstracts) === 0) {
        answer(200, ['status' => 'no_abstract']);
    }
    $explainer = RelevanceExplainer::create();
    if ($explainer === null) {
        answer(200, ['status' => 'unavailable']);
    }
    $clientAddress = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    try {
        // ONLY the query and the abstracts (numbered 1..n) are passed on
        $result = $explainer->overview($query, $abstracts, $clientAddress);
    } catch (Throwable $exception) {
        SearchIndex::logProblem('writing the AI overview', $exception);
        answer(200, ['status' => 'unavailable']);
    }
    // the numbers [1], [2] ... become thesis ids again here, so the page can show the matching titles
    $mostRelevant = [];
    foreach ($result['most_relevant'] as $number) {
        $mostRelevant[] = $usedIds[$number - 1];
    }
    answer(200, [
        'status' => 'ok',
        'overview' => $result['overview'],
        'numbered_ids' => $usedIds,
        'most_relevant' => $mostRelevant,
        'sufficient' => $result['sufficient'],
    ]);
}
$thesisId = 0;
if (isset($_GET['id']) && is_string($_GET['id']) && preg_match('/\A[1-9][0-9]{0,18}\z/', $_GET['id'])) {
    $thesisId = (int) $_GET['id'];
}
if ($query === '' || $thesisId === 0) {
    answer(400, ['status' => 'bad_request']);
}

$pdo = connectDatabase();
$thesis = (new PublicCatalog($pdo))->findPublicThesis($thesisId);
if ($thesis === null) {
    // unpublished, archived and missing records answer the same way, like thesis.php
    answer(404, ['status' => 'not_found']);
}
if ($thesis['abstract'] === null || trim($thesis['abstract']) === '') {
    answer(200, ['status' => 'no_abstract']);
}

$explainer = RelevanceExplainer::create();
if ($explainer === null) {
    answer(200, ['status' => 'unavailable']); // OPENROUTER_API_KEY or OPENROUTER_MODEL is not set
}

// the visitor's address is only used (hashed) to rate-limit uncached explanations; it is never sent to OpenRouter
$clientAddress = '';
if (isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
    $clientAddress = $_SERVER['REMOTE_ADDR'];
}
try {
    // ONLY the query and the abstract are passed on; the title, authors and every other field stay here
    $result = $explainer->explain($query, $thesis['abstract'], $clientAddress);
} catch (RelevanceUnavailable | Throwable $exception) {
    // OpenRouter down, slow, refused the key, odd answer, the rate limit, or a cache-folder problem:
    // the page keeps the result and its score, just without the explanation
    SearchIndex::logProblem('explaining relevance for record ' . $thesisId, $exception);
    answer(200, ['status' => 'unavailable']);
}
answer(200, [
    'status' => 'ok',
    'explanation' => $result['explanation'],
    'matched_concepts' => $result['matched_concepts'],
    'sufficient' => $result['sufficient'],
]);
