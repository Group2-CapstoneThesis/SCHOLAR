<?php
// WHAT THIS FILE DOES: checks escapeHtml() by itself using five fixed examples.
// These are unit tests.
// Loading bootstrap makes the real application function available; the function is not copied here.

// STEP 1: Allow terminal use only and load the application's bootstrap file.
// STEP 2: Prepare script text, call escapeHtml(), and compare the exact returned text (UNIT-01).
// STEP 3: Repeat with single and double quotation marks (UNIT-02).
// STEP 4: Repeat with ordinary text that should remain unchanged (UNIT-03).
// STEP 5: Repeat with an empty string (UNIT-04).
// STEP 6: Repeat with valid Unicode text, including the letter ñ (UNIT-05).
// STEP 7: Print the number of passed/failed cases and return an exit code.

// Every case calls the real function once and checks one exact expected result.
// A failed case does not prevent the other cases from running!
// Exit code 0 means all five cases passed; exit code 1 means at least one case failed.
// To use this testing script, run it in CLI with PHP 8.5+
declare(strict_types=1);

// prevent running this terminal-only test as a web page
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

// return true for a passing case, or false for a failure; print useful comparison details
function checkEscapedText(string $caseId, string $description, string $input, string $expected): bool {
    echo "\n$caseId: $description\n";
    echo "STEPS 1: Prepare the fixed input and expected result.\n";
    echo "STEPS 2: Call the application's escapeHtml() function once.\n";
    try {
        $actual = escapeHtml($input); // Act: use the real application function
    } catch (Throwable $exception) {
        // only report the error type; do not print potentially private exception messages
        echo 'FAIL: The function threw ' . get_class($exception) . ".\n";
        return false;
    }
    echo "STEPS 3: Compare the actual result with the exact expected result.\n";
    // JSON shows quotation marks and empty strings clearly in terminal output
    echo 'Expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n";
    echo 'Actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n";
    if ($actual !== $expected) { // Assert: both the value and its type must match
        echo "FAIL: $caseId - $description\n";
        return false;
    }
    echo "PASS: $caseId - $description\n";
    return true;
}

// Arrange: fixed test values keep the same cases repeatable on every run
$cases = [
    ['id' => 'UNIT-01', 'description' => 'Escape script text safely',
        'input' => '<script>alert(1)</script>', 'expected' => '&lt;script&gt;alert(1)&lt;/script&gt;'],
    ['id' => 'UNIT-02', 'description' => 'Escape quotation marks',
        'input' => "\"Hello\" 'World'", 'expected' => '&quot;Hello&quot; &#039;World&#039;'],
    ['id' => 'UNIT-03', 'description' => 'Keep ordinary text unchanged',
        'input' => 'Synthetic Author Alpha', 'expected' => 'Synthetic Author Alpha'],
    ['id' => 'UNIT-04', 'description' => 'Handle empty text',
        'input' => '', 'expected' => ''],
    ['id' => 'UNIT-05', 'description' => 'Keep valid Unicode text readable',
        'input' => 'Peña', 'expected' => 'Peña'],
];

$passed = 0;
$failed = 0;
echo "UNIT TESTING: Application HTML escaping helper\n";
echo 'Executed: ' . date('Y-m-d H:i:s T') . "\n";
echo 'PHP version: ' . PHP_VERSION . "\n";
foreach ($cases as $case) {
    $success = checkEscapedText($case['id'], $case['description'], $case['input'], $case['expected']);
    if ($success) {
        $passed++;
    } else {
        $failed++;
    }
}
echo "\nRESULT: $passed passed; $failed failed.\n";
if ($failed > 0) {
    exit(1);
}
exit(0);
