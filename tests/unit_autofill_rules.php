<?php
// WHAT THIS FILE DOES: checks the autofill reading rules without a browser, web server, or MySQL database.
// STEP 1: Load the real ThesisMetadataExtractor.
// STEP 2: Feed it made-up pages of text in the WCC format (title page, approval sheet, abstract) and check every field.
// STEP 3: Feed it pages with parts missing or unusual (no approval sheet, no "A Thesis Presented to", odd dates, empty pages,
//         title pages without a "by" line, "By:" with a name on the same line, the program on the "Degree of" line).
// STEP 4: Generate a small made-up PDF with the same layout, including the vertical banner letters in the right margin,
//         and check that reading the actual PDF gives the same fields with no stray banner letters.
// STEP 5: Generate a PDF with no text at all (like a scanned image) and check that it is reported as unreadable.
// STEP 6: New WCC template (title, approval, acknowledgement, dedication, abstract, contents): an abstract that starts on
//         page 6 and ends on page 7 must be read whole, a table of contents after it must not interfere, and an
//         abstract placed after page 10 is not read (only the first 10 pages are searched).
// STEP 7: Delete the temporary files and print the total result.
declare(strict_types=1);

use Scholar\Theses\ThesisMetadataExtractor;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

// builds a small but complete PDF; each page is a list of [x, y, text] pieces drawn in Helvetica
// x is measured from the left edge and y from the bottom, in points (a Letter page is 612 x 792)
function makePdf(array $pages): string {
    $objects = [];
    $objects[1] = '<</Type/Catalog/Pages 2 0 R>>';
    $objects[3] = '<</Type/Font/Subtype/Type1/BaseFont/Helvetica/Encoding/WinAnsiEncoding>>';
    $pageIds = [];
    $nextId = 4;
    foreach ($pages as $pieces) {
        $stream = '';
        foreach ($pieces as $piece) {
            // inside PDF text, backslash and brackets must be escaped
            $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $piece[2]);
            $stream .= 'BT /F1 11 Tf ' . $piece[0] . ' ' . $piece[1] . ' Td (' . $text . ") Tj ET\n";
        }
        $contentId = $nextId;
        $pageId = $nextId + 1;
        $nextId += 2;
        $objects[$contentId] = '<</Length ' . strlen($stream) . ">>\nstream\n" . $stream . 'endstream';
        $objects[$pageId] = '<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Resources<</Font<</F1 3 0 R>>>>/Contents ' . $contentId . ' 0 R>>';
        $pageIds[] = $pageId . ' 0 R';
    }
    $objects[2] = '<</Type/Pages/Kids[' . implode(' ', $pageIds) . ']/Count ' . count($pageIds) . '>>';
    ksort($objects);

    // the cross-reference table lists where each object starts, as PDF readers expect
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xrefStart = strlen($pdf);
    $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= 'trailer<</Size ' . (count($objects) + 1) . "/Root 1 0 R>>\nstartxref\n" . $xrefStart . "\n%%EOF\n";
    return $pdf;
}

// turns a list of lines into pieces stacked down the page from the top, starting at $top, 16 points apart
function stackLines(array $lines, int $top): array {
    $pieces = [];
    $y = $top;
    foreach ($lines as $line) {
        $pieces[] = [72, $y, $line];
        $y -= 16;
    }
    return $pieces;
}

// the vertical "COLLEGE OF INFORMATION TECHNOLOGY" banner: one capital letter per line in the right margin
function bannerLetters(): array {
    $pieces = [];
    $y = 620;
    foreach (str_split('COLLEGEOFINFORMATIONTECHNOLOGY') as $letter) {
        $pieces[] = [565, $y, $letter];
        $y -= 14;
    }
    return $pieces;
}

// made-up pages in the institution's order: title page, approval sheet, abstract (continued on a 4th page)
$titlePage = [
    'W O R L D C I T I C O L L E G E S',
    'Sample Study: A Fictional Library',
    'Booking System for Testing',
    'A Thesis Presented to',
    'the Faculty of College of Information Technology',
    'World Citi Colleges, Quezon City',
    'In Partial Fulfillment of the Requirements for the Degree of',
    'Bachelor of Science in Information Technology',
    'by',
    'Dela Cruz, Maria Clara S.',
    'Reyes, Jose Miguel T.',
    'August, 2025',
];
$approvalPage = [
    'WORLD CITI COLLEGES ii',
    'Approval Sheet',
    'This research entitled, "Sample Study: A Fictional Library Booking System',
    'for Testing", prepared and submitted by Maria Clara S. Dela Cruz and',
    'Jose Miguel T. Reyes, has been examined and is recommended for acceptance.',
    'Prof. Sample Adviser',
    'Adviser',
    'THESIS REVIEW PANEL',
    'Date of Final Defense: March 3, 2025',
];
$abstractPage = [
    'WORLD CITI COLLEGES iii',
    'Abstract',
    'This made-up study describes a booking system with real-',
    'time room availability for a fictional library.',
];
$abstractContinued = [
    'WORLD CITI COLLEGES iv',
    'It was tested only with sample data.',
    'Keywords: booking system, fictional library and',
    'sample data.',
];

