<?php
// WHAT THIS FILE DOES: checks the hybrid search rules (M14) without a browser, a database, Ollama or Qdrant.
// STEP 1: Load the real HybridRanking class (what is embedded, which filters go with it, how results are combined).
// STEP 2: Check the embedded text: only title, keywords and abstract, never private fields such as the adviser.
// STEP 3: Check the filters stored with each record and the Qdrant filter built from the search page's filters.
// STEP 4: Check how word matches and meaning matches are combined, ranked and labelled.
// STEP 5: Check the search settings read from .env, including refused values.
// STEP 6: Print the total result.
// All records here are made up.
declare(strict_types=1);

use Scholar\Configuration;
use Scholar\Search\HybridRanking;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

// a made-up database row as SearchIndex reads it, with private fields that must never be embedded
$row = [
    'id' => 7, 'title' => ' Sample Study: Smart Beehive Logger ', 'keywords' => 'apiculture, IoT',
    'abstract' => "A made-up abstract.\nSecond line.", 'adviser' => 'Private Adviser Name', 'department' => 'Private Department',
    'is_public' => 1, 'archived_at' => null, 'publication_year' => '2025', 'program' => 'BS Sample Studies', 'campus' => 'WCC Guimba',
];

// the filters the search page builds, changed per case with array_merge()
$noFilters = ['q' => 'x', 'year' => null, 'program' => '', 'campus' => '', 'sort' => 'relevance', 'status' => 'active', 'page' => 1];

// the setting check with one .env value replaced; returns '' when it is accepted, otherwise 'refused'
function settingResult(string $key, string $value): string {
    $previous = null;
    if (isset($_SERVER[$key])) {
        $previous = $_SERVER[$key];
    }
    $_SERVER[$key] = $value; // Configuration reads $_SERVER before .env, like a real environment variable
    $result = '';
    try {
        Configuration::load();
    } catch (RuntimeException $exception) {
        $result = 'refused';
    }
    if ($previous === null) {
        unset($_SERVER[$key]);
    } else {
        $_SERVER[$key] = $previous;
    }
    return $result;
}

// combine() with made-up scores: 1 and 2 contain the words; 3, 4 and 5 were found by meaning
$combined = HybridRanking::combine([1, 2], [1 => 0.62, 2 => 0.20], [1 => 0.62, 3 => 0.70, 4 => 0.55, 5 => 0.49], 0.5);
$order = array_column($combined, 'id');
$matches = array_column($combined, 'match', 'id');
$notIndexed = HybridRanking::combine([9], [], [], 0.5); // a word match that is not in the index yet
$tie = HybridRanking::combine([], [], [10 => 0.6, 11 => 0.6], 0.5);

