<?php
// WHAT THIS FILE DOES: checks the record rules without opening a browser, session, or database.
// STEP 1: Load the real classes: Access (who may do what), ThesisValidator (form rules) and ThesisSearch (filter reading).
// STEP 2: Check that the RDPO role has its permissions and that unknown roles or permissions are denied.
// STEP 3: Submit a complete valid form and check the cleaned values, including the author rows and department.
// STEP 4: Submit one broken field at a time and check that exactly that field is reported.
// STEP 5: Check that search filters from the address bar fall back to safe defaults when they are invalid.
// STEP 6: Print the total result.
// Each case is worked out first and printed afterwards, so every case runs even when an earlier one fails.
declare(strict_types=1);

use Scholar\Auth\Access;
use Scholar\Theses\ThesisSearch;
use Scholar\Theses\ThesisValidator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

// a complete, valid form submission; each broken case below changes only one field of it
$validForm = [
    'title' => '  Synthetic Study: A Fictional Test Record  ',
    // author rows: the same position in each list is one author; the empty middle row is skipped
    'author_last_name' => ['  Verifier ', '', 'Dela Prueba'],
    'author_given_names' => ['Synthetic One', '', 'Sample Two'],
    'author_suffix' => ['', '', 'Jr.'],
    'publication_year' => '2025',
    'program' => 'BS Information Technology',
    'department' => ' College of Sample Studies ',
    'campus' => 'WCC Antipolo',
    'adviser' => '',
    'abstract' => "First line of a fictional abstract.\nSecond line.",
    'keywords' => ' testing ,, fictional,records ',
    'date_submitted' => '2025-05-20',
    'final_defense_date' => '2025-04-10',
    'page_count' => '88',
    'is_public' => '1',
];
$validator = new ThesisValidator();

// runs the validator on the valid form with one field replaced, and returns the error list
function errorsWith(ThesisValidator $validator, array $validForm, string $field, $value): array {
    $form = $validForm;
    $form[$field] = $value;
    return $validator->validate($form, true)['errors'];
}

// true when the ONLY reported problem is the expected field
function onlyErrorIs(array $errors, string $field): bool {
    return array_keys($errors) === [$field];
}

$valid = $validator->validate($validForm, true);
$editValid = $validator->validate($validForm, false); // the edit form has no visibility checkbox
$nextYear = (string) ((int) date('Y') + 1);
$tooLateYear = (string) ((int) date('Y') + 2);

// how many of the five campuses pass the form check (all five should)
$acceptedCampuses = 0;
foreach (Scholar\Theses\Campuses::NAMES as $campusName) {
    if (!isset($validator->validate(array_merge($validForm, ['campus' => $campusName]), true)['errors']['campus'])) {
        $acceptedCampuses++;
    }
}