$extractor = new ThesisMetadataExtractor();
$full = $extractor->extractFromLines([$titlePage, $approvalPage, $abstractPage, $abstractContinued], 120);
$values = $full['values'];

// variations with parts missing
$noApproval = $extractor->extractFromLines([$titlePage, $abstractPage, $abstractContinued], 3);
$titleWithoutMarker = $titlePage;
unset($titleWithoutMarker[3]); // remove "A Thesis Presented to"
$noMarker = $extractor->extractFromLines([array_values($titleWithoutMarker), $approvalPage], 2);
$oddDatePage = $approvalPage;
$oddDatePage[8] = 'Date of Final Defense: sometime in 2025';
$oddDate = $extractor->extractFromLines([$titlePage, $oddDatePage], 2);
$emptyPages = $extractor->extractFromLines([['WORLD CITI COLLEGES', 'iii'], []], 2);

// title pages that print the authors without a line that only says "by"
$titleWithoutBy = $titlePage;
unset($titleWithoutBy[8]); // remove "by": the names follow the program line directly
$noBy = $extractor->extractFromLines([array_values($titleWithoutBy)], 1);
$titleWithByColon = $titlePage;
$titleWithByColon[8] = 'By: Dela Cruz, Maria Clara S.'; // "By:" and the first name on one line
unset($titleWithByColon[9]);
$byColon = $extractor->extractFromLines([array_values($titleWithByColon)], 1);
$titleOneLineDegree = [
    'Sample Study: A Fictional Library',
    'Booking System for Testing',
    'A Thesis Presented to',
    'the Faculty of College of Information Technology',
    'In Partial Fulfillment of the Requirements for the Degree of Bachelor of Science in Information Technology',
    'Byrne, Anna Lee P.', // a surname starting with "By" must not be mistaken for a "by" line
    'Reyes, Jose Miguel T.',
    'July 2025',
];
$oneLineDegree = $extractor->extractFromLines([$titleOneLineDegree], 1);
$titleNoProgramNoBy = ['Sample Study: A Fictional Library', 'A Thesis Presented to', 'the Faculty', 'Dela Cruz, Maria Clara S.', 'August, 2025'];
$noProgramNoBy = $extractor->extractFromLines([$titleNoProgramNoBy], 1);

// the same pages as a real PDF, with the banner letters drawn in the right margin of the first three pages
$workFolder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scholar_autofill_rules_' . bin2hex(random_bytes(6));
mkdir($workFolder);
$samplePdf = $workFolder . DIRECTORY_SEPARATOR . 'sample.pdf';
file_put_contents($samplePdf, makePdf([
    array_merge(stackLines($titlePage, 740), bannerLetters()),
    array_merge(stackLines($approvalPage, 740), bannerLetters()),
    array_merge(stackLines($abstractPage, 740), bannerLetters()),
    stackLines($abstractContinued, 740),
]));
$fromPdf = $extractor->extractFromFile($samplePdf);
$blankPdf = $workFolder . DIRECTORY_SEPARATOR . 'blank.pdf';
file_put_contents($blankPdf, makePdf([[]])); // one page, no text: like a scanned image
$fromBlank = $extractor->extractFromFile($blankPdf);
$notPdf = $workFolder . DIRECTORY_SEPARATOR . 'broken.pdf';
file_put_contents($notPdf, "%PDF-1.4\nthis is not really a PDF\n");
$fromBroken = $extractor->extractFromFile($notPdf);