$cases = [
    // ---- what is embedded ----
    ['Embedded text is title, keywords and abstract, trimmed', HybridRanking::documentText($row) === "Sample Study: Smart Beehive Logger\napiculture, IoT\nA made-up abstract.\nSecond line."],
    ['Private fields are never embedded', strpos(HybridRanking::documentText($row), 'Private') === false],
    ['Missing keywords and abstract are skipped', HybridRanking::documentText(['title' => 'Only a title', 'keywords' => null, 'abstract' => '']) === 'Only a title'],

    // ---- filters stored with each record ----
    ['Stored filters', HybridRanking::payload($row) === ['is_public' => true, 'is_archived' => false, 'publication_year' => 2025, 'program' => 'BS Sample Studies', 'campus' => 'WCC Guimba']],
    ['Archived and unpublished are stored as such', HybridRanking::payload(array_merge($row, ['is_public' => 0, 'archived_at' => '2026-01-01 00:00:00']))['is_archived'] === true
        && HybridRanking::payload(array_merge($row, ['is_public' => 0]))['is_public'] === false],
    ['A record without a year stores null', HybridRanking::payload(array_merge($row, ['publication_year' => null]))['publication_year'] === null],

    // ---- the Qdrant filter ----
    ['Public search: published and not archived only, whatever the status filter says',
        HybridRanking::qdrantFilter(array_merge($noFilters, ['status' => 'all']), false) === ['must' => [
            ['key' => 'is_public', 'match' => ['value' => true]], ['key' => 'is_archived', 'match' => ['value' => false]]]]],
    ['RDPO "active": not archived', HybridRanking::qdrantFilter($noFilters, true) === ['must' => [['key' => 'is_archived', 'match' => ['value' => false]]]]],
    ['RDPO "archived": archived only', HybridRanking::qdrantFilter(array_merge($noFilters, ['status' => 'archived']), true) === ['must' => [['key' => 'is_archived', 'match' => ['value' => true]]]]],
    ['RDPO "unpublished": not public and not archived', count(HybridRanking::qdrantFilter(array_merge($noFilters, ['status' => 'unpublished']), true)['must']) === 2],
    ['RDPO "all": no filter at all', HybridRanking::qdrantFilter(array_merge($noFilters, ['status' => 'all']), true) === []],
    ['Year, program and campus become conditions', HybridRanking::qdrantFilter(array_merge($noFilters, ['status' => 'all', 'year' => 2024, 'program' => 'BS IT', 'campus' => 'WCC QC']), true) === ['must' => [
        ['key' => 'publication_year', 'match' => ['value' => 2024]], ['key' => 'program', 'match' => ['value' => 'BS IT']], ['key' => 'campus', 'match' => ['value' => 'WCC QC']]]]],

    // ---- combining the matches ----
    ['Word matches get the bonus and rank above similar meaning-only matches', $order === [1, 3, 4, 2]],
    ['A record with the words AND close meaning is labelled "both"', $matches[1] === 'both'],
    ['A word match with low similarity is still listed, as "words"', $matches[2] === 'words'],
    ['Meaning-only matches are labelled "meaning"', $matches[3] === 'meaning' && $matches[4] === 'meaning'],
    ['A meaning match below the minimum score is left out', !isset($matches[5])],
    ['The score is similarity plus the word bonus', abs($combined[0]['score'] - (0.62 + HybridRanking::WORD_MATCH_BONUS)) < 0.0001],
    ['A word match that is not indexed yet is still listed', $notIndexed === [['id' => 9, 'score' => HybridRanking::WORD_MATCH_BONUS, 'match' => 'words', 'similarity' => null]]],
    ['Meaning matches carry their Qdrant similarity unchanged (no word bonus)', $combined[1]['similarity'] === 0.70],
    ['Relevance levels: 0.65 High, 0.64 and 0.55 Moderate, 0.54 Low, not indexed none',
        HybridRanking::relevanceLevel(0.65) === 'High' && HybridRanking::relevanceLevel(0.64) === 'Moderate' && HybridRanking::relevanceLevel(0.55) === 'Moderate'
        && HybridRanking::relevanceLevel(0.54) === 'Low' && HybridRanking::relevanceLevel(null) === null],
    ['Equal scores: the newer record (higher id) first', array_column($tie, 'id') === [11, 10]],
    ['No matches at all: an empty list', HybridRanking::combine([], [], [], 0.5) === []],

    // ---- settings ----
    ['Default settings: on, bge-m3, local addresses', Configuration::load()['search']['model'] !== '' && is_bool(Configuration::load()['search']['enabled'])],
    ['A minimum score of 0.45 is accepted', settingResult('SEARCH_MIN_SCORE', '0.45') === ''],
    ['A minimum score above 1 is refused', settingResult('SEARCH_MIN_SCORE', '1.5') === 'refused'],
    ['A minimum score that is not a number is refused', settingResult('SEARCH_MIN_SCORE', 'high') === 'refused'],
    ['An address with a path or other parts is refused', settingResult('SEARCH_QDRANT_URL', 'http://example.invalid/x?y=1') === 'refused'],
    ['A non-http address is refused', settingResult('SEARCH_OLLAMA_URL', 'file:///etc/passwd') === 'refused'],
    ['"maybe" is not a valid on/off value', settingResult('SEARCH_SEMANTIC_ENABLED', 'maybe') === 'refused'],
    ['A collection name with odd characters is refused', settingResult('SEARCH_QDRANT_COLLECTION', '../theses') === 'refused'],
];

$passed = 0;
$failed = 0;
foreach ($cases as $case) {
    echo 'STEPS ' . ($passed + $failed + 1) . ': ' . $case[0] . ".\n";
    if ($case[1]) {
        echo 'PASS: ' . $case[0] . "\n";
        $passed++;
    } else {
        echo 'FAIL: ' . $case[0] . "\n";
        $failed++;
    }
}
echo "RESULT: $passed passed; $failed failed.\n";
if ($failed > 0) {
    exit(1);
}
exit(0);
