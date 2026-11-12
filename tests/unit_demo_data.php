<?php
// WHAT THIS FILE DOES: checks the made-up demo theses (Scholar\DemoTheses) without a database.
// STEP 1: Load the real DemoTheses class and its list of records.
// STEP 2: Check every record is complete: a real title without "Sample", a year, a valid campus and department,
//         a defense date in its year, keywords, authors with both name parts, and an abstract long enough to search.
// STEP 3: Check the set as a whole: unique titles, every campus and department used, several years.
// STEP 4: Print the total result.
declare(strict_types=1);

use Scholar\DemoTheses;
use Scholar\Theses\Campuses;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

$departments = ['College of Computer Studies', 'College of Arts and Sciences', 'College of Business Administration', 'College of Nursing'];
$records = DemoTheses::records();

// one problem list per rule, filled while going through every record once
$problems = ['title' => [], 'year' => [], 'campus' => [], 'department' => [], 'defense' => [], 'keywords' => [], 'authors' => [], 'abstract' => [], 'key' => []];
foreach ($records as $key => $record) {
    if (!preg_match('/\A[a-z_]{3,40}\z/', $key)) {
        $problems['key'][] = $key;
    }
    if (stripos($record['title'], 'sample') !== false || strlen($record['title']) < 30) {
        $problems['title'][] = $key;
    }
    if ($record['year'] < 2019 || $record['year'] > 2026) {
        $problems['year'][] = $key;
    }
    if (!Campuses::isCampus($record['campus'])) {
        $problems['campus'][] = $key;
    }
    if (!in_array($record['department'], $departments, true)) {
        $problems['department'][] = $key;
    }
    if (substr($record['defense'], 0, 4) !== (string) $record['year'] || !strtotime($record['defense'])) {
        $problems['defense'][] = $key;
    }
    if (count(explode(',', $record['keywords'])) < 3) {
        $problems['keywords'][] = $key;
    }
    foreach ($record['authors'] as $author) {
        if (count($author) !== 2 || trim($author[0]) === '' || trim($author[1]) === '') {
            $problems['authors'][] = $key;
        }
    }
    // a normal abstract length, so semantic search has enough text to work with (roughly 70 words or more)
    if (str_word_count($record['abstract']) < 70 || stripos($record['abstract'], 'sample') !== false) {
        $problems['abstract'][] = $key;
    }
}
$titles = array_column($records, 'title');

$cases = [
    ['There are at least 20 demo records', count($records) >= 20 && DemoTheses::count() === count($records)],
    ['Keys are short lowercase names', $problems['key'] === []],
    ['Titles look real: no "Sample", not too short', $problems['title'] === []],
    ['Years are between 2019 and 2026', $problems['year'] === []],
    ['Every campus is one of the five WCC campuses', $problems['campus'] === []],
    ['Every department is one of the four used by the sample data', $problems['department'] === []],
    ['Every defense date is a real date in the thesis year', $problems['defense'] === []],
    ['Every record has at least three keywords', $problems['keywords'] === []],
    ['Every author has given names and a last name', $problems['authors'] === []],
    ['Every abstract has about 70 words or more and no "sample"', $problems['abstract'] === []],
    ['Titles are unique', count(array_unique($titles)) === count($titles)],
    ['All five campuses are used', count(array_unique(array_column($records, 'campus'))) === 5],
    ['All four departments are used', count(array_unique(array_column($records, 'department'))) === 4],
    ['Several thesis years are used', count(array_unique(array_column($records, 'year'))) >= 4],
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
