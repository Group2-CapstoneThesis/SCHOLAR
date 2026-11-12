<?php
// WHAT THIS FILE DOES: checks the visits and logins report rules (M12, FB-06) without a browser, session, or database.
// STEP 1: Load the real classes: Access, SiteVisits (what counts as a visit) and UsageReport (grouping per day, week, month).
// STEP 2: Check that only the head administrator may open the report, and that it is not a per-account switch.
// STEP 3: Check which browser names count as robots or scripts (not counted as visits).
// STEP 4: Check the grouping: weeks start on Monday, months on the 1st, labels, and the default ranges.
// STEP 5: Check the filters read from the address: odd values, dates the wrong way round, and ranges that are too long.
// STEP 6: Write a small CSV into memory and check its heading row and numbers.
// STEP 7: Print the total result.
// A fixed "today" (Monday 2026-11-09) is used, so the results never depend on the day the test runs.
declare(strict_types=1);

use Scholar\Auth\Access;
use Scholar\SiteVisits;
use Scholar\UsageReport;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

$today = '2026-11-09'; // a Monday

// shorthand: the filters for one made-up address query
function usageFilters(array $query, string $today): array {
    return UsageReport::filtersFromQuery($query, $today);
}

// shorthand: the period labels between two days
function labels(string $from, string $to, string $group): array {
    return array_column(UsageReport::periodsBetween(new DateTimeImmutable($from), new DateTimeImmutable($to), $group), 'label');
}

// a tiny made-up report written as CSV into memory
$report = [
    'periods' => [ // newest first, as build() returns them
        ['start' => '2026-11-09', 'end' => '2026-11-15', 'label' => '', 'visits' => 4, 'logins' => 1, 'account_logins' => [7 => 1]],
        ['start' => '2026-11-02', 'end' => '2026-11-08', 'label' => '', 'visits' => 2, 'logins' => 3, 'account_logins' => [7 => 2, 9 => 1]],
    ],
    'accounts' => [['id' => 7, 'username' => 'sample.staff', 'is_active' => true, 'logins' => 3], ['id' => 9, 'username' => '-sample', 'is_active' => true, 'logins' => 1]],
    'total_visits' => 6, 'total_logins' => 4,
];
$handle = fopen('php://memory', 'w+b');
UsageReport::writeCsv($report, $handle);
rewind($handle);
$csv = stream_get_contents($handle);
fclose($handle);
$csvLines = explode("\r\n", rtrim(substr($csv, 3), "\r\n"));

$defaultDaily = usageFilters([], $today);
$defaultWeekly = usageFilters(['group' => 'weekly'], $today);
$defaultMonthly = usageFilters(['group' => 'monthly'], $today);
$swapped = usageFilters(['from' => '2026-11-09', 'to' => '2026-11-01'], $today);
$tooLong = usageFilters(['group' => 'daily', 'from' => '2020-01-01', 'to' => '2026-11-09'], $today);

$cases = [
    // ---- who may open it ----
    ['Head administrator may open the report', Access::roleAllows('rdpo_head', 'report.usage')],
    ['RDPO administrator may not', !Access::roleAllows('rdpo_admin', 'report.usage')],
    ['Not a per-account switch', !isset(Access::SWITCHABLE_PERMISSIONS['report.usage'])],

    // ---- robots and scripts ----
    ['An ordinary browser is a person', !SiteVisits::isAutomatedAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36')],
    ['A phone browser is a person', !SiteVisits::isAutomatedAgent('Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36')],
    ['Googlebot is a robot', SiteVisits::isAutomatedAgent('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')],
    ['curl is a script', SiteVisits::isAutomatedAgent('curl/8.4.0')],
    ['python-requests is a script', SiteVisits::isAutomatedAgent('python-requests/2.31')],
    ['No browser name at all is not counted', SiteVisits::isAutomatedAgent('') && SiteVisits::isAutomatedAgent('   ')],

    // ---- periods ----
    ['A week starts on Monday', UsageReport::periodStart(new DateTimeImmutable('2026-11-15'), 'weekly')->format('Y-m-d') === '2026-11-09'],
    ['Monday is the start of its own week', UsageReport::periodStart(new DateTimeImmutable('2026-11-09'), 'weekly')->format('Y-m-d') === '2026-11-09'],
    ['A week ends on Sunday', UsageReport::periodEnd(new DateTimeImmutable('2026-11-10'), 'weekly')->format('Y-m-d') === '2026-11-15'],
    ['A month starts on the 1st', UsageReport::periodStart(new DateTimeImmutable('2026-11-09'), 'monthly')->format('Y-m-d') === '2026-11-01'],
    ['February 2028 ends on the 29th', UsageReport::periodEnd(new DateTimeImmutable('2028-02-10'), 'monthly')->format('Y-m-d') === '2028-02-29'],
    ['Daily labels', labels('2026-11-08', '2026-11-09', 'daily') === ['Sun, Nov 8, 2026', 'Mon, Nov 9, 2026']],
    ['Weekly labels, including across the new year', labels('2026-12-28', '2027-01-04', 'weekly') === ['Dec 28, 2026 - Jan 3, 2027', 'Jan 4 - Jan 10, 2027']],
    ['Monthly labels', labels('2026-10-15', '2026-11-09', 'monthly') === ['October 2026', 'November 2026']],

    // ---- filters ----
    ['Default: daily, the last 14 days', $defaultDaily['group'] === 'daily' && $defaultDaily['from'] === '2026-10-27' && $defaultDaily['to'] === '2026-11-09'],
    ['Default weekly: 12 whole weeks', $defaultWeekly['from'] === '2026-08-24' && $defaultWeekly['to'] === '2026-11-15'
        && count(UsageReport::periodsBetween(new DateTimeImmutable($defaultWeekly['from']), new DateTimeImmutable($defaultWeekly['to']), 'weekly')) === 12],
    ['Default monthly: 12 whole months', $defaultMonthly['from'] === '2025-12-01' && $defaultMonthly['to'] === '2026-11-30'],
    ['Unknown grouping falls back to daily', usageFilters(['group' => 'hourly'], $today)['group'] === 'daily'],
    ['Impossible date ignored', usageFilters(['from' => '2026-02-30'], $today)['typed_from'] === ''],
    ['Dates the wrong way round are swapped', $swapped['from'] === '2026-11-01' && $swapped['to'] === '2026-11-09'],
    ['A chosen range is widened to whole months', usageFilters(['group' => 'monthly', 'from' => '2026-03-15', 'to' => '2026-04-02'], $today)['from'] === '2026-03-01'
        && usageFilters(['group' => 'monthly', 'from' => '2026-03-15', 'to' => '2026-04-02'], $today)['to'] === '2026-04-30'],
    ['A daily range longer than 366 days is shortened from the start', $tooLong['was_shortened'] && $tooLong['to'] === '2026-11-09' && $tooLong['from'] === '2025-11-09'],

    // ---- the CSV ----
    ['CSV starts with the UTF-8 mark', strncmp($csv, "\xEF\xBB\xBF", 3) === 0],
    // fputcsv() puts quotes around headings that contain spaces; Excel removes them
    ['CSV heading row names each account', $csvLines[0] === '"Period start","Period end","Website visits","Logins (all accounts)","Logins: sample.staff","Logins: -sample"'],
    ['CSV rows run oldest first with each account\'s logins', $csvLines[1] === '2026-11-02,2026-11-08,2,3,2,1' && $csvLines[2] === '2026-11-09,2026-11-15,4,1,1,0'],
    ['CSV has one row per period', count($csvLines) === 3],
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