// ---- new WCC template: acknowledgement (2 pages) and dedication push the abstract to pages 6-7 ----
$acknowledgementPage1 = ['WORLD CITI COLLEGES iii', 'Acknowledgement', 'The made-up researchers thank their fictional families.'];
$acknowledgementPage2 = ['WORLD CITI COLLEGES iv', 'They also thank the sample library staff for their time.'];
$dedicationPage = ['WORLD CITI COLLEGES v', 'Dedication', 'To every fictional reader of this sample.'];
$abstractStartPage = [
    'WORLD CITI COLLEGES vi',
    'Abstract',
    'This made-up study starts its abstract on page six',
    'and describes a fictional reading room schedule.',
];
$abstractEndPage = [
    'WORLD CITI COLLEGES vii',
    'The abstract ends on page seven with its keywords.',
    'Keywords: reading room, fictional schedule',
];
// the contents page lists "Abstract" again (on its own line here, the hardest case); the first heading must still win
$contentsPage = ['WORLD CITI COLLEGES viii', 'Table of Contents', 'Abstract', 'Chapter I Introduction'];
$newTemplatePages = [$titlePage, $approvalPage, $acknowledgementPage1, $acknowledgementPage2, $dedicationPage,
    $abstractStartPage, $abstractEndPage, $contentsPage];
$newTemplate = $extractor->extractFromLines($newTemplatePages, 80);

// the same abstract without its "Keywords:" line, followed by the contents page
$abstractEndNoKeywords = ['WORLD CITI COLLEGES vii', 'The abstract ends on page seven with its keywords.'];
$noKeywords = $extractor->extractFromLines([$titlePage, $approvalPage, $acknowledgementPage1, $acknowledgementPage2,
    $dedicationPage, $abstractStartPage, $abstractEndNoKeywords, $contentsPage], 8);

// as a real PDF: proves the page limit itself reaches page 7 (the old limit of 6 pages lost the end and the keywords)
$newTemplatePdf = $workFolder . DIRECTORY_SEPARATOR . 'new_template.pdf';
$newTemplatePdfPages = [];
foreach ($newTemplatePages as $pageLines) {
    $newTemplatePdfPages[] = array_merge(stackLines($pageLines, 740), bannerLetters());
}
file_put_contents($newTemplatePdf, makePdf($newTemplatePdfPages));
$fromNewTemplatePdf = $extractor->extractFromFile($newTemplatePdf);

// an abstract that only appears on page 11 is beyond the 10 pages searched, so it stays empty
$lateAbstractPdf = $workFolder . DIRECTORY_SEPARATOR . 'late_abstract.pdf';
$lateAbstractPages = [stackLines($titlePage, 740), stackLines($approvalPage, 740)];
for ($fillerPage = 3; $fillerPage <= 10; $fillerPage++) {
    $lateAbstractPages[] = stackLines(['WORLD CITI COLLEGES', 'Sample filler page ' . $fillerPage . '.'], 740);
}
$lateAbstractPages[] = stackLines($abstractStartPage, 740); // page 11
file_put_contents($lateAbstractPdf, makePdf($lateAbstractPages));
$fromLateAbstractPdf = $extractor->extractFromFile($lateAbstractPdf);
$expectedLongAbstract = 'This made-up study starts its abstract on page six and describes a fictional reading room schedule. '
    . 'The abstract ends on page seven with its keywords.';

$expectedTitle = 'Sample Study: A Fictional Library Booking System for Testing';
$expectedAbstract = 'This made-up study describes a booking system with real-time room availability for a fictional library. It was tested only with sample data.';

