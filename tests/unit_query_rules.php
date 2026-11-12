<?php
// WHAT THIS FILE DOES: checks how a search typed as a sentence is understood (Scholar\Search\QueryInterpreter).
// These are unit tests; no database or web server is needed.

// STEP 1: Allow terminal use only and load the application.
// STEP 2: Interpret fixed example sentences and compare the topic, program, year, campus and order with the expected ones.
// STEP 3: Print the number of passed/failed cases and return an exit code (0 = all passed).

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

use Scholar\Search\QueryInterpreter;

$programs = ['BS Business Administration', 'BS Computer Science', 'BS Information Technology', 'BS Nursing', 'BS Psychology'];

// [sentence, topic, program, year, campus, sort]
$examples = [
    ['research conducted by IT', '', 'BS Information Technology', null, null, null],
    ['research about flood monitoring', 'flood monitoring', null, null, null, null],
    ['find me a research related to library inventory', 'library inventory', null, null, null, null],
    ['Can you find me studies about stress of BS Psychology students in 2024?', 'stress', 'BS Psychology', 2024, null, null],
    ['latest research about inventory in Antipolo', 'inventory', null, null, 'WCC Antipolo', 'newest'],
    ['thesis made by BSCS students about rice disease', 'rice disease', 'BS Computer Science', null, null, null],
    ['research from the Computer Science department', '', 'BS Computer Science', null, null, null],
    ['show me research done in WCC QC', '', null, null, 'WCC QC', null],
    ['oldest theses by business students', '', 'BS Business Administration', null, null, 'oldest'],
    // topics that only look like programs or campuses must stay topics
    ['IT security research', 'IT security', null, null, null, null],
    ['research about it', 'it', null, null, null, null],
    ['research about nurse shift scheduling', 'nurse shift scheduling', null, null, null, null],
    ['impact of business on students', 'impact business students', null, null, null, null],
    ['aqcuisition of tools', 'aqcuisition tools', null, null, null, null],
    // plain words, names and quoted words
    ['flood monitoring', 'flood monitoring', null, null, null, null],
    ['research by Angelica Dela Cruz', 'Angelica Dela Cruz', null, null, null, null],
    ['"find me"', 'find me', null, null, null, null],
    ['research from 2023 and 2024 about sensors', '2023 2024 sensors', null, null, null, null], // two years: no year filter
];

$passed = 0;
$failed = 0;
foreach ($examples as $number => [$sentence, $topic, $program, $year, $campus, $sort]) {
    $result = QueryInterpreter::interpret($sentence, $programs);
    $actual = [$result['topic'], $result['program'], $result['year'], $result['campus'], $result['sort']];
    $expected = [$topic, $program, $year, $campus, $sort];
    echo 'STEPS ' . ($number + 1) . ": \"$sentence\"\n";
    if ($actual === $expected) {
        echo "PASS: understood as " . json_encode($actual) . "\n";
        $passed++;
    } else {
        echo 'FAIL: expected ' . json_encode($expected) . ' but got ' . json_encode($actual) . "\n";
        $failed++;
    }
}
$unchanged = QueryInterpreter::interpret('flood monitoring', $programs);
echo "STEPS " . (count($examples) + 1) . ": plain words are not marked as interpreted\n";
if ($unchanged['changed'] === false) {
    echo "PASS: plain words are left as they are\n";
    $passed++;
} else {
    echo "FAIL: plain words were marked as interpreted\n";
    $failed++;
}
echo "RESULT: $passed passed; $failed failed.\n";
exit($failed === 0 ? 0 : 1);
