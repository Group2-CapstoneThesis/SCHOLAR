<?php
declare(strict_types=1); // keep typed values strict

use Scholar\Auth\Access;
use Scholar\Auth\Session;
use Scholar\Theses\ThesisFiles;
use Scholar\Theses\ThesisMetadataExtractor;
use Scholar\Theses\ThesisRecords;
use Scholar\Theses\ThesisSearch;
use Scholar\Theses\ThesisValidator;

// this one page creates a new record (admin_thesis_form.php) or edits one (admin_thesis_form.php?id=5)
require_once __DIR__ . '/../app/bootstrap.php';
Session::start();
$pdo = connectDatabase();

$requestMethod = $_SERVER['REQUEST_METHOD'];
if ($requestMethod !== 'GET' && $requestMethod !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405); // reject methods this page does not support
    exit;
}

// an id in the address means edit mode; it must be digits only, so "5abc" or "-1" are refused
$thesisId = null;
if (isset($_GET['id'])) {
    if (!is_string($_GET['id']) || !preg_match('/\A[1-9][0-9]{0,18}\z/', $_GET['id'])) {
        http_response_code(404);
        exit('Record not found.');
    }
    $thesisId = (int) $_GET['id'];
}
$isEditing = $thesisId !== null;

// creating and editing are separate permissions, checked before anything else happens
$permission = 'thesis.create';
if ($isEditing) {
    $permission = 'thesis.edit';
}
$account = Access::requirePermission($pdo, $permission);

$records = new ThesisRecords($pdo);
$validator = new ThesisValidator();
$thesis = null;
if ($isEditing) {
    $thesis = $records->findThesis($thesisId);
    if ($thesis === null) {
        http_response_code(404);
        exit('Record not found.');
    }
}

// what the form shows: the saved record when editing, or empty fields for a new record
$formValues = [
    'title' => '', 'authors' => '', 'publication_year' => '', 'program' => '', 'adviser' => '',
    'abstract' => '', 'keywords' => '', 'date_submitted' => '', 'final_defense_date' => '', 'page_count' => '',
    'is_public' => false,
];
if ($isEditing) {
    $formValues = ThesisRecords::formValuesFromRecord($thesis);
}
$errors = [];
$errorMessage = '';

// ---- autofill from PDF (new records only) ----
// $pendingPdf describes a PDF that was read for autofill and waits to be attached when the record is saved
$autofillMessage = '';
$autofillIsError = false;
$autofilledFields = []; // names of the fields filled from the PDF, tinted in the form so RDPO checks them
$pendingPdf = null;
$files = null;
$canUploadPdf = Access::roleAllows($account['role_code'], 'thesis.upload_pdf');
if (!$isEditing) {
    $files = ThesisFiles::create($pdo);
    $pendingPdf = pendingPdfFromSession($files);
}

// reads the waiting PDF's details from the session; forgets them if the file has since been cleaned up
// returns ['token' => ..., 'name' => ..., 'size' => ...] or null
function pendingPdfFromSession(ThesisFiles $files): ?array {
    if (!isset($_SESSION['pending_pdf']) || !is_array($_SESSION['pending_pdf'])) {
        return null;
    }
    $pending = $_SESSION['pending_pdf'];
    if (!isset($pending['token'], $pending['name'], $pending['size']) || $files->pendingPath($pending['token']) === null) {
        unset($_SESSION['pending_pdf']);
        return null;
    }
    return $pending;
}

// which button sent the form: "Read details" on the PDF form, or "Save record" on the main form
$requestedAction = 'save';
if (isset($_POST['form_action']) && $_POST['form_action'] === 'read_pdf') {
    $requestedAction = 'read_pdf';
}

// a file bigger than PHP's post_max_size makes PHP drop the whole form, so explain that instead of "form expired"
$contentLength = 0;
if (isset($_SERVER['CONTENT_LENGTH']) && is_string($_SERVER['CONTENT_LENGTH']) && ctype_digit($_SERVER['CONTENT_LENGTH'])) {
    $contentLength = (int) $_SERVER['CONTENT_LENGTH'];
}
// (only the New record form sends files, so this check only applies there)
$formWasDropped = !$isEditing && $requestMethod === 'POST' && count($_POST) === 0 && count($_FILES) === 0 && $contentLength > 0;

