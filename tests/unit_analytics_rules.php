<?php
// WHAT THIS FILE DOES: checks the research analytics rules (M13, FB-05) without a browser, session, or database.
// STEP 1: Load the real classes: Access and ResearchAnalytics.
// STEP 2: Check who may open the analytics: every RDPO role, and it can be switched off per account.
// STEP 3: Check the filters read from the address, including made-up values, the year range and the file name.
// STEP 4: Check the order of years (newest first, "no year" last) and departments (A-Z, "no department" last).
// STEP 5: Write a small made-up report as CSV into memory and check every line, including a formula-looking department.
// STEP 6: Print the total result.
declare(strict_types=1);

use Scholar\Auth\Access;
use Scholar\Theses\ResearchAnalytics;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

// shorthand: a list sorted with one of the class's sort helpers
function sortedWith(array $items, string $helper): array {
    usort($items, 'Scholar\Theses\ResearchAnalytics::' . $helper);
    return $items;
}

// a tiny made-up report, shaped like ResearchAnalytics::build() returns it
$report = [
    'years' => ['2025', '2024', ''],
    'departments' => ['=Sample Dept', 'College of Sample Studies', ResearchAnalytics::NO_DEPARTMENT],
    'cells' => ['2025' => ['College of Sample Studies' => 2], '2024' => ['=Sample Dept' => 1, 'College of Sample Studies' => 1], '' => [ResearchAnalytics::NO_DEPARTMENT => 1]],
    'by_year' => ['2025' => ['theses' => 2, 'students' => 5], '2024' => ['theses' => 2, 'students' => 3], '' => ['theses' => 1, 'students' => 1]],
    'by_department' => ['=Sample Dept' => ['theses' => 1, 'students' => 1], 'College of Sample Studies' => ['theses' => 3, 'students' => 7], ResearchAnalytics::NO_DEPARTMENT => ['theses' => 1, 'students' => 1]],
    'total_theses' => 5, 'total_students' => 9,
];
$handle = fopen('php://memory', 'w+b');
ResearchAnalytics::writeCsv($report, $handle);
rewind($handle);
$csv = stream_get_contents($handle);
fclose($handle);
$lines = explode("\r\n", rtrim(substr($csv, 3), "\r\n"));

$cases = [
    // ---- who may open it ----
    ['RDPO administrator may open the analytics', Access::roleAllows('rdpo_admin', 'report.analytics')],
    ['Head administrator may open the analytics', Access::roleAllows('rdpo_head', 'report.analytics')],
    ['It can be switched off for one account', isset(Access::SWITCHABLE_PERMISSIONS['report.analytics'])],
    ['Switched off, it is refused', !Access::allows(['role_code' => 'rdpo_admin', 'revoked_permissions' => ['report.analytics']], 'report.analytics')],

    // ---- filters ----
    ['Defaults: thesis year, all active records, every year', ResearchAnalytics::filtersFromQuery([]) === ['basis' => 'thesis', 'scope' => 'active', 'from_year' => '', 'to_year' => '']],
    ['Defense year accepted', ResearchAnalytics::filtersFromQuery(['basis' => 'defense'])['basis'] === 'defense'],
    ['Published only accepted', ResearchAnalytics::filtersFromQuery(['scope' => 'public'])['scope'] === 'public'],
    ['Made-up year basis ignored', ResearchAnalytics::filtersFromQuery(['basis' => 'submitted'])['basis'] === 'thesis'],
    ['"archived" is not a scope (archived records are never counted)', ResearchAnalytics::filtersFromQuery(['scope' => 'archived'])['scope'] === 'active'],
    ['Values sent as arrays ignored', ResearchAnalytics::filtersFromQuery(['basis' => ['defense'], 'scope' => ['public'], 'from_year' => ['2020']])
        === ['basis' => 'thesis', 'scope' => 'active', 'from_year' => '', 'to_year' => '']],

    // ---- year range ----
    ['Year range accepted', ResearchAnalytics::filtersFromQuery(['from_year' => '2020', 'to_year' => '2025'])['from_year'] === '2020'
        && ResearchAnalytics::filtersFromQuery(['from_year' => '2020', 'to_year' => '2025'])['to_year'] === '2025'],
    ['Years the wrong way round are swapped', ResearchAnalytics::filtersFromQuery(['from_year' => '2025', 'to_year' => '2020'])['from_year'] === '2020'],
    ['Only one end of the range is fine', ResearchAnalytics::filtersFromQuery(['to_year' => '2022'])['from_year'] === ''
        && ResearchAnalytics::hasYearRange(ResearchAnalytics::filtersFromQuery(['to_year' => '2022']))],
    ['A year with SQL text is ignored', ResearchAnalytics::filtersFromQuery(['from_year' => '2020 OR 1=1'])['from_year'] === ''],
    ['A two-digit year is ignored', ResearchAnalytics::filtersFromQuery(['to_year' => '25'])['to_year'] === ''],
    ['No range when both ends are empty', !ResearchAnalytics::hasYearRange(ResearchAnalytics::filtersFromQuery([]))],
    ['File name without a range', ResearchAnalytics::fileName(ResearchAnalytics::filtersFromQuery([])) === 'research_totals_by_thesis_year.csv'],
    ['File name with a range and published only', ResearchAnalytics::fileName(ResearchAnalytics::filtersFromQuery(['basis' => 'defense', 'scope' => 'public', 'from_year' => '2020', 'to_year' => '2025']))
        === 'research_totals_by_defense_year_2020-2025_published_only.csv'],
    ['File name with only one end of the range', ResearchAnalytics::fileName(ResearchAnalytics::filtersFromQuery(['from_year' => '2021'])) === 'research_totals_by_thesis_year_2021-latest.csv'],

    // ---- order ----
    ['Years newest first, "no year" last', sortedWith(['2019', '', '2025', '2021'], 'newerYearFirst') === ['2025', '2021', '2019', '']],
    ['Departments A-Z ignoring case, "no department" last', sortedWith([ResearchAnalytics::NO_DEPARTMENT, 'college of b', 'College of A'], 'departmentOrder')
        === ['College of A', 'college of b', ResearchAnalytics::NO_DEPARTMENT]],
    ['Year label for a missing year', ResearchAnalytics::yearLabel('') === 'No year recorded' && ResearchAnalytics::yearLabel('2025') === '2025'],
    ['An empty cell counts as 0', ResearchAnalytics::cell($report, '2025', '=Sample Dept') === 0 && ResearchAnalytics::cell($report, '2024', '=Sample Dept') === 1],

    // ---- the CSV ----
    ['CSV starts with the UTF-8 mark', strncmp($csv, "\xEF\xBB\xBF", 3) === 0],
    ['Heading row: Year, each department, totals; a formula-looking department is made plain text',
        $lines[0] === 'Year,"\'=Sample Dept","College of Sample Studies","No department recorded","Total theses","Total students"'],
    ['One row per year with each department\'s theses', $lines[1] === '2025,0,2,0,2,5' && $lines[2] === '2024,1,1,0,2,3'],
    ['Records without a year get their own row', $lines[3] === '"No year recorded",0,0,1,1,1'],
    ['A Total row at the end', $lines[4] === 'Total,1,3,1,5,9' && count($lines) === 5],
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
