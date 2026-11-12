<?php
declare(strict_types=1); // keep typed values strict

use Scholar\Auth\Access;
use Scholar\Auth\Session;
use Scholar\Theses\ThesisFiles;
use Scholar\Theses\ThesisRecords;

// receives the "Upload PDF" form from the RDPO record page, saves the file privately, then goes back to the record
// this page shows no HTML of its own; every outcome becomes a one-time message on the record page
require_once __DIR__ . '/../app/bootstrap.php';
Session::start();
$pdo = connectDatabase();
$account = Access::requirePermission($pdo, 'thesis.upload_pdf');

// uploads are only ever sent by the form's POST; opening this address directly does nothing
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

// digits only; anything else is treated as a record that does not exist
$thesisId = 0;
if (isset($_GET['id']) && is_string($_GET['id']) && preg_match('/\A[1-9][0-9]{0,18}\z/', $_GET['id'])) {
    $thesisId = (int) $_GET['id'];
}
$records = new ThesisRecords($pdo);
if ($thesisId === 0 || $records->findThesis($thesisId) === null) {
    http_response_code(404);
    exit('Record not found.');
}
$recordPage = 'admin_thesis.php?id=' . $thesisId;
$files = ThesisFiles::create($pdo);

// when a request is bigger than PHP's post_max_size, PHP throws the whole form away: $_POST and $_FILES arrive empty
// without this check, that would look like a missing security token, which would be a confusing message
$contentLength = 0;
if (isset($_SERVER['CONTENT_LENGTH']) && is_string($_SERVER['CONTENT_LENGTH']) && ctype_digit($_SERVER['CONTENT_LENGTH'])) {
    $contentLength = (int) $_SERVER['CONTENT_LENGTH'];
}
if (count($_POST) === 0 && count($_FILES) === 0 && $contentLength > 0) {
    Session::setFlash('The file is too large to upload. The limit is ' . $files->maxMegabytes() . ' MB.', true);
    header('Location: ' . $recordPage, true, 303);
    exit;
}

$submittedToken = null;
if (isset($_POST['csrf_token'])) {
    $submittedToken = $_POST['csrf_token'];
}
if (!Session::verifyCsrf($submittedToken)) {
    http_response_code(403); // this is for preventing CSRF attacks
    exit('The form expired. Return to the record page and try again.');
}

$upload = null;
if (isset($_FILES['thesis_pdf'])) {
    $upload = $_FILES['thesis_pdf'];
}
$problem = $files->checkUpload($upload);
if ($problem !== '') {
    Session::setFlash('PDF not uploaded: ' . $problem, true);
    header('Location: ' . $recordPage, true, 303);
    exit;
}

$files->storeUpload($thesisId, $upload, $account['id']);
Session::setFlash('PDF uploaded.');
// POST/redirect/GET: refreshing the record page afterwards will not upload the file again
header('Location: ' . $recordPage, true, 303);
exit;
