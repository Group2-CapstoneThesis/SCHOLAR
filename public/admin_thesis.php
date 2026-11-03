<?php
declare(strict_types=1); // keep typed values strict

use Scholar\AuditLog;
use Scholar\Auth\Access;
use Scholar\Auth\Session;
use Scholar\Theses\ThesisFiles;
use Scholar\Theses\ThesisRecords;

// RDPO's page for one record: all details, the Publish/Archive buttons, and the record's history
require_once __DIR__ . '/../app/bootstrap.php';
Session::start();
$pdo = connectDatabase();
$account = Access::requirePermission($pdo, 'thesis.view_all');

$requestMethod = $_SERVER['REQUEST_METHOD'];
if ($requestMethod !== 'GET' && $requestMethod !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

// digits only; anything else is treated as a record that does not exist
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

// each button sends one of these action names; the map says which permission it needs and what it does
// an action name that is not in this list is refused, so a modified form cannot invent new actions
$actions = [
    'publish' => ['permission' => 'thesis.publish'],
    'unpublish' => ['permission' => 'thesis.publish'],
    'archive' => ['permission' => 'thesis.archive'],
    'restore' => ['permission' => 'thesis.archive'],
];
// what to tell RDPO after each possible result
$resultMessages = [
    'published' => 'The record is now visible on the public search page.',
    'unpublished' => 'The record is now hidden from the public search page.',
    'archived' => 'The record was archived. It is hidden everywhere except the "Archived" filter.',
    'restored' => 'The record was restored.',
    'unchanged' => 'Nothing changed; the record was already in that state.',
];

if ($requestMethod === 'POST') {
    $submittedToken = null;
    if (isset($_POST['csrf_token'])) {
        $submittedToken = $_POST['csrf_token'];
    }
    if (!Session::verifyCsrf($submittedToken)) {
        http_response_code(403); // this is for preventing CSRF attacks
        exit('The form expired. Return to the record page and try again.');
    }

    $action = '';
    if (isset($_POST['action']) && is_string($_POST['action'])) {
        $action = $_POST['action'];
    }
    if (!isset($actions[$action])) {
        http_response_code(422);
        exit('Unknown action.');
    }
    // the page itself needs view permission; each action is checked again against its own permission
    if (!Access::roleAllows($account['role_code'], $actions[$action]['permission'])) {
        http_response_code(403);
        exit('You do not have permission to do that.');
    }

    if ($action === 'publish') {
        $outcome = $records->setVisibility($thesisId, true, $account['id']);
    } elseif ($action === 'unpublish') {
        $outcome = $records->setVisibility($thesisId, false, $account['id']);
    } elseif ($action === 'archive') {
        $outcome = $records->setArchived($thesisId, true, $account['id']);
    } else {
        $outcome = $records->setArchived($thesisId, false, $account['id']);
    }

    if ($outcome === 'not_found') {
        http_response_code(404);
        exit('Record not found.');
    }
    if ($outcome === 'archived' && ($action === 'publish' || $action === 'unpublish')) {
        // setVisibility() refuses archived records; explain instead of pretending it worked
        Session::setFlash('Restore this record before changing whether it is public.', true);
    } else {
        Session::setFlash($resultMessages[$outcome]);
    }
    // POST/redirect/GET: refreshing the page afterwards will not press the button again
    header('Location: admin_thesis.php?id=' . $thesisId, true, 303);
    exit;
}

$history = AuditLog::historyFor($pdo, 'thesis', $thesisId, 20); // newest 20 events for this record
// readable names for the history list; the database keeps the fixed machine codes
$actionLabels = [
    'thesis.created' => 'Created',
    'thesis.updated' => 'Edited',
    'thesis.published' => 'Published',
    'thesis.unpublished' => 'Unpublished',
    'thesis.archived' => 'Archived',
    'thesis.restored' => 'Restored',
    'thesis.pdf_uploaded' => 'PDF uploaded',
    'thesis.pdf_viewed' => 'PDF opened',
];
// attach the readable label to each event now, so the HTML below only has to print it
foreach ($history as $index => $event) {
    $history[$index]['label'] = $event['action_code']; // fall back to the code itself if a label is missing
    if (isset($actionLabels[$event['action_code']])) {
        $history[$index]['label'] = $actionLabels[$event['action_code']];
    }
}
$isArchived = $thesis['archived_at'] !== null;
$isPublic = (int) $thesis['is_public'] === 1;

// small helper for optional fields: show a dash instead of an empty space
function displayValue($value): string {
    if ($value === null || $value === '') {
        return '-';
    }
    return (string) $value;
}

// the current manuscript PDF (null when none has been uploaded yet), shown in the "Manuscript PDF" card
$files = ThesisFiles::create($pdo);
$currentFile = $files->currentFile($thesisId);
$canUploadPdf = Access::roleAllows($account['role_code'], 'thesis.upload_pdf');
$canViewPdf = Access::roleAllows($account['role_code'], 'thesis.view_pdf');

$flashMessage = Session::takeFlash();
$flashIsError = Session::takeFlashIsError(); // red for problems such as a rejected upload, green otherwise
$csrfToken = Session::csrfToken();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escapeHtml($thesis['title']) ?> - RDPO record</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
</head>

<body class="bg-light">
<!-- RDPO navigation; the same small bar appears on each RDPO page -->
<nav class="navbar navbar-dark bg-dark">
    <div class="container flex-wrap gap-2">
        <a class="navbar-brand" href="admin.php">S.C.H.O.L.A.R. RDPO</a>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <a class="btn btn-sm btn-outline-light" href="admin.php">Records</a>
            <a class="btn btn-sm btn-outline-light" href="admin_thesis_form.php">New record</a>
            <span class="text-white-50 small">Signed in as <?= escapeHtml($account['username']) ?></span>
            <form method="post" action="logout.php" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                <button type="submit" class="btn btn-sm btn-light">Log out</button>
            </form>
        </div>
    </div>
</nav>

<main class="container py-4">
    <p><a href="admin.php">&larr; Back to records</a></p>

    <?php if ($flashMessage !== '' && $flashIsError): ?>
        <div class="alert alert-danger" role="alert"><?= escapeHtml($flashMessage) ?></div>
    <?php elseif ($flashMessage !== ''): ?>
        <div class="alert alert-success" role="status"><?= escapeHtml($flashMessage) ?></div>
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1"><?= escapeHtml($thesis['title']) ?></h1>
            <!-- current status as text, plus what it means for the public page -->
            <?php if ($isArchived): ?>
                <span class="badge text-bg-secondary">Archived</span> <span class="small text-secondary">Hidden from the public page.</span>
            <?php elseif ($isPublic): ?>
                <span class="badge text-bg-success">Published</span> <span class="small text-secondary">Visible on the public search page.</span>
            <?php else: ?>
                <span class="badge text-bg-warning">Unpublished</span> <span class="small text-secondary">Only RDPO can see this record.</span>
            <?php endif; ?>
        </div>

        <!-- every button is its own small POST form with the CSRF token and a fixed action name -->
        <div class="d-flex flex-wrap gap-2">
            <a href="admin_thesis_form.php?id=<?= (int) $thesisId ?>" class="btn btn-primary">Edit</a>
            <?php if (!$isArchived): ?>
                <?php if ($isPublic): ?>
                    <form method="post" action="admin_thesis.php?id=<?= (int) $thesisId ?>" class="m-0">
                        <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                        <input type="hidden" name="action" value="unpublish">
                        <button type="submit" class="btn btn-outline-warning">Unpublish</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="admin_thesis.php?id=<?= (int) $thesisId ?>" class="m-0">
                        <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                        <input type="hidden" name="action" value="publish">
                        <button type="submit" class="btn btn-outline-success">Publish</button>
                    </form>
                <?php endif; ?>
                <!-- onsubmit asks the browser for a quick confirmation; the server does not rely on it -->
                <form method="post" action="admin_thesis.php?id=<?= (int) $thesisId ?>" class="m-0"
                      onsubmit="return confirm('Archive this record? It will be hidden everywhere except the Archived filter. You can restore it later.');">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                    <input type="hidden" name="action" value="archive">
                    <button type="submit" class="btn btn-outline-secondary">Archive</button>
                </form>
            <?php else: ?>
                <form method="post" action="admin_thesis.php?id=<?= (int) $thesisId ?>" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                    <input type="hidden" name="action" value="restore">
                    <button type="submit" class="btn btn-outline-primary">Restore</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-lg-8">
            <div class="card card-body">
                <!-- dl = description list: each <dt> is a label and the <dd> after it is its value -->
                <dl class="row mb-0">
                    <dt class="col-sm-4">Authors</dt>
                    <dd class="col-sm-8">
                        <?php foreach ($thesis['authors'] as $author): ?>
                            <div><?= escapeHtml($author) ?></div>
                        <?php endforeach; ?>
                    </dd>
                    <dt class="col-sm-4">Year</dt>
                    <dd class="col-sm-8"><?= escapeHtml(displayValue($thesis['publication_year'])) ?></dd>
                    <dt class="col-sm-4">Program or course</dt>
                    <dd class="col-sm-8"><?= escapeHtml(displayValue($thesis['program'])) ?></dd>
                    <dt class="col-sm-4">Thesis adviser</dt>
                    <dd class="col-sm-8"><?= escapeHtml(displayValue($thesis['adviser'])) ?></dd>
                    <dt class="col-sm-4">Date of final defense</dt>
                    <dd class="col-sm-8"><?= escapeHtml(displayValue($thesis['final_defense_date'])) ?></dd>
                    <dt class="col-sm-4">Date submitted</dt>
                    <dd class="col-sm-8"><?= escapeHtml(displayValue($thesis['date_submitted'])) ?></dd>
                    <dt class="col-sm-4">Number of pages</dt>
                    <dd class="col-sm-8"><?= escapeHtml(displayValue($thesis['page_count'])) ?></dd>
                    <dt class="col-sm-4">Keywords</dt>
                    <dd class="col-sm-8"><?= escapeHtml(displayValue($thesis['keywords'])) ?></dd>
                    <dt class="col-sm-4">Abstract</dt>
                    <?php if ($thesis['abstract'] !== null && $thesis['abstract'] !== ''): ?>
                        <!-- nl2br keeps the abstract's line breaks; escaping happens first so typed HTML stays plain text -->
                        <dd class="col-sm-8"><?= nl2br(escapeHtml($thesis['abstract'])) ?></dd>
                    <?php else: ?>
                        <!-- the abstract is optional -->
                        <dd class="col-sm-8 text-secondary fst-italic">No abstract recorded.</dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card card-body mb-3 small">
                <div><strong>Created:</strong> <?= escapeHtml(displayValue($thesis['created_at'])) ?> by <?= escapeHtml(displayValue($thesis['created_by_username'])) ?></div>
                <div><strong>Last changed:</strong> <?= escapeHtml(displayValue($thesis['updated_at'])) ?> by <?= escapeHtml(displayValue($thesis['updated_by_username'])) ?></div>
                <?php if ($isArchived): ?>
                    <div><strong>Archived:</strong> <?= escapeHtml($thesis['archived_at']) ?></div>
                <?php endif; ?>
                <?php if ($isPublic && !$isArchived): ?>
                    <div class="mt-2"><a href="thesis.php?id=<?= (int) $thesisId ?>">View the public page</a></div>
                <?php endif; ?>
            </div>

            <!-- the manuscript PDF: stored privately, opened only through the RDPO viewer, never linked from public pages -->
            <div class="card card-body mb-3 small">
                <h2 class="h6">Manuscript PDF</h2>
                <?php if ($currentFile === null): ?>
                    <p class="text-secondary">No PDF uploaded yet.</p>
                <?php else: ?>
                    <div class="mb-1"><strong><?= escapeHtml($currentFile['original_filename']) ?></strong></div>
                    <div class="text-secondary mb-2">
                        <?= escapeHtml(ThesisFiles::readableSize((int) $currentFile['size_bytes'])) ?>,
                        uploaded <?= escapeHtml($currentFile['created_at']) ?> by <?= escapeHtml($currentFile['uploaded_by_username']) ?>
                    </div>
                    <?php if ($canViewPdf): ?>
                        <a href="admin_thesis_viewer.php?id=<?= (int) $thesisId ?>" class="btn btn-sm btn-primary mb-3">Open in secure viewer</a>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($canUploadPdf): ?>
                    <!-- enctype="multipart/form-data" is required for a form that sends a file -->
                    <form method="post" action="admin_thesis_upload.php?id=<?= (int) $thesisId ?>" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                        <label for="thesis_pdf" class="form-label">
                            <?php if ($currentFile === null): ?>Upload the final PDF<?php else: ?>Replace with a newer PDF<?php endif; ?>
                            <span class="text-secondary">(PDF only, up to <?= $files->maxMegabytes() ?> MB)</span>
                        </label>
                        <!-- accept= only filters the file picker; the server checks the real file contents -->
                        <input id="thesis_pdf" name="thesis_pdf" type="file" accept="application/pdf,.pdf" required class="form-control form-control-sm mb-2">
                        <button type="submit" class="btn btn-sm btn-outline-primary">Upload</button>
                        <?php if ($currentFile !== null): ?>
                            <div class="form-text">The current PDF is kept on file when replaced, not deleted.</div>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>

            <!-- the audit history for this record, newest first; only field names are logged, never their contents -->
            <div class="card card-body small">
                <h2 class="h6">History</h2>
                <?php if (count($history) === 0): ?>
                    <p class="mb-0 text-secondary">No recorded changes yet.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($history as $event): ?>
                            <li class="mb-2">
                                <strong><?= escapeHtml($event['label']) ?></strong>
                                <?php if ($event['username'] === null): ?>
                                    from the terminal
                                <?php else: ?>
                                    by <?= escapeHtml($event['username']) ?>
                                <?php endif; ?>
                                <div class="text-secondary"><?= escapeHtml($event['created_at']) ?></div>
                                <?php if ($event['changed_fields'] !== null): ?>
                                    <div class="text-secondary">Changed: <?= escapeHtml($event['changed_fields']) ?></div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
</body>

</html>