if ($formWasDropped) {
    http_response_code(413); // "content too large"
    $autofillIsError = true;
    $autofillMessage = 'The PDF is too large to upload. The limit is ' . $files->maxMegabytes() . ' MB.';
} elseif ($requestMethod === 'POST') {
    $submittedToken = null;
    if (isset($_POST['csrf_token'])) {
        $submittedToken = $_POST['csrf_token'];
    }

    if (!Session::verifyCsrf($submittedToken)) {
        http_response_code(403); // this is for preventing CSRF attacks
        $errorMessage = 'The form expired. Please review the details and save again.';
        $formValues = $validator->formValuesFrom($_POST); // keep what was typed so nothing is lost
    } elseif ($requestedAction === 'read_pdf') {
        // autofill only exists on the New record form, and only for accounts that may upload PDFs
        if ($isEditing || !$canUploadPdf) {
            http_response_code(403);
            exit('You do not have permission to do that.');
        }
        $upload = null;
        if (isset($_FILES['thesis_pdf'])) {
            $upload = $_FILES['thesis_pdf'];
        }
        $problem = $files->checkUpload($upload);
        if ($problem !== '') {
            http_response_code(422);
            $autofillIsError = true;
            $autofillMessage = 'PDF not read: ' . $problem;
        } else {
            // a newly chosen PDF replaces any PDF that was waiting from an earlier "Read details"
            if ($pendingPdf !== null) {
                $files->discardPendingFile($pendingPdf['token']);
            }
            $token = $files->holdPendingUpload($upload);
            $pendingPdf = [
                'token' => $token,
                'name' => ThesisFiles::displayName($upload['name']),
                'size' => (int) filesize($files->pendingPath($token)),
            ];
            $_SESSION['pending_pdf'] = $pendingPdf;

            // read the first pages on this server; nothing is sent anywhere or saved except what RDPO keeps in the form
            $extractor = new ThesisMetadataExtractor();
            $extraction = $extractor->extractFromFile($files->pendingPath($token));
            if (!$extraction['readable']) {
                $autofillIsError = true;
                $autofillMessage = 'No text could be read from this PDF (it may be a scanned image or a locked file). '
                    . 'Please type the details below; the PDF will still be attached when you save.';
            } else {
                // copy only the known form fields, so unexpected keys can never reach the form
                foreach ($extraction['values'] as $field => $value) {
                    if (array_key_exists($field, $formValues) && $field !== 'is_public') {
                        $formValues[$field] = $value;
                    }
                }
                $autofilledFields = $extraction['found'];
                $autofillMessage = 'Filled ' . count($autofilledFields) . ' fields from "' . $pendingPdf['name'] . '". '
                    . 'Tinted fields came from the PDF: check each one before saving. Empty fields were not found.';
            }
        }
    } else {
        // visibility is only part of the create form; on edit it has its own Publish/Unpublish button
        $result = $validator->validate($_POST, !$isEditing);
        $errors = $result['errors'];

        if (count($errors) > 0) {
            http_response_code(422); // the form was understood but some fields need fixing
            $errorMessage = 'Please correct the highlighted fields.';
            $formValues = $validator->formValuesFrom($_POST);
        } elseif ($isEditing) {
            $outcome = $records->updateThesis($thesisId, $result['values'], $account['id']);
            if ($outcome === 'not_found') {
                http_response_code(404);
                exit('Record not found.');
            }
            if ($outcome === 'no_changes') {
                Session::setFlash('No changes were made.');
            } else {
                Session::setFlash('Record saved.');
            }
            // POST/redirect/GET: refreshing the next page will not submit this form again
            header('Location: admin_thesis.php?id=' . $thesisId, true, 303);
            exit;
        } else {
            $newThesisId = $records->createThesis($result['values'], $account['id']);
            Session::setFlash('Record created.');

            // attach the PDF that was read for autofill, if RDPO left "Attach this PDF" ticked;
            // the hidden token must match the session, so an old tab cannot attach a different waiting PDF
            $postedToken = '';
            if (isset($_POST['pending_token']) && is_string($_POST['pending_token'])) {
                $postedToken = $_POST['pending_token'];
            }
            $wantsAttachment = isset($_POST['attach_pdf']) && $_POST['attach_pdf'] === '1';
            if ($pendingPdf !== null && hash_equals($pendingPdf['token'], $postedToken)) {
                if ($wantsAttachment && $canUploadPdf) {
                    $fileId = $files->attachPendingFile($newThesisId, $pendingPdf['token'], $pendingPdf['name'], $account['id']);
                    if ($fileId > 0) {
                        Session::setFlash('Record created and PDF attached.');
                    } else {
                        Session::setFlash('Record created, but the PDF was no longer waiting. Upload it again below.', true);
                    }
                } else {
                    $files->discardPendingFile($pendingPdf['token']); // RDPO chose not to attach it
                }
                unset($_SESSION['pending_pdf']);
            }

            header('Location: admin_thesis.php?id=' . $newThesisId, true, 303);
            exit;
        }
    }
}

