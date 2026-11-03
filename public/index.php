<?php
declare(strict_types=1); // keep typed values strict

use Scholar\Theses\ThesisSearch;

// the public research search page, similar to SSRN's: search and filter published theses, metadata only
// no login and no session: visitors get no cookie, and this page can never show RDPO-only fields
// (ThesisSearch's public mode only selects PublicCatalog::SEARCH_COLUMNS from published, non-archived records)
require_once __DIR__ . '/../app/bootstrap.php';

// the search form uses GET, so results can be bookmarked; nothing on this page changes data
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
header('X-Content-Type-Options: nosniff'); // the browser must treat this page only as HTML

$pdo = connectDatabase();
$filters = ThesisSearch::filtersFromQuery($_GET, false); // false = public mode, no status filter
$search = new ThesisSearch($pdo);
$results = $search->search($filters, false);
$filterOptions = $search->filterOptions(false);

$firstShown = 0;
$lastShown = 0;
if ($results['total'] > 0) {
    $firstShown = ($results['page'] - 1) * ThesisSearch::RESULTS_PER_PAGE + 1;
    $lastShown = $firstShown + count($results['rows']) - 1;
}
$sortOptions = ['newest' => 'Newest first', 'oldest' => 'Oldest first', 'title' => 'Title A-Z'];

// a search is "active" when the visitor typed or picked something, which changes the heading text
$isFiltered = $filters['q'] !== '' || $filters['year'] !== null || $filters['program'] !== '';

// shortens a long abstract for the results list without cutting a word in half
// example: a 900-character abstract becomes its first ~280 characters followed by "..."
function shortAbstract(?string $abstract): string {
    if ($abstract === null) {
        return '';
    }
    if (mb_strlen($abstract) <= 280) {
        return $abstract;
    }
    $shortened = mb_substr($abstract, 0, 280);
    $lastSpace = mb_strrpos($shortened, ' '); // position of the last space, so the cut lands between words
    if ($lastSpace !== false) {
        $shortened = mb_substr($shortened, 0, $lastSpace);
    }
    return $shortened . '...';
}

// "IoT, flood monitoring" becomes ['IoT', 'flood monitoring'] for the keyword badges
function keywordList(?string $keywords): array {
    if ($keywords === null || $keywords === '') {
        return [];
    }
    $list = [];
    foreach (explode(',', $keywords) as $keyword) {
        $cleanKeyword = trim($keyword);
        if ($cleanKeyword !== '') {
            $list[] = $cleanKeyword;
        }
    }
    return $list;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>S.C.H.O.L.A.R. research search</title>
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
    <h1 class="h3">Find World Citi Colleges theses</h1>
    <p class="text-secondary">Search approved theses by title, author, keyword or topic. Only published thesis details are shown here; full manuscripts are kept by the RDPO.</p>

    <!-- search box and filters; the server checks every value again, so editing the address bar cannot break the page -->
    <form method="get" action="index.php" class="card card-body mb-4" role="search">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-lg-5">
                <label for="q" class="form-label">Search</label>
                <input id="q" name="q" type="search" class="form-control" maxlength="200"
                       placeholder="e.g. flood monitoring, inventory, an author's name" value="<?= escapeHtml($filters['q']) ?>">
            </div>
            <div class="col-6 col-lg-2">
                <label for="year" class="form-label">Year</label>
                <select id="year" name="year" class="form-select">
                    <option value="">Any year</option>
                    <?php foreach ($filterOptions['years'] as $year): ?>
                        <option value="<?= escapeHtml((string) $year) ?>" <?php if ($filters['year'] === (int) $year): ?>selected<?php endif; ?>><?= escapeHtml((string) $year) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label for="program" class="form-label">Program</label>
                <select id="program" name="program" class="form-select">
                    <option value="">Any program</option>
                    <?php foreach ($filterOptions['programs'] as $program): ?>
                        <option value="<?= escapeHtml($program) ?>" <?php if ($filters['program'] === $program): ?>selected<?php endif; ?>><?= escapeHtml($program) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label for="sort" class="form-label">Sort</label>
                <select id="sort" name="sort" class="form-select">
                    <?php foreach ($sortOptions as $value => $label): ?>
                        <option value="<?= escapeHtml($value) ?>" <?php if ($filters['sort'] === $value): ?>selected<?php endif; ?>><?= escapeHtml($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-1">
                <button type="submit" class="btn btn-primary w-100">Search</button>
            </div>
        </div>
        <?php if ($isFiltered): ?>
            <div class="mt-2"><a href="index.php" class="small">Clear search</a></div>
        <?php endif; ?>
    </form>

    <?php if ($results['total'] === 0): ?>
        <!-- empty state with a suggestion, instead of a blank page -->
        <div class="alert alert-secondary">No theses match your search. Try fewer or different words, or clear the year and program filters.</div>
    <?php else: ?>
        <!-- aria-live lets screen readers announce the new result count after a search -->
        <p class="text-secondary small" aria-live="polite">
            Showing <?= $firstShown ?>-<?= $lastShown ?> of <?= $results['total'] ?> <?php if ($results['total'] === 1): ?>thesis<?php else: ?>theses<?php endif; ?>
        </p>

        <?php foreach ($results['rows'] as $row): ?>
            <article class="card card-body mb-3">
                <h2 class="h5 mb-1"><a href="thesis.php?id=<?= (int) $row['id'] ?>"><?= escapeHtml($row['title']) ?></a></h2>
                <div class="small mb-1"><?= escapeHtml(implode(', ', $row['authors'])) ?></div>
                <div class="small text-secondary mb-2"><?= escapeHtml((string) $row['publication_year']) ?> &middot; <?= escapeHtml((string) $row['program']) ?></div>
                <!-- records without an abstract leave this line out of the results list -->
                <?php if ($row['abstract'] !== null && $row['abstract'] !== ''): ?>
                    <p class="mb-2"><?= escapeHtml(shortAbstract($row['abstract'])) ?></p>
                <?php endif; ?>
                <div>
                    <?php foreach (keywordList($row['keywords']) as $keyword): ?>
                        <!-- each keyword links to a search for that keyword -->
                        <a href="index.php?<?= escapeHtml(http_build_query(['q' => $keyword])) ?>" class="badge text-bg-light border text-decoration-none"><?= escapeHtml($keyword) ?></a>
                    <?php endforeach; ?>
                </div>
            </article>
        <?php endforeach; ?>

        <?php if ($results['pages'] > 1): ?>
            <nav aria-label="Result pages">
                <ul class="pagination flex-wrap">
                    <?php for ($pageNumber = 1; $pageNumber <= $results['pages']; $pageNumber++): ?>
                        <li class="page-item <?php if ($pageNumber === $results['page']): ?>active<?php endif; ?>">
                            <a class="page-link" href="index.php?<?= escapeHtml(ThesisSearch::queryString($filters, $pageNumber)) ?>"><?= $pageNumber ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>

</html>
