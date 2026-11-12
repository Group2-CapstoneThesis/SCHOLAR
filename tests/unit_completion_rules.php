<?php
// WHAT THIS FILE DOES: checks the author-name and completion-list rules without a browser, session, or database.
// STEP 1: Load the real classes: AuthorNames (name parts) and CompletionList (Registrar list helpers).
// STEP 2: Split printed and typed names into last name, given names and suffix, including "Dela Cruz" style last names.
// STEP 3: Build full names from parts and turn autofill's one-name-per-line text into form rows.
// STEP 4: Check the list filters, the download file name and the protection against spreadsheet formulas.
// STEP 5: Write a small CSV into memory and check its first bytes, the one-cell "Last name, Given names" lines
//         (no heading row, no other columns) and the escaped formula-looking name.
// STEP 6: Print the total result.
// Each case is worked out first and printed afterwards, so every case runs even when an earlier one fails.
// All names here are made up for testing.
declare(strict_types=1);

use Scholar\Theses\AuthorNames;
use Scholar\Theses\CompletionList;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

// shorthand: the parts of one name as [last name, given names, suffix], so the cases below stay short
function partsOf(string $name): array {
    $parts = AuthorNames::splitFullName($name);
    return [$parts['last_name'], $parts['given_names'], $parts['suffix']];
}

// writes the given rows with CompletionList::writeCsv() into memory (not a real file) and returns the text
function csvText(array $rows): string {
    $handle = fopen('php://memory', 'w+b');
    CompletionList::writeCsv($rows, $handle);
    rewind($handle); // go back to the start before reading what was written
    $text = stream_get_contents($handle);
    fclose($handle);
    return $text;
}

// three made-up rows: an ordinary name with a suffix, an old row without name parts, and a name that looks like a formula
$sampleRow = [
    'last_name' => 'Dela Prueba', 'given_names' => 'Muestra Ana', 'suffix' => 'Jr.', 'author_name' => 'Muestra Ana Dela Prueba Jr.',
    'thesis_id' => 1, 'title' => 'Sample Title', 'program' => 'BS Sample Studies',
    'department' => 'College of Sample Studies', 'final_defense_date' => '2025-04-10', 'completion_year' => 2025,
];
$oldRow = array_merge($sampleRow, ['last_name' => '', 'given_names' => '', 'suffix' => null, 'author_name' => 'Old Sample Name']);
$formulaRow = array_merge($sampleRow, ['last_name' => '=SUM(1+1)', 'given_names' => 'Muestra', 'suffix' => null]);
$csv = csvText([$sampleRow, $oldRow, $formulaRow]);
$csvLines = explode("\r\n", substr($csv, 3)); // substr() skips the 3-byte UTF-8 mark at the start

$cases = [
    // ---- splitting names ----
    ['"Last, Given" keeps the printed order', partsOf('Dela Cruz, Maria Clara S.') === ['Dela Cruz', 'Maria Clara S.', '']],
    ['Suffix as its own comma piece', partsOf('Reyes, Jose Miguel T., Jr.') === ['Reyes', 'Jose Miguel T.', 'Jr.']],
    ['Suffix stuck to the last name', partsOf('Reyes Jr., Jose Miguel') === ['Reyes', 'Jose Miguel', 'Jr.']],
    ['Suffix after the given names', partsOf('Reyes, Jose Miguel III') === ['Reyes', 'Jose Miguel', 'III']],
    ['"Given Last": last word is the last name', partsOf('Carlo Ramos') === ['Ramos', 'Carlo', '']],
    ['"dela" stays with the last name', partsOf('Juan Miguel dela Cruz Jr.') === ['dela Cruz', 'Juan Miguel', 'Jr.']],
    ['"de los" stays with the last name', partsOf('Ana de los Santos') === ['de los Santos', 'Ana', '']],
    ['At least one given name is kept', partsOf('San Juan') === ['Juan', 'San', '']],
    ['Middle initial stays with the given names', partsOf('Maria Clara S. Santos') === ['Santos', 'Maria Clara S.', '']],
    ['Hyphenated last name kept whole', partsOf('Prueba-Ag, Muestra L.') === ['Prueba-Ag', 'Muestra L.', '']],
    ['One word becomes the last name', partsOf('Muestra') === ['Muestra', '', '']],
    ['Extra spaces are tidied', partsOf('  Ana    Cruz ') === ['Cruz', 'Ana', '']],
    ['Letters like ñ are kept', partsOf('Ibañez, Niño') === ['Ibañez', 'Niño', '']],
    ['Empty text gives empty parts', partsOf('   ') === ['', '', '']],

    // ---- full names and autofill rows ----
    ['Full name from parts', AuthorNames::fullName('Juan Miguel', 'Dela Cruz', 'Jr.') === 'Juan Miguel Dela Cruz Jr.'],
    ['Full name without a suffix', AuthorNames::fullName('Ana', 'Cruz', null) === 'Ana Cruz'],
    ['Autofill lines become form rows', AuthorNames::rowsFromLines("Dela Cruz, Maria Clara S.\n\nReyes, Jose Miguel T.") === [
        ['last_name' => 'Dela Cruz', 'given_names' => 'Maria Clara S.', 'suffix' => ''],
        ['last_name' => 'Reyes', 'given_names' => 'Jose Miguel T.', 'suffix' => ''],
    ]],

    // ---- filters and file name ----
    ['Four-digit year accepted', CompletionList::filtersFromQuery(['year' => '2025'])['year'] === '2025'],
    ['Year with SQL text ignored', CompletionList::filtersFromQuery(['year' => '2025 OR 1=1'])['year'] === ''],
    ['Array department ignored', CompletionList::filtersFromQuery(['department' => ['x']])['department'] === ''],
    ['Overlong department ignored', CompletionList::filtersFromQuery(['department' => str_repeat('d', 151)])['department'] === ''],
    ['File name for all years', CompletionList::fileName(['year' => '', 'department' => '']) === 'completion_list_all_years.csv'],
    ['File name keeps only safe characters', CompletionList::fileName(['year' => '2025', 'department' => 'College of "Sample"/Studies']) === 'completion_list_2025_college_of_sample_studies.csv'],

    // ---- spreadsheet formula protection ----
    ['Cell starting with = is made plain text', CompletionList::safeCell('=1+1') === "'=1+1"],
    ['Cell starting with + is made plain text', CompletionList::safeCell('+63 900') === "'+63 900"],
    ['Cell starting with - is made plain text', CompletionList::safeCell('-5') === "'-5"],
    ['Cell starting with @ is made plain text', CompletionList::safeCell('@SUM(A1)') === "'@SUM(A1)"],
    ['Ordinary cell unchanged', CompletionList::safeCell('Dela Cruz') === 'Dela Cruz'],

    // ---- the CSV itself ----
    ['CSV starts with the UTF-8 mark for Excel', strncmp($csv, "\xEF\xBB\xBF", 3) === 0],
    ['CSV has one line per name and no heading row', count($csvLines) === 4 && $csvLines[3] === ''],
    ['Name is "Last name, Given names" in one quoted cell', $csvLines[0] === '"Dela Prueba, Muestra Ana Jr."'],
    ['No other columns in the file', strpos($csv, 'Sample Title') === false && strpos($csv, 'BS Sample Studies') === false && strpos($csv, '2025') === false],
    ['Old row shows its whole saved name', $csvLines[1] === '"Old Sample Name"'],
    ['Formula-looking name escaped in the CSV', $csvLines[2] === '"\'=SUM(1+1), Muestra"'],
    ['List name without a suffix', CompletionList::listName($formulaRow) === '=SUM(1+1), Muestra'],
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
