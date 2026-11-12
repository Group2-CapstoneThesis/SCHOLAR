<?php
declare(strict_types=1); // keep typed values strict

use Scholar\Auth\Access;
use Scholar\Auth\Session;
use Scholar\Search\SearchIndex;
use Scholar\Theses\Campuses;
use Scholar\Theses\ThesisSearch;

// load shared code, then restore and check the session before any HTML is shown
require_once __DIR__ . '/../app/bootstrap.php';
Session::start();
$pdo = connectDatabase(); // needed so the guard below can confirm the account is still active
// sends unauthenticated visitors and disabled accounts back to login, and refuses accounts without this permission
$account = Access::requirePermission($pdo, 'thesis.view_all');

// this page only reads; the filter form uses GET so the address can be bookmarked or shared
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$filters = ThesisSearch::filtersFromQuery($_GET, true); // true = RDPO mode with the status filter
// hybrid search with words typed (M14); word search only when semantic search is switched off
$search = new ThesisSearch($pdo, SearchIndex::create($pdo));
$results = $search->search($filters, true);
$filterOptions = $search->filterOptions(true);

// numbers for the "Showing 11-20 of 34" line
$firstShown = 0;
$lastShown = 0;
if ($results['total'] > 0) {
    $firstShown = ($results['page'] - 1) * ThesisSearch::RESULTS_PER_PAGE + 1;
    $lastShown = $firstShown + count($results['rows']) - 1;
}

// labels for the status dropdown; the keys are the values ThesisSearch accepts
$statusOptions = [
    'active' => 'Active (not archived)',
    'public' => 'Published',
    'unpublished' => 'Unpublished',
    'archived' => 'Archived',
    'all' => 'All records',
];
// the empty value means "the default order": Best match when words are typed, otherwise newest year first
$sortOptions = ['' => 'Newest year first', 'oldest' => 'Oldest year first', 'title' => 'Title A-Z', 'updated' => 'Recently changed'];
if ($filters['q'] !== '') {
    $sortOptions = ['' => 'Best match', 'newest' => 'Newest year first', 'oldest' => 'Oldest year first', 'title' => 'Title A-Z', 'updated' => 'Recently changed'];
}
$selectedSort = $filters['sort'];
if ($filters['sort'] === ThesisSearch::defaultSort($filters['q'])) {
    $selectedSort = '';
}

$flashMessage = Session::takeFlash(); // e.g. "Record saved." from the page that redirected here
$flashIsError = Session::takeFlashIsError(); // red for problems, green for normal confirmations
$csrfToken = Session::csrfToken(); // token for the logout form
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RDPO thesis records - S.C.H.O.L.A.R.</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="assets/scholar.css" rel="stylesheet">
</head>

<body class="bg-light has-rdpo-sidebar">
<!-- the RDPO menu: a sidebar on wide screens, a Menu button on phones (shared by every RDPO page) -->
<?php require __DIR__ . '/assets/admin_sidebar.php'; ?>

