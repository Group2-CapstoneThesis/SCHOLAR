<?php
declare(strict_types=1); // keep typed values strict

use Scholar\AuditLog;
use Scholar\Auth\Access;
use Scholar\Auth\Session;
use Scholar\Theses\ThesisFiles;

// sends the current manuscript PDF of one thesis to a signed-in RDPO account, and only to them
// this is the ONLY way to get a stored PDF: the files sit in storage/theses, which Apache never serves directly
// the secure viewer page shows this address inside a frame; opening it on its own also works for RDPO
require_once __DIR__ . '/../app/bootstrap.php';
Session::start();
$pdo = connectDatabase();
$account = Access::requirePermission($pdo, 'thesis.view_pdf'); // visitors go to login, other roles get 403

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$thesisId = 0;
if (isset($_GET['id']) && is_string($_GET['id']) && preg_match('/\A[1-9][0-9]{0,18}\z/', $_GET['id'])) {
    $thesisId = (int) $_GET['id'];
}
$files = ThesisFiles::create($pdo);
$file = null;
$path = null;
if ($thesisId > 0) {
    $file = $files->currentFile($thesisId);
}
if ($file !== null) {
    $path = $files->storedPath($file);
}
if ($path === null) {
    // no record, no PDF yet, or the stored file is missing: all answer the same way
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('PDF not found.');
}

// a size different from the one recorded at upload means the stored file was damaged or swapped
if (filesize($path) !== (int) $file['size_bytes']) {
    throw new RuntimeException('Stored thesis PDF size does not match its record.'); // handled privately by bootstrap
}

// record who opened which manuscript; only the thesis id and the account, never any PDF text
AuditLog::record($pdo, $account['id'], 'thesis.pdf_viewed', 'thesis', $thesisId);

// let other pages of this session load while a large PDF is still downloading;
// PHP otherwise keeps the session file locked until this script ends
session_write_close();

// the suggested name for "Save as", in two forms: plain ASCII for old browsers, and the full name in UTF-8
// example: "Tésis Final.pdf" becomes filename="T_sis Final.pdf"; filename*=UTF-8''T%C3%A9sis%20Final.pdf
$asciiName = preg_replace('/[^A-Za-z0-9 ._-]/', '_', $file['original_filename']);
$encodedName = rawurlencode($file['original_filename']);

header('Content-Type: application/pdf');
header('Content-Length: ' . $file['size_bytes']);
// inline = show it in the browser's PDF viewer instead of downloading it straight away
header('Content-Disposition: inline; filename="' . $asciiName . "\"; filename*=UTF-8''" . $encodedName);
header('X-Content-Type-Options: nosniff'); // the browser must treat it as a PDF, never guess another type
// only our own pages may show this PDF inside a frame; other websites cannot embed it
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
header('Cache-Control: no-store, private'); // do not keep a copy in the browser cache or any shared cache
header('Referrer-Policy: no-referrer');

readfile($path); // send the file's bytes to the browser
exit;
