<?php
// WHAT THIS FILE DOES: checks session time rules without opening a browser, session, or database.
// STEP 1: Load the real policy class and use a fixed current time.
// STEP 2: Check active sessions, the exact expiry limits, and the renewal limit separately.
// STEP 3: Check missing, invalid, future, and inconsistent timestamps.
// STEP 4: Check invalid timer settings and print the total result.
// Fixed times mean no waiting, sleeps, or edits to the application's real session settings.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';
use Scholar\Auth\SessionPolicy;

$policy = new SessionPolicy(1800, 28800, 900);
$now = 100000;
$active = ['login_at' => 90000, 'last_activity_at' => 99990, 'renewed_at' => 99900];
// each case changes just the input needed to exercise one rule
$cases = [
    ['Active session', $active, 'active'],
    ['Just before inactivity limit', array_replace($active, ['last_activity_at' => 98201]), 'active'],
    ['Exactly at inactivity limit', array_replace($active, ['last_activity_at' => 98200]), 'expired'],
    ['Past inactivity limit', array_replace($active, ['last_activity_at' => 98199]), 'expired'],
    ['Just before maximum lifetime', array_replace($active, ['login_at' => 71201]), 'active'],
    ['Exactly at maximum lifetime despite recent activity', array_replace($active, ['login_at' => 71200]), 'expired'],
    ['Past maximum lifetime', array_replace($active, ['login_at' => 71199]), 'expired'],
    ['Just before renewal limit', array_replace($active, ['renewed_at' => 99101]), 'active'],
    ['Exactly at renewal limit', array_replace($active, ['renewed_at' => 99100]), 'renew'],
    ['Expiry takes priority over renewal', array_replace($active, ['last_activity_at' => 98200, 'renewed_at' => 99100]), 'expired'],
    ['Missing timestamps', [], 'expired'],
    ['String timestamp rejected', array_replace($active, ['login_at' => '90000']), 'expired'],
    ['Future timestamp rejected', array_replace($active, ['last_activity_at' => 100001]), 'expired'],
    ['Activity before login rejected', array_replace($active, ['last_activity_at' => 89999]), 'expired'],
    ['Renewal before login rejected', array_replace($active, ['renewed_at' => 89999]), 'expired'],
];
$passed = 0;
$failed = 0;
foreach ($cases as $case) {
    echo 'STEPS ' . ($passed + $failed + 1) . ': ' . $case[0] . ".\n";
    $actual = $policy->evaluate($case[1], $now); // Act: evaluate the real policy once
    if ($actual === $case[2]) {
        echo 'PASS: ' . $case[0] . "\n";
        $passed++;
    } else {
        echo 'FAIL: ' . $case[0] . ' expected ' . $case[2] . ', got ' . $actual . "\n";
        $failed++;
    }
}
foreach ([[0, 28800, 900], [1800, 1000, 900], [1800, 28800, 28800], [1800, 604801, 900]] as $settings) {
    echo 'STEPS ' . ($passed + $failed + 1) . ": Reject invalid timer settings.\n";
    try {
        new SessionPolicy($settings[0], $settings[1], $settings[2]);
        echo "FAIL: Invalid timer settings accepted.\n";
        $failed++;
    } catch (InvalidArgumentException $exception) {
        echo "PASS: Invalid timer settings rejected.\n";
        $passed++;
    }
}
echo "RESULT: $passed passed; $failed failed.\n";
if ($failed > 0) {
    exit(1);
}
exit(0);
