<?php
// WHAT THIS FILE DOES: checks the Activity log rules (M12, FB-07) without a browser, session, or database.
// STEP 1: Load the real classes: Access (who may do what), AuditLog (the action codes) and ActivityLog (the filters).
// STEP 2: Check that only the head administrator may read the log and delete old entries, and that neither can be switched.
// STEP 3: Check that every action the application records has a readable name on the Activity log page.
// STEP 4: Check the filters read from the address, including made-up actions, impossible dates and odd page numbers.
// STEP 5: Print the total result.
// Each case is worked out first and printed afterwards, so every case runs even when an earlier one fails.
declare(strict_types=1);

use Scholar\ActivityLog;
use Scholar\AuditLog;
use Scholar\Auth\Access;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

// shorthand: the filters read from one made-up address query
function filtersFor(array $query): array {
    return ActivityLog::filtersFromQuery($query);
}

// the codes AuditLog accepts but the Activity log has no label for (should be none), and the reverse
$unlabelled = array_diff(AuditLog::actionCodes(), array_keys(ActivityLog::ACTION_LABELS));
$labelsWithoutCode = array_diff(array_keys(ActivityLog::ACTION_LABELS), AuditLog::actionCodes());

$cases = [
    // ---- who may use the log ----
    ['Head administrator may read the log', Access::roleAllows('rdpo_head', 'audit.view')],
    ['Head administrator may delete old entries', Access::roleAllows('rdpo_head', 'audit.delete_old')],
    ['RDPO administrator may not read the log', !Access::roleAllows('rdpo_admin', 'audit.view')],
    ['RDPO administrator may not delete old entries', !Access::roleAllows('rdpo_admin', 'audit.delete_old')],
    ['Log permissions are not per-account switches', !isset(Access::SWITCHABLE_PERMISSIONS['audit.view']) && !isset(Access::SWITCHABLE_PERMISSIONS['audit.delete_old'])],

    // ---- labels ----
    ['Every recorded action has a readable name', count($unlabelled) === 0],
    ['Every readable name belongs to a real action', count($labelsWithoutCode) === 0],

    // ---- filters ----
    ['No filters means everything, page 1', filtersFor([]) === ['action' => '', 'account' => '', 'from' => '', 'to' => '', 'page' => 1]],
    ['Known action accepted', filtersFor(['action' => 'auth.login'])['action'] === 'auth.login'],
    ['Made-up action ignored', filtersFor(['action' => 'thesis.deleted'])['action'] === ''],
    ['Account id accepted', filtersFor(['account' => '12'])['account'] === '12'],
    ['"terminal" accepted as an account', filtersFor(['account' => 'terminal'])['account'] === 'terminal'],
    ['Account with SQL text ignored', filtersFor(['account' => '1 OR 1=1'])['account'] === ''],
    ['Account sent as an array ignored', filtersFor(['account' => ['1']])['account'] === ''],
    ['Real date accepted', filtersFor(['from' => '2026-02-28'])['from'] === '2026-02-28'],
    ['Impossible date ignored', filtersFor(['from' => '2026-02-30'])['from'] === ''],
    ['Date in another format ignored', filtersFor(['to' => '11/09/2026'])['to'] === ''],
    ['Leap day accepted only in a leap year', ActivityLog::isRealDate('2028-02-29') && !ActivityLog::isRealDate('2027-02-29')],
    ['Page 3 accepted', filtersFor(['page' => '3'])['page'] === 3],
    ['Page 0 falls back to 1', filtersFor(['page' => '0'])['page'] === 1],
    ['Negative page falls back to 1', filtersFor(['page' => '-2'])['page'] === 1],
    ['Huge page number falls back to 1', filtersFor(['page' => '9999999'])['page'] === 1],
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