$cases = [
    ['Pages with text are readable', $full['readable'] === true],
    ['Title joined from its two lines', $values['title'] === $expectedTitle],
    ['Program read from the line after "Degree of"', $values['program'] === 'Bachelor of Science in Information Technology'],
    ['Authors kept as printed, one per line', $values['authors'] === "Dela Cruz, Maria Clara S.\nReyes, Jose Miguel T."],
    ['Year read from the month/year line', $values['publication_year'] === '2025'],
    ['Adviser read from the line above "Adviser"', $values['adviser'] === 'Prof. Sample Adviser'],
    ['Defense date turned into YYYY-MM-DD', $values['final_defense_date'] === '2025-03-03'],
    ['Abstract joined across lines and pages, hyphen kept', $values['abstract'] === $expectedAbstract],
    ['Keywords split by commas, final "and" and full stop handled', $values['keywords'] === 'booking system, fictional library, sample data'],
    ['Page count comes from the whole PDF', $values['page_count'] === '120'],
    ['Header and page numbers never leak into fields', strpos(implode(' ', $values), 'COLLEGES') === false && strpos($values['abstract'], 'iv') === false],
    ['Date submitted is never guessed', !isset($values['date_submitted'])],
    ['Without an approval sheet, adviser and defense date stay empty', !isset($noApproval['values']['adviser']) && !isset($noApproval['values']['final_defense_date'])],
    ['Without an approval sheet, the other fields still fill', $noApproval['values']['title'] === $expectedTitle && isset($noApproval['values']['abstract'])],
    ['Without "A Thesis Presented to", the quoted approval-sheet title is used', $noMarker['values']['title'] === $expectedTitle],
    ['An unreadable defense date leaves the field empty', !isset($oddDate['values']['final_defense_date'])],
    ['Pages with only a header count as unreadable', $emptyPages['readable'] === false],
    ['Without "by", authors after the program line are read', isset($noBy['values']['authors']) && $noBy['values']['authors'] === "Dela Cruz, Maria Clara S.\nReyes, Jose Miguel T."],
    ['Without "by", the year is still read', isset($noBy['values']['publication_year']) && $noBy['values']['publication_year'] === '2025'],
    ['"By:" with a name on the same line keeps that name', isset($byColon['values']['authors']) && $byColon['values']['authors'] === "Dela Cruz, Maria Clara S.\nReyes, Jose Miguel T."],
    ['Program on the "Degree of" line, then authors and year', isset($oneLineDegree['values']['program'], $oneLineDegree['values']['authors'], $oneLineDegree['values']['publication_year'])
        && $oneLineDegree['values']['program'] === 'Bachelor of Science in Information Technology'
        && $oneLineDegree['values']['authors'] === "Byrne, Anna Lee P.\nReyes, Jose Miguel T."
        && $oneLineDegree['values']['publication_year'] === '2025'],
    ['No program and no "by": year still read, authors left for RDPO', isset($noProgramNoBy['values']['publication_year']) && $noProgramNoBy['values']['publication_year'] === '2025' && !isset($noProgramNoBy['values']['authors'])],
    ['Generated PDF is readable', $fromPdf['readable'] === true],
    ['PDF: title has no banner letters', isset($fromPdf['values']['title']) && $fromPdf['values']['title'] === $expectedTitle],
    ['PDF: authors have no banner letters', isset($fromPdf['values']['authors']) && $fromPdf['values']['authors'] === "Dela Cruz, Maria Clara S.\nReyes, Jose Miguel T."],
    ['PDF: abstract matches', isset($fromPdf['values']['abstract']) && $fromPdf['values']['abstract'] === $expectedAbstract],
    ['PDF: adviser and defense date match', isset($fromPdf['values']['adviser']) && $fromPdf['values']['adviser'] === 'Prof. Sample Adviser' && $fromPdf['values']['final_defense_date'] === '2025-03-03'],
    ['PDF: page count is 4', isset($fromPdf['values']['page_count']) && $fromPdf['values']['page_count'] === '4'],
    ['PDF with no text is reported unreadable', $fromBlank['readable'] === false],
    ['Damaged PDF is reported unreadable, not an error', $fromBroken['readable'] === false],
    ['New template: abstract from pages 6 and 7 joined whole', $newTemplate['values']['abstract'] === $expectedLongAbstract],
    ['New template: keywords on page 7 read', $newTemplate['values']['keywords'] === 'reading room, fictional schedule'],
    ['New template: acknowledgement and dedication never leak into the abstract', strpos($newTemplate['values']['abstract'], 'thank') === false && strpos($newTemplate['values']['abstract'], 'Dedication') === false],
    ['New template: title, adviser and defense date still read', $newTemplate['values']['title'] === $expectedTitle && $newTemplate['values']['adviser'] === 'Prof. Sample Adviser' && $newTemplate['values']['final_defense_date'] === '2025-03-03'],
    ['Contents page after an abstract without keywords is not swallowed', $noKeywords['values']['abstract'] === $expectedLongAbstract && !isset($noKeywords['values']['keywords'])],
    ['New template PDF: abstract on pages 6-7 read whole', isset($fromNewTemplatePdf['values']['abstract']) && $fromNewTemplatePdf['values']['abstract'] === $expectedLongAbstract],
    ['New template PDF: keywords on page 7 read', isset($fromNewTemplatePdf['values']['keywords']) && $fromNewTemplatePdf['values']['keywords'] === 'reading room, fictional schedule'],
    ['New template PDF: page count is 8', isset($fromNewTemplatePdf['values']['page_count']) && $fromNewTemplatePdf['values']['page_count'] === '8'],
    ['Abstract after page 10 is not read', $fromLateAbstractPdf['readable'] === true && !isset($fromLateAbstractPdf['values']['abstract'])],
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

// cleanup: remove only the files this script wrote, then its own folder
foreach ([$samplePdf, $blankPdf, $notPdf, $newTemplatePdf, $lateAbstractPdf] as $sample) {
    unlink($sample);
}
rmdir($workFolder);

echo "RESULT: $passed passed; $failed failed.\n";
if ($failed > 0) {
    exit(1);
}
exit(0);