$cases = [
    // ---- Access: default deny ----
    ['RDPO role may create records', Access::roleAllows('rdpo_admin', 'thesis.create') === true],
    ['RDPO role may publish records', Access::roleAllows('rdpo_admin', 'thesis.publish') === true],
    ['RDPO role may open the completion list', Access::roleAllows('rdpo_admin', 'report.completion_list') === true],
    ['Unknown permission is denied', Access::roleAllows('rdpo_admin', 'thesis.delete') === false],
    ['Unknown role is denied', Access::roleAllows('library_staff', 'thesis.view_all') === false],
    ['Empty role is denied', Access::roleAllows('', 'thesis.view_all') === false],

    // ---- valid form and cleaning ----
    ['Valid form has no errors', count($valid['errors']) === 0],
    ['Title is trimmed', $valid['values']['title'] === 'Synthetic Study: A Fictional Test Record'],
    ['Author rows cleaned, empty row skipped, order kept', $valid['values']['authors'] === [
        ['given_names' => 'Synthetic One', 'last_name' => 'Verifier', 'suffix' => null, 'full_name' => 'Synthetic One Verifier'],
        ['given_names' => 'Sample Two', 'last_name' => 'Dela Prueba', 'suffix' => 'Jr.', 'full_name' => 'Sample Two Dela Prueba Jr.'],
    ]],
    ['Department is trimmed', $valid['values']['department'] === 'College of Sample Studies'],
    ['Chosen campus is kept', $valid['values']['campus'] === 'WCC Antipolo'],
    ['Missing campus reported', onlyErrorIs(errorsWith($validator, $validForm, 'campus', ''), 'campus')],
    ['A campus not on the list is refused', onlyErrorIs(errorsWith($validator, $validForm, 'campus', 'WCC Elsewhere'), 'campus')],
    ['A campus in the wrong case is refused', onlyErrorIs(errorsWith($validator, $validForm, 'campus', 'wcc qc'), 'campus')],
    ['Every one of the five campuses is accepted', $acceptedCampuses === 5],
    ['Empty department is saved as NULL', $validator->validate(array_merge($validForm, ['department' => '']), true)['values']['department'] === null],
    ['Failed form shows the filled author rows again, without the empty one', count($validator->formValuesFrom($validForm)['authors']) === 2 && $validator->formValuesFrom($validForm)['authors'][1] === ['last_name' => 'Dela Prueba', 'given_names' => 'Sample Two', 'suffix' => 'Jr.']],
    ['Year becomes a number', $valid['values']['publication_year'] === 2025],
    ['Empty adviser is saved as NULL', $valid['values']['adviser'] === null],
    ['Keywords tidied into "a, b, c"', $valid['values']['keywords'] === 'testing, fictional, records'],
    ['Page count becomes a number', $valid['values']['page_count'] === 88],
    ['Ticked checkbox publishes a new record', $valid['values']['is_public'] === true],
    ['Edit form ignores the visibility checkbox', $editValid['values']['is_public'] === false],

    // ---- one broken field at a time ----
    ['Missing title reported', onlyErrorIs(errorsWith($validator, $validForm, 'title', '   '), 'title')],
    ['Overlong title reported', onlyErrorIs(errorsWith($validator, $validForm, 'title', str_repeat('a', 501)), 'title')],
    ['Title with a line break reported', onlyErrorIs(errorsWith($validator, $validForm, 'title', "Line one\nLine two"), 'title')],
    ['Array instead of title text reported', onlyErrorIs(errorsWith($validator, $validForm, 'title', ['bad']), 'title')],
    ['Broken UTF-8 text reported', onlyErrorIs(errorsWith($validator, $validForm, 'title', "bad \xC3\x28 text"), 'title')],
    ['No authors reported', onlyErrorIs(errorsWith($validator, array_merge($validForm, ['author_given_names' => [' ', ''], 'author_suffix' => []]), 'author_last_name', ['', ' ']), 'authors')],
    ['Missing last name reported', onlyErrorIs(errorsWith($validator, $validForm, 'author_last_name', ['', '', 'Dela Prueba']), 'authors')],
    ['Missing given names reported', onlyErrorIs(errorsWith($validator, $validForm, 'author_given_names', ['Synthetic One', '', '']), 'authors')],
    ['Error names the author row', errorsWith($validator, $validForm, 'author_given_names', ['Synthetic One', '', ''])['authors'] === 'Author 2: enter the first name.'],
    ['More than 20 authors reported', onlyErrorIs(errorsWith($validator, array_merge($validForm, ['author_given_names' => array_fill(0, 21, 'Given'), 'author_suffix' => []]), 'author_last_name', array_fill(0, 21, 'Last')), 'authors')],
    ['Overlong suffix reported', onlyErrorIs(errorsWith($validator, $validForm, 'author_suffix', ['', '', str_repeat('I', 21)]), 'authors')],
    ['Overlong full name reported', onlyErrorIs(errorsWith($validator, $validForm, 'author_last_name', [str_repeat('a', 190), '', 'Dela Prueba']), 'authors')],
    ['Text instead of an author list reported', onlyErrorIs(errorsWith($validator, $validForm, 'author_last_name', 'Verifier'), 'authors')],
    ['Nested list in author names reported', onlyErrorIs(errorsWith($validator, $validForm, 'author_last_name', [['Verifier']]), 'authors')],
    ['Line break in an author name reported', onlyErrorIs(errorsWith($validator, $validForm, 'author_last_name', ["Veri\nfier", '', 'Dela Prueba']), 'authors')],
    ['Overlong department reported', onlyErrorIs(errorsWith($validator, $validForm, 'department', str_repeat('d', 151)), 'department')],
    ['Three-digit year reported', onlyErrorIs(errorsWith($validator, $validForm, 'publication_year', '999'), 'publication_year')],
    ['Year with letters reported', onlyErrorIs(errorsWith($validator, $validForm, 'publication_year', '20x5'), 'publication_year')],
    ['Next year accepted', count(errorsWith($validator, $validForm, 'publication_year', $nextYear)) === 0],
    ['Two years ahead reported', onlyErrorIs(errorsWith($validator, $validForm, 'publication_year', $tooLateYear), 'publication_year')],
    ['Missing program reported', onlyErrorIs(errorsWith($validator, $validForm, 'program', ''), 'program')],
    ['Empty abstract accepted (optional since FB-01)', count(errorsWith($validator, $validForm, 'abstract', '  ')) === 0],
    ['Empty abstract is saved as NULL', $validator->validate(array_merge($validForm, ['abstract' => '']), true)['values']['abstract'] === null],
    ['Overlong abstract reported', onlyErrorIs(errorsWith($validator, $validForm, 'abstract', str_repeat('a', 10001)), 'abstract')],
    ['Hidden control character in abstract reported', onlyErrorIs(errorsWith($validator, $validForm, 'abstract', "Text\x07bell"), 'abstract')],
    ['Impossible date reported', onlyErrorIs(errorsWith($validator, $validForm, 'date_submitted', '2025-02-30'), 'date_submitted')],
    ['Date in another format reported', onlyErrorIs(errorsWith($validator, $validForm, 'final_defense_date', '04/10/2025'), 'final_defense_date')],
    ['Defense after submission reported', onlyErrorIs(errorsWith($validator, $validForm, 'final_defense_date', '2025-06-01'), 'final_defense_date')],
    ['Zero pages reported', onlyErrorIs(errorsWith($validator, $validForm, 'page_count', '0'), 'page_count')],
    ['Decimal page count reported', onlyErrorIs(errorsWith($validator, $validForm, 'page_count', '12.5'), 'page_count')],

    // ---- search filters from the address bar ----
    ['Unknown sort falls back to newest', ThesisSearch::filtersFromQuery(['sort' => 'DROP TABLE'], false)['sort'] === 'newest'],
    ['Public page cannot use the RDPO-only sort', ThesisSearch::filtersFromQuery(['sort' => 'updated'], false)['sort'] === 'newest'],
    ['RDPO list may use the recently-changed sort', ThesisSearch::filtersFromQuery(['sort' => 'updated'], true)['sort'] === 'updated'],
    ['Public page ignores the status filter', ThesisSearch::filtersFromQuery(['status' => 'archived'], false)['status'] === 'active'],
    ['Non-numeric year ignored', ThesisSearch::filtersFromQuery(['year' => '2024 OR 1=1'], false)['year'] === null],
    ['Negative page falls back to page 1', ThesisSearch::filtersFromQuery(['page' => '-3'], false)['page'] === 1],
    ['Array search text ignored', ThesisSearch::filtersFromQuery(['q' => ['flood']], false)['q'] === ''],
    ['Page links keep filters and skip defaults',
        ThesisSearch::queryString(['q' => 'flood map', 'year' => 2024, 'program' => '', 'campus' => '', 'sort' => 'relevance', 'status' => 'active', 'page' => 1], 2) === 'q=flood+map&year=2024&page=2'],
    // with words typed the default is "Best match" (M14), so a chosen "Newest first" must stay in the link
    ['Page links keep "Newest first" when words are typed',
        ThesisSearch::queryString(['q' => 'flood', 'year' => null, 'program' => '', 'campus' => '', 'sort' => 'newest', 'status' => 'active', 'page' => 1], 2) === 'q=flood&sort=newest&page=2'],
    ['Typed words default to Best match', ThesisSearch::filtersFromQuery(['q' => 'flood'], false)['sort'] === 'relevance'],
    ['No words: Best match falls back to newest', ThesisSearch::filtersFromQuery(['sort' => 'relevance'], false)['sort'] === 'newest'],
    ['An empty sort value means the default order', ThesisSearch::filtersFromQuery(['q' => 'flood', 'sort' => ''], false)['sort'] === 'relevance'],
    ['Page links keep the campus filter', ThesisSearch::queryString(['q' => '', 'year' => null, 'program' => '', 'campus' => 'WCC North Manila', 'sort' => 'newest', 'status' => 'active', 'page' => 1], 2) === 'campus=WCC+North+Manila&page=2'],
    ['Campus filter accepted on the RDPO list', ThesisSearch::filtersFromQuery(['campus' => 'WCC Binalonan'], true)['campus'] === 'WCC Binalonan'],
    ['Made-up campus filter ignored', ThesisSearch::filtersFromQuery(['campus' => 'WCC Nowhere'], true)['campus'] === ''],
    ['Campus filter accepted on the public search too', ThesisSearch::filtersFromQuery(['campus' => 'WCC QC'], false)['campus'] === 'WCC QC'],
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