<main class="container py-4">
    <h1 class="h3 mb-3">RDPO thesis records</h1>

    <!-- one-time message from the previous action, such as saving or archiving a record -->
    <?php if ($flashMessage !== '' && $flashIsError): ?>
        <div class="alert alert-danger" role="alert"><?= escapeHtml($flashMessage) ?></div>
    <?php elseif ($flashMessage !== ''): ?>
        <div class="alert alert-success" role="status"><?= escapeHtml($flashMessage) ?></div>
    <?php endif; ?>

    <!-- search and filters; every choice is checked again on the server by ThesisSearch::filtersFromQuery() -->
    <!-- the one-line layout starts at "xl" (1200px) because the sidebar takes 240px of the width from 992px up -->
    <form method="get" action="admin.php" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <!-- two rows of filters on wide screens: search, status, year / program, campus, sort, button -->
            <div class="col-12 col-xl-6">
                <label for="q" class="form-label">Search title, abstract, keywords or author</label>
                <input id="q" name="q" type="search" class="form-control" maxlength="200" value="<?= escapeHtml($filters['q']) ?>">
            </div>
            <div class="col-6 col-xl-3">
                <label for="status" class="form-label">Status</label>
                <select id="status" name="status" class="form-select">
                    <?php foreach ($statusOptions as $value => $label): ?>
                        <option value="<?= escapeHtml($value) ?>" <?php if ($filters['status'] === $value): ?>selected<?php endif; ?>><?= escapeHtml($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-xl-3">
                <label for="year" class="form-label">Year</label>
                <select id="year" name="year" class="form-select">
                    <option value="">Any</option>
                    <?php foreach ($filterOptions['years'] as $year): ?>
                        <option value="<?= escapeHtml((string) $year) ?>" <?php if ($filters['year'] === (int) $year): ?>selected<?php endif; ?>><?= escapeHtml((string) $year) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-xl-3">
                <label for="program" class="form-label">Program</label>
                <select id="program" name="program" class="form-select">
                    <option value="">Any</option>
                    <?php foreach ($filterOptions['programs'] as $program): ?>
                        <option value="<?= escapeHtml($program) ?>" <?php if ($filters['program'] === $program): ?>selected<?php endif; ?>><?= escapeHtml($program) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-xl-3">
                <label for="campus" class="form-label">Campus</label>
                <select id="campus" name="campus" class="form-select">
                    <option value="">Any</option>
                    <?php foreach (Campuses::NAMES as $campusName): ?>
                        <option value="<?= escapeHtml($campusName) ?>" <?php if ($filters['campus'] === $campusName): ?>selected<?php endif; ?>><?= escapeHtml($campusName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-xl-3">
                <label for="sort" class="form-label">Sort</label>
                <select id="sort" name="sort" class="form-select">
                    <?php foreach ($sortOptions as $value => $label): ?>
                        <option value="<?= escapeHtml((string) $value) ?>" <?php if ($selectedSort === (string) $value): ?>selected<?php endif; ?>><?= escapeHtml($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-xl-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100">Search</button>
            </div>
        </div>
        <div class="mt-2"><a href="admin.php" class="small">Clear filters</a></div>
    </form>


    <?php if ($results['mode'] === 'words_fallback'): ?>
        <!-- semantic search (Ollama / Qdrant) did not answer, so only records containing the typed words are listed -->
        <div class="alert alert-warning small" role="status">
            Search by meaning is unavailable right now, so these results only include records containing your exact words.
        </div>
    <?php endif; ?>

    <?php if ($results['total'] === 0): ?>
        <!-- empty state: explain what to do next instead of showing an empty table -->
        <div class="alert alert-secondary">No records match these filters. Try fewer words or choose "All records".<?php if (Access::allows($account, 'thesis.create')): ?> You can also <a href="admin_thesis_form.php">add a new record</a>.<?php endif; ?></div>
    <?php else: ?>
        <p class="text-secondary small">Showing <?= $firstShown ?>-<?= $lastShown ?> of <?= $results['total'] ?> records</p>

        <div class="table-responsive">
            <table class="table table-hover bg-white align-middle">
                <thead>
                    <tr>
                        <th scope="col">Title</th>
                        <!-- d-none d-md-table-cell hides these two columns on phones so the status stays visible -->
                        <th scope="col" class="d-none d-md-table-cell">Authors</th>
                        <th scope="col">Year</th>
                        <th scope="col" class="d-none d-md-table-cell">Program</th>
                        <th scope="col" class="d-none d-sm-table-cell">Campus</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results['rows'] as $row): ?>
                        <tr>
                            <td>
                                <a href="admin_thesis.php?id=<?= (int) $row['id'] ?>"><?= escapeHtml($row['title']) ?></a>
                                <?php if (isset($row['match']) && $row['match'] === 'meaning'): ?>
                                    <!-- found by meaning (semantic search): related, but it may not contain every typed word -->
                                    <span class="badge text-bg-info" data-match="meaning">Similar topic</span>
                                <?php endif; ?>
                            </td>
                            <td class="small d-none d-md-table-cell"><?= escapeHtml(implode(', ', $row['authors'])) ?></td>
                            <td><?= escapeHtml((string) $row['publication_year']) ?></td>
                            <td class="small d-none d-md-table-cell"><?= escapeHtml((string) $row['program']) ?></td>
                            <td class="small text-nowrap d-none d-sm-table-cell"><?= escapeHtml((string) $row['campus']) ?></td>
                            <td>
                                <!-- status is shown as text, not only as a colour -->
                                <?php if ($row['archived_at'] !== null): ?>
                                    <span class="badge text-bg-secondary">Archived</span>
                                <?php elseif ((int) $row['is_public'] === 1): ?>
                                    <span class="badge text-bg-success">Published</span>
                                <?php else: ?>
                                    <span class="badge text-bg-warning">Unpublished</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- page links keep the current search and filters -->
        <?php if ($results['pages'] > 1): ?>
            <nav aria-label="Result pages">
                <ul class="pagination flex-wrap">
                    <?php for ($pageNumber = 1; $pageNumber <= $results['pages']; $pageNumber++): ?>
                        <li class="page-item <?php if ($pageNumber === $results['page']): ?>active<?php endif; ?>">
                            <a class="page-link" href="admin.php?<?= escapeHtml(ThesisSearch::queryString($filters, $pageNumber)) ?>"><?= $pageNumber ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>

</html>