// existing program names are offered as suggestions, but any program can still be typed
$search = new ThesisSearch($pdo);
$programSuggestions = $search->filterOptions(true)['programs'];

$pageTitle = 'New thesis record';
$formAction = 'admin_thesis_form.php';
if ($isEditing) {
    $pageTitle = 'Edit thesis record';
    $formAction = 'admin_thesis_form.php?id=' . $thesisId;
}
$csrfToken = Session::csrfToken();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escapeHtml($pageTitle) ?> - S.C.H.O.L.A.R.</title>
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
            <a class="btn btn-sm btn-outline-light" href="index.php">Public search</a>
            <span class="text-white-50 small">Signed in as <?= escapeHtml($account['username']) ?></span>
            <form method="post" action="logout.php" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                <button type="submit" class="btn btn-sm btn-light">Log out</button>
            </form>
        </div>
    </div>
</nav>

<main class="container py-4">
    <div class="row justify-content-center">
        <div class="col-12 col-lg-9">
            <h1 class="h3 mb-3"><?= escapeHtml($pageTitle) ?></h1>
            <p class="text-secondary small">Fields marked * are required.</p>

            <!-- step 1 (optional): read the details from the thesis PDF; this is its own small form, so pressing
                 Enter in the main form below always saves instead of re-reading the PDF -->
            <?php if (!$isEditing && $canUploadPdf): ?>
                <form method="post" action="admin_thesis_form.php" enctype="multipart/form-data" class="card card-body mb-3">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                    <input type="hidden" name="form_action" value="read_pdf">
                    <label for="thesis_pdf" class="form-label fw-semibold">Fill in from the thesis PDF</label>
                    <p class="small text-secondary mb-2">
                        Reads the title page, approval sheet and abstract on this server, then fills the fields below for you to check.
                        Works with text PDFs, not scanned images (up to <?= $files->maxMegabytes() ?> MB).
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <input id="thesis_pdf" name="thesis_pdf" type="file" accept="application/pdf,.pdf" required class="form-control" style="max-width: 28rem;">
                        <button type="submit" class="btn btn-outline-primary">Read details</button>
                    </div>
                </form>
            <?php endif; ?>

            <!-- the result of reading the PDF: blue when fields were filled, red when nothing could be read -->
            <?php if ($autofillMessage !== '' && $autofillIsError): ?>
                <div class="alert alert-danger" role="alert"><?= escapeHtml($autofillMessage) ?></div>
            <?php elseif ($autofillMessage !== ''): ?>
                <div class="alert alert-info" role="status"><?= escapeHtml($autofillMessage) ?></div>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= escapeHtml($errorMessage) ?></div>
            <?php endif; ?>

            <!-- novalidate lets the server's messages appear for every field; the browser hints (required, maxlength) still guide typing -->
            <form method="post" action="<?= escapeHtml($formAction) ?>" class="card card-body" novalidate>
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">

                <!-- a PDF read for autofill waits here; the token ties this form to that exact waiting file -->
                <?php if ($pendingPdf !== null): ?>
                    <input type="hidden" name="pending_token" value="<?= escapeHtml($pendingPdf['token']) ?>">
                    <div class="form-check mb-3 p-2 ps-5 border rounded bg-info-subtle">
                        <input id="attach_pdf" name="attach_pdf" type="checkbox" value="1" class="form-check-input" checked>
                        <label for="attach_pdf" class="form-check-label">
                            Attach <strong><?= escapeHtml($pendingPdf['name']) ?></strong>
                            (<?= escapeHtml(ThesisFiles::readableSize((int) $pendingPdf['size'])) ?>) to this record when it is saved
                        </label>
                    </div>
                <?php endif; ?>

                <!-- each field: label linked by for/id, the escaped value, and its error message linked by aria-describedby -->
                <div class="mb-3">
                    <label for="title" class="form-label">Title *</label>
                    <input id="title" name="title" type="text" required maxlength="500"
                           class="form-control <?php if (isset($errors['title'])): ?>is-invalid<?php endif; ?> <?php if (in_array('title', $autofilledFields, true)): ?>bg-info-subtle<?php endif; ?>"
                           value="<?= escapeHtml($formValues['title']) ?>" aria-describedby="title-error">
                    <?php if (isset($errors['title'])): ?>
                        <div id="title-error" class="invalid-feedback"><?= escapeHtml($errors['title']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label for="authors" class="form-label">Authors * <span class="text-secondary small">(one name per line, in order)</span></label>
                    <textarea id="authors" name="authors" rows="4" required
                              class="form-control <?php if (isset($errors['authors'])): ?>is-invalid<?php endif; ?> <?php if (in_array('authors', $autofilledFields, true)): ?>bg-info-subtle<?php endif; ?>"
                              aria-describedby="authors-error"><?= escapeHtml($formValues['authors']) ?></textarea>
                    <?php if (isset($errors['authors'])): ?>
                        <div id="authors-error" class="invalid-feedback"><?= escapeHtml($errors['authors']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-3">
                        <label for="publication_year" class="form-label">Year *</label>
                        <input id="publication_year" name="publication_year" type="text" inputmode="numeric" required maxlength="4"
                               class="form-control <?php if (isset($errors['publication_year'])): ?>is-invalid<?php endif; ?> <?php if (in_array('publication_year', $autofilledFields, true)): ?>bg-info-subtle<?php endif; ?>"
                               value="<?= escapeHtml($formValues['publication_year']) ?>" aria-describedby="publication_year-error">
                        <?php if (isset($errors['publication_year'])): ?>
                            <div id="publication_year-error" class="invalid-feedback"><?= escapeHtml($errors['publication_year']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-12 col-md-5">
                        <label for="program" class="form-label">Program or course *</label>
                        <!-- list= connects the input to the suggestions below; typing a new program is still allowed -->
                        <input id="program" name="program" type="text" required maxlength="150" list="program-suggestions"
                               class="form-control <?php if (isset($errors['program'])): ?>is-invalid<?php endif; ?> <?php if (in_array('program', $autofilledFields, true)): ?>bg-info-subtle<?php endif; ?>"
                               value="<?= escapeHtml($formValues['program']) ?>" aria-describedby="program-error">
                        <datalist id="program-suggestions">
                            <?php foreach ($programSuggestions as $program): ?>
                                <option value="<?= escapeHtml($program) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <?php if (isset($errors['program'])): ?>
                            <div id="program-error" class="invalid-feedback"><?= escapeHtml($errors['program']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-12 col-md-4">
                        <label for="page_count" class="form-label">Number of pages</label>
                        <input id="page_count" name="page_count" type="text" inputmode="numeric" maxlength="4"
                               class="form-control <?php if (isset($errors['page_count'])): ?>is-invalid<?php endif; ?> <?php if (in_array('page_count', $autofilledFields, true)): ?>bg-info-subtle<?php endif; ?>"
                               value="<?= escapeHtml($formValues['page_count']) ?>" aria-describedby="page_count-error">
                        <?php if (isset($errors['page_count'])): ?>
                            <div id="page_count-error" class="invalid-feedback"><?= escapeHtml($errors['page_count']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="adviser" class="form-label">Thesis adviser <span class="text-secondary small">(RDPO only, not shown publicly)</span></label>
                    <input id="adviser" name="adviser" type="text" maxlength="200"
                           class="form-control <?php if (isset($errors['adviser'])): ?>is-invalid<?php endif; ?> <?php if (in_array('adviser', $autofilledFields, true)): ?>bg-info-subtle<?php endif; ?>"
                           value="<?= escapeHtml($formValues['adviser']) ?>" aria-describedby="adviser-error">
                    <?php if (isset($errors['adviser'])): ?>
                        <div id="adviser-error" class="invalid-feedback"><?= escapeHtml($errors['adviser']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6">
                        <label for="final_defense_date" class="form-label">Date of final defense</label>
                        <input id="final_defense_date" name="final_defense_date" type="date"
                               class="form-control <?php if (isset($errors['final_defense_date'])): ?>is-invalid<?php endif; ?> <?php if (in_array('final_defense_date', $autofilledFields, true)): ?>bg-info-subtle<?php endif; ?>"
                               value="<?= escapeHtml($formValues['final_defense_date']) ?>" aria-describedby="final_defense_date-error">
                        <?php if (isset($errors['final_defense_date'])): ?>
                            <div id="final_defense_date-error" class="invalid-feedback"><?= escapeHtml($errors['final_defense_date']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="date_submitted" class="form-label">Date submitted <span class="text-secondary small">(RDPO only)</span></label>
                        <input id="date_submitted" name="date_submitted" type="date"
                               class="form-control <?php if (isset($errors['date_submitted'])): ?>is-invalid<?php endif; ?> <?php if (in_array('date_submitted', $autofilledFields, true)): ?>bg-info-subtle<?php endif; ?>"
                               value="<?= escapeHtml($formValues['date_submitted']) ?>" aria-describedby="date_submitted-error">
                        <?php if (isset($errors['date_submitted'])): ?>
                            <div id="date_submitted-error" class="invalid-feedback"><?= escapeHtml($errors['date_submitted']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="keywords" class="form-label">Keywords <span class="text-secondary small">(separate with commas)</span></label>
                    <input id="keywords" name="keywords" type="text" maxlength="500"
                           class="form-control <?php if (isset($errors['keywords'])): ?>is-invalid<?php endif; ?> <?php if (in_array('keywords', $autofilledFields, true)): ?>bg-info-subtle<?php endif; ?>"
                           value="<?= escapeHtml($formValues['keywords']) ?>" aria-describedby="keywords-error">
                    <?php if (isset($errors['keywords'])): ?>
                        <div id="keywords-error" class="invalid-feedback"><?= escapeHtml($errors['keywords']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <!-- optional: older theses may have no abstract to enter -->
                    <label for="abstract" class="form-label">Abstract <span class="text-secondary small">(optional)</span></label>
                    <textarea id="abstract" name="abstract" rows="8" maxlength="10000"
                              class="form-control <?php if (isset($errors['abstract'])): ?>is-invalid<?php endif; ?> <?php if (in_array('abstract', $autofilledFields, true)): ?>bg-info-subtle<?php endif; ?>"
                              aria-describedby="abstract-error"><?= escapeHtml($formValues['abstract']) ?></textarea>
                    <?php if (isset($errors['abstract'])): ?>
                        <div id="abstract-error" class="invalid-feedback"><?= escapeHtml($errors['abstract']) ?></div>
                    <?php endif; ?>
                </div>

                <!-- new records start unpublished unless this box is ticked; edits use the Publish button on the record page -->
                <?php if (!$isEditing): ?>
                    <div class="form-check mb-3">
                        <input id="is_public" name="is_public" type="checkbox" value="1" class="form-check-input"
                               <?php if ($formValues['is_public']): ?>checked<?php endif; ?>>
                        <label for="is_public" class="form-check-label">Show on the public search page right away</label>
                    </div>
                <?php endif; ?>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save record</button>
                    <?php if ($isEditing): ?>
                        <a href="admin_thesis.php?id=<?= (int) $thesisId ?>" class="btn btn-outline-secondary">Cancel</a>
                    <?php else: ?>
                        <a href="admin.php" class="btn btn-outline-secondary">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</main>
</body>

</html>
