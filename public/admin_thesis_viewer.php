<?php
declare(strict_types=1); // keep typed values strict

use Scholar\Auth\Access;
use Scholar\Auth\Session;
use Scholar\Theses\ThesisFiles;
use Scholar\Theses\ThesisRecords;

// the secure PDF viewer: shows the manuscript inside the RDPO pages using the browser's built-in PDF viewer
// "secure" here means: only signed-in RDPO accounts can load it, it is never on a public address, the browser
// is told not to cache it, other websites cannot embed it, and every opening is written to the audit history.
// it does NOT stop someone who can already see the PDF from saving, printing or photographing it.
require_once __DIR__ . '/../app/bootstrap.php';
Session::start();
$pdo = connectDatabase();
$account = Access::requirePermission($pdo, 'thesis.view_pdf');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$thesisId = 0;
if (isset($_GET['id']) && is_string($_GET['id']) && preg_match('/\A[1-9][0-9]{0,18}\z/', $_GET['id'])) {
    $thesisId = (int) $_GET['id'];
}
$records = new ThesisRecords($pdo);
$thesis = null;
if ($thesisId > 0) {
    $thesis = $records->findThesis($thesisId);
}
if ($thesis === null) {
    http_response_code(404);
    exit('Record not found.');
}
$files = ThesisFiles::create($pdo);
$currentFile = $files->currentFile($thesisId);

// this page may only be shown inside our own site, like the PDF itself
header('X-Frame-Options: SAMEORIGIN');
$csrfToken = Session::csrfToken();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF - <?= escapeHtml($thesis['title']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
</head>

<body class="bg-light">
<!-- RDPO navigation; the same small bar appears on each RDPO page -->
<nav class="navbar navbar-dark bg-dark">
    <div class="container-fluid flex-wrap gap-2">
        <a class="navbar-brand" href="admin.php">S.C.H.O.L.A.R. RDPO</a>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <a class="btn btn-sm btn-outline-light" href="admin_thesis.php?id=<?= (int) $thesisId ?>">Back to record</a>
            <span class="text-white-50 small">Signed in as <?= escapeHtml($account['username']) ?></span>
            <form method="post" action="logout.php" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                <button type="submit" class="btn btn-sm btn-light">Log out</button>
            </form>
        </div>
    </div>
</nav>

<main class="container-fluid py-3">
    <h1 class="h5 mb-1"><?= escapeHtml($thesis['title']) ?></h1>

    <?php if ($currentFile === null): ?>
        <div class="alert alert-secondary mt-3">No PDF has been uploaded for this record yet. <a href="admin_thesis.php?id=<?= (int) $thesisId ?>">Go back to upload one</a>.</div>
    <?php else: ?>
        <p class="small text-secondary mb-2">
            <?= escapeHtml($currentFile['original_filename']) ?> &middot; <?= escapeHtml(ThesisFiles::readableSize((int) $currentFile['size_bytes'])) ?>
            &middot; Restricted to RDPO. Opening this file is recorded in the record's history.
        </p>
        <!-- the frame loads admin_thesis_pdf.php, which checks the login again before sending any bytes;
             the browser's own PDF viewer draws the pages, so no extra viewer library is needed -->
        <iframe src="admin_thesis_pdf.php?id=<?= (int) $thesisId ?>" title="Manuscript PDF for <?= escapeHtml($thesis['title']) ?>"
                class="w-100 border rounded bg-white" style="height: 80vh;"></iframe>
        <p class="small text-secondary mt-2">If the PDF does not appear, your browser may not show PDFs inside pages;
            <a href="admin_thesis_pdf.php?id=<?= (int) $thesisId ?>">open it directly</a> instead (still restricted to RDPO).</p>
    <?php endif; ?>
</main>
</body>

</html>
