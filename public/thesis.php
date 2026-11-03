<?php
declare(strict_types=1); // keep typed values strict

use Scholar\Theses\PublicCatalog;

// the public page for one thesis: only the fields in PublicCatalog::DETAIL_COLUMNS, and only if it is published
// like the search page, there is no login and no session here
require_once __DIR__ . '/../app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
header('X-Content-Type-Options: nosniff');

// digits only; anything else is treated the same as a thesis that does not exist
$thesis = null;
if (isset($_GET['id']) && is_string($_GET['id']) && preg_match('/\A[1-9][0-9]{0,18}\z/', $_GET['id'])) {
    $catalog = new PublicCatalog(connectDatabase());
    $thesis = $catalog->findPublicThesis((int) $_GET['id']);
}

// unpublished, archived and missing records all get the same "not found" page,
// so a visitor cannot tell whether a hidden record exists
$isFound = $thesis !== null;
if (!$isFound) {
    http_response_code(404);
}

// "2024-03-15" becomes "March 15, 2024"; an empty date shows nothing
function readableDate(?string $date): string {
    if ($date === null || $date === '') {
        return '';
    }
    $dateObject = DateTime::createFromFormat('Y-m-d', $date); // read the database format exactly
    if ($dateObject === false) {
        return $date; // show it as stored rather than hiding it if it is somehow in another format
    }
    return $dateObject->format('F j, Y');
}

$pageTitle = 'Thesis not found';
if ($isFound) {
    $pageTitle = $thesis['title'];
}
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
<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="index.php">S.C.H.O.L.A.R. research search</a>
        <a class="btn btn-sm btn-outline-light" href="login.php">RDPO login</a>
    </div>
</nav>

<main class="container py-4">
    <!-- history.back() would leave the site if the visitor arrived from a shared link, so link to the search page instead -->
    <p><a href="index.php">&larr; Back to search</a></p>

    <?php if (!$isFound): ?>
        <h1 class="h3">Thesis not found</h1>
        <p>This thesis does not exist or is not available to the public. <a href="index.php">Search all published theses</a>.</p>
    <?php else: ?>
        <article class="card card-body">
            <h1 class="h3"><?= escapeHtml($thesis['title']) ?></h1>
            <p class="mb-1"><?= escapeHtml(implode(', ', $thesis['authors'])) ?></p>
            <p class="text-secondary"><?= escapeHtml((string) $thesis['publication_year']) ?> &middot; <?= escapeHtml((string) $thesis['program']) ?></p>

            <h2 class="h5">Abstract</h2>
            <?php if ($thesis['abstract'] !== null && $thesis['abstract'] !== ''): ?>
                <!-- escape first, then nl2br turns the abstract's line breaks into <br> tags -->
                <p><?= nl2br(escapeHtml($thesis['abstract'])) ?></p>
            <?php else: ?>
                <!-- the abstract is optional, so say so instead of leaving an empty heading -->
                <p class="text-secondary fst-italic">No abstract recorded.</p>
            <?php endif; ?>

            <dl class="row mb-0 small">
                <?php if ($thesis['keywords'] !== null && $thesis['keywords'] !== ''): ?>
                    <dt class="col-sm-3">Keywords</dt>
                    <dd class="col-sm-9"><?= escapeHtml($thesis['keywords']) ?></dd>
                <?php endif; ?>
                <?php if ($thesis['final_defense_date'] !== null): ?>
                    <dt class="col-sm-3">Date of final defense</dt>
                    <dd class="col-sm-9"><?= escapeHtml(readableDate($thesis['final_defense_date'])) ?></dd>
                <?php endif; ?>
                <?php if ($thesis['page_count'] !== null): ?>
                    <dt class="col-sm-3">Number of pages</dt>
                    <dd class="col-sm-9"><?= escapeHtml((string) $thesis['page_count']) ?></dd>
                <?php endif; ?>
            </dl>
        </article>
        <p class="small text-secondary mt-3">The full manuscript is not available online.</p>
    <?php endif; ?>
</main>
</body>

</html>
