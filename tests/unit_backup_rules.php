<?php
// WHAT THIS FILE DOES: checks the backup file rules (M12) without a browser, session, or database.
// STEP 1: Load the real Backup class and make an empty scratch folder in storage/test-results.
// STEP 2: Check which file names count as backups (only names the application makes).
// STEP 3: Build small made-up backup files with PHP's PharData: one correct, and several damaged or changed ones.
// STEP 4: Check that Backup::check() accepts the correct one and finds the problem in each of the others:
//         changed database, changed PDF, missing PDF, extra PDF, unexpected or "../" entries, missing manifest, not a .tar.
// STEP 5: Remove the scratch folder and print the total result.
// The made-up PDFs are a few bytes of fake text; no real thesis is used.
declare(strict_types=1);

use Scholar\Backup;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

$scratch = __DIR__ . '/../storage/test-results/unit_backup_' . bin2hex(random_bytes(6));
mkdir($scratch, 0700, true);

// builds one made-up backup file and returns its path
// $entries: path inside the archive => content; $manifestChanges: values that replace the correct manifest's
function buildBackup(string $folder, string $name, array $entries, array $manifestChanges = [], bool $withManifest = true): string {
    $path = $folder . DIRECTORY_SEPARATOR . $name;
    $archive = new PharData($path);
    $sql = "-- made-up backup\nSET NAMES utf8mb4;\n";
    $files = [];
    foreach ($entries as $inside => $content) {
        if ($inside === 'database.sql.gz') {
            $archive->addFromString($inside, gzencode($content));
            continue;
        }
        $archive->addFromString($inside, $content);
        if (str_starts_with($inside, 'files/theses/')) {
            $files[substr($inside, 13)] = hash('sha256', $content);
        }
    }
    if (!isset($entries['database.sql.gz'])) {
        $archive->addFromString('database.sql.gz', gzencode($sql));
    }
    $manifest = array_merge([
        'format' => 1, 'application' => 'S.C.H.O.L.A.R.', 'created_at' => '2026-11-09 10:00:00', 'database' => 'made_up',
        'tables' => ['theses' => 0], 'database_sha256' => hash('sha256', $sql), 'files' => $files,
    ], $manifestChanges);
    if ($withManifest) {
        $archive->addFromString('manifest.json', json_encode($manifest));
    }
    unset($archive);
    return $path;
}

// true when check() reports at least one problem containing $words
function hasProblem(array $checked, string $words): bool {
    foreach ($checked['problems'] as $problem) {
        if (strpos($problem, $words) !== false) {
            return true;
        }
    }
    return false;
}

$pdfA = str_repeat('a', 32) . '.pdf';
$pdfB = str_repeat('b', 32) . '.pdf';
try {
    $good = Backup::check(buildBackup($scratch, 'good.tar', ['files/theses/' . $pdfA => '%PDF-1.4 made-up A']));
    $changedSql = Backup::check(buildBackup($scratch, 'changed_sql.tar', ['database.sql.gz' => "DROP TABLE users;\n"]));
    $changedPdf = Backup::check(buildBackup($scratch, 'changed_pdf.tar', ['files/theses/' . $pdfA => '%PDF-1.4 made-up A'],
        ['files' => [$pdfA => hash('sha256', 'something else')]]));
    $missingPdf = Backup::check(buildBackup($scratch, 'missing_pdf.tar', [], ['files' => [$pdfA => hash('sha256', 'x')]]));
    $extraPdf = Backup::check(buildBackup($scratch, 'extra_pdf.tar', ['files/theses/' . $pdfB => '%PDF-1.4 B'], ['files' => []]));
    $strangeEntry = Backup::check(buildBackup($scratch, 'strange.tar', ['notes/run_me.php' => '<?php echo 1;']));
    $badPdfName = Backup::check(buildBackup($scratch, 'bad_name.tar', ['files/theses/evil.php' => '<?php echo 1;']));
    $noManifest = Backup::check(buildBackup($scratch, 'no_manifest.tar', [], [], false));
    $oldFormat = Backup::check(buildBackup($scratch, 'old_format.tar', [], ['format' => 99]));
    file_put_contents($scratch . DIRECTORY_SEPARATOR . 'not_a_backup.txt', 'hello');
    $notTar = Backup::check($scratch . DIRECTORY_SEPARATOR . 'not_a_backup.txt');
    $missingFile = Backup::check($scratch . DIRECTORY_SEPARATOR . 'nothing_here.tar');

    $cases = [
        // ---- file names ----
        ['A name the application makes is a backup', preg_match(Backup::FILE_PATTERN, 'scholar_backup_2026-11-09_103000_a1b2c3.tar') === 1],
        ['A half-made "partial_" file is not listed', preg_match(Backup::FILE_PATTERN, 'partial_a1b2c3d4e5f6.tar') === 0],
        ['A path with "../" is not a backup name', preg_match(Backup::FILE_PATTERN, '../scholar_backup_2026-11-09_103000_a1b2c3.tar') === 0],
        ['Another file type is not a backup name', preg_match(Backup::FILE_PATTERN, 'scholar_backup_2026-11-09_103000_a1b2c3.tar.php') === 0],
        ['The .env file is not a backup name', preg_match(Backup::FILE_PATTERN, '.env') === 0],

        // ---- checking backups ----
        ['A correct backup passes', $good['problems'] === [] && $good['manifest']['files'] === [$pdfA => hash('sha256', '%PDF-1.4 made-up A')]],
        ['A correct backup gives back its SQL', strpos($good['sql'], 'SET NAMES utf8mb4;') !== false],
        ['A changed database part is caught', hasProblem($changedSql, 'database part does not match')],
        ['A changed database part gives no SQL to restore', $changedSql['sql'] === ''],
        ['A changed PDF is caught', hasProblem($changedPdf, 'does not match its fingerprint: ' . $pdfA)],
        ['A missing PDF is caught', hasProblem($missingPdf, 'is missing: ' . $pdfA)],
        ['A PDF not in the manifest is caught', hasProblem($extraPdf, 'not listed in its manifest: ' . $pdfB)],
        ['An unexpected item is caught', hasProblem($strangeEntry, 'Unexpected item in the backup: notes/run_me.php')],
        ['A file that is not a stored-PDF name is caught', hasProblem($badPdfName, 'Unexpected item in the backup: files/theses/evil.php')],
        ['A missing manifest is caught', hasProblem($noManifest, 'missing its manifest')],
        ['An unknown backup format is refused', hasProblem($oldFormat, 'manifest is not readable')],
        ['A text file is refused', hasProblem($notTar, 'not a .tar backup')],
        ['A missing file is refused', hasProblem($missingFile, 'not a .tar backup')],
    ];
} finally {
    // remove the scratch folder and everything in it
    foreach (glob($scratch . DIRECTORY_SEPARATOR . '*') as $file) {
        unlink($file);
    }
    rmdir($scratch);
}

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
