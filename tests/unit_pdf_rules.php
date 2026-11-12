<?php
// WHAT THIS FILE DOES: checks the PDF upload rules without a browser, web server, or MySQL database.
// STEP 1: Load the real ThesisFiles class with a tiny 1 KB limit, so a "too large" file is easy to make.
// STEP 2: Write small sample files into a fresh temporary folder: a real minimal PDF, a text file named .pdf, an empty file, and others.
// STEP 3: Check that only the real PDF passes, and that each other file gets the matching message.
// STEP 4: Check how uploaded file names are cleaned for display, and how sizes are written.
// STEP 5: Delete the temporary folder and print the total result.
// The PDF checks never touch the database, so an in-memory SQLite connection stands in for MySQL here.
declare(strict_types=1);

use Scholar\Theses\ThesisFiles;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

// a fresh folder inside the system temp directory, removed again at the end
$workFolder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scholar_pdf_rules_' . bin2hex(random_bytes(6));
mkdir($workFolder);

// the smallest PDF structure: a catalog, a page list and one blank page; enough for any PDF reader
$minimalPdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
    . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

// writes one sample file and returns its full path
function sampleFile(string $folder, string $name, string $contents): string {
    $path = $folder . DIRECTORY_SEPARATOR . $name;
    file_put_contents($path, $contents);
    return $path;
}

$files = new ThesisFiles(new PDO('sqlite::memory:'), $workFolder, 1024); // 1 KB limit for this test only

$realPdf = sampleFile($workFolder, 'real.pdf', $minimalPdf);
$textAsPdf = sampleFile($workFolder, 'notes.pdf', 'Just some text pretending to be a PDF.');
$programAsPdf = sampleFile($workFolder, 'program.pdf', "MZ\x90\x00 fake Windows program bytes");
$emptyFile = sampleFile($workFolder, 'empty.pdf', '');
$largePdf = sampleFile($workFolder, 'large.pdf', $minimalPdf . str_repeat('%', 2000)); // still a PDF, but over 1 KB

$cases = [
    ['Real minimal PDF accepted', $files->checkFile($realPdf, 'Final Thesis.pdf') === ''],
    ['Upper-case .PDF extension accepted', $files->checkFile($realPdf, 'FINAL.PDF') === ''],
    ['Real PDF with a .docx name refused', strpos($files->checkFile($realPdf, 'thesis.docx'), 'Only PDF files') !== false],
    ['Real PDF with no extension refused', strpos($files->checkFile($realPdf, 'thesis'), 'Only PDF files') !== false],
    ['Text file named .pdf refused', strpos($files->checkFile($textAsPdf, 'notes.pdf'), 'not a real PDF') !== false],
    ['Program file named .pdf refused', strpos($files->checkFile($programAsPdf, 'program.pdf'), 'not a real PDF') !== false],
    ['Empty file refused', strpos($files->checkFile($emptyFile, 'empty.pdf'), 'empty') !== false],
    ['File over the size limit refused', strpos($files->checkFile($largePdf, 'large.pdf'), 'larger than') !== false],
    ['Missing upload field refused', $files->checkUpload(null) === 'Choose one PDF file to upload.'],
    ['Several files under one name refused', $files->checkUpload(['name' => ['a.pdf'], 'tmp_name' => ['x'], 'size' => [1], 'error' => [0]]) === 'Choose one PDF file to upload.'],
    ['No file chosen explained', strpos($files->checkUpload(['name' => '', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_NO_FILE]), 'Choose a PDF') !== false],
    ['PHP size error explained', strpos($files->checkUpload(['name' => 'a.pdf', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_INI_SIZE]), 'larger than') !== false],
    // a path to an existing file is not a fresh upload, so is_uploaded_file() must refuse it
    ['Existing file passed as an upload refused', strpos($files->checkUpload(['name' => 'real.pdf', 'tmp_name' => $realPdf, 'size' => 10, 'error' => UPLOAD_ERR_OK]), 'did not finish') !== false],
    ['Windows path stripped from the name', ThesisFiles::displayName('C:\\Users\\me\\Thesis Final.pdf') === 'Thesis Final.pdf'],
    ['Folder climbing removed from the name', ThesisFiles::displayName('../../secret/evil.pdf') === 'evil.pdf'],
    ['Quotes and control characters removed', ThesisFiles::displayName("bad\"na\x07me.pdf") === 'badname.pdf'],
    ['Empty name gets a safe default', ThesisFiles::displayName('') === 'thesis.pdf'],
    ['Accented name kept', ThesisFiles::displayName('Tésis.pdf') === 'Tésis.pdf'],
    ['Size in megabytes', ThesisFiles::readableSize(12345678) === '11.8 MB'],
    ['Size in kilobytes', ThesisFiles::readableSize(2048) === '2 KB'],
    ['Tiny file shown in bytes, not "0 KB"', ThesisFiles::readableSize(400) === '400 bytes'],
    ['Stored name in another format refused', $files->storedPath(['storage_name' => '../.env']) === null],
    ['Valid-looking stored name that does not exist refused', $files->storedPath(['storage_name' => str_repeat('a', 32) . '.pdf']) === null],
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
foreach (glob($workFolder . DIRECTORY_SEPARATOR . '*.pdf') as $sample) {
    unlink($sample);
}
rmdir($workFolder);

echo "RESULT: $passed passed; $failed failed.\n";
if ($failed > 0) {
    exit(1);
}
exit(0);
