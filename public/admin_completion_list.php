<?php
declare(strict_types=1); // keep typed values strict

use Scholar\AuditLog;
use Scholar\Auth\Access;
use Scholar\Auth\Session;
use Scholar\Theses\CompletionList;

// the Registrar's completion list (M12, FB-03): students who completed a thesis, by year and department,
// sorted by last name; RDPO previews it here and downloads it as a CSV file that opens in Excel
// admin_completion_list.php?year=2025&department=...             -> the preview page
// admin_completion_list.php?year=2025&department=...&download=csv -> the file itself
require_once __DIR__ . '/../app/bootstrap.php';
Session::start();
$pdo = connectDatabase();
$account = Access::requirePermission($pdo, 'report.completion_list');

// this page only reads; the filters use GET so a list can be bookmarked
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$filters = CompletionList::filtersFromQuery($_GET);
$completionList = new CompletionList($pdo);
$rows = $completionList->rows($filters);

// ---- the download: sent instead of the page, then stop ----
if (isset($_GET['download']) && $_GET['download'] === 'csv') {
    // the file lists students' names, so each download is written to the history (no names are logged)
    AuditLog::record($pdo, $account['id'], 'report.completion_list_exported', 'report', null);

    header('Content-Type: text/csv; charset=utf-8');
    // "attachment" makes the browser save the file instead of showing it
    header('Content-Disposition: attachment; filename="' . CompletionList::fileName($filters) . '"');
    header('Cache-Control: no-store'); // do not keep a copy of the names in the browser cache
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'wb'); // php://output writes straight into the response
    CompletionList::writeCsv($rows, $output);
    fclose($output);
    exit;
}

$filterOptions = $completionList->filterOptions();

// the download link keeps the same filters as the preview, plus download=csv
// http_build_query() turns the array into "year=2025&department=College+of+..." with every value encoded
$downloadQuery = ['download' => 'csv'];
if ($filters['year'] !== '') {
    $downloadQuery['year'] = $filters['year'];
}
if ($filters['department'] !== '') {
    $downloadQuery['department'] = $filters['department'];
}
$downloadLink = 'admin_completion_list.php?' . http_build_query($downloadQuery);

// how many different theses the listed students wrote, for the summary line
$thesisIds = [];
foreach ($rows as $row) {
    $thesisIds[(int) $row['thesis_id']] = true;
}
$thesisCount = count($thesisIds);

// "Juan Miguel" + "Jr." -> "Juan Miguel, Jr." for the preview table
function givenNamesWithSuffix(array $row): string {
    if ($row['suffix'] === null || $row['suffix'] === '') {
        return $row['given_names'];
    }
    return $row['given_names'] . ', ' . $row['suffix'];
}

// an old author row without name parts shows its full name in the last-name column instead of a blank
function lastNameOrFullName(array $row): string {
    if ($row['last_name'] === '') {
        return $row['author_name'];
    }
    return $row['last_name'];
}

// "2025-04-10" -> "April 10, 2025"; '-' when no date was recorded
function readableDate(?string $date): string {
    if ($date === null || $date === '') {
        return '-';
    }
    return date('F j, Y', strtotime($date));
}

$csrfToken = Session::csrfToken(); // token for the logout form
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar completion list - S.C.H.O.L.A.R.</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="assets/scholar.css" rel="stylesheet">
</head>

<body class="bg-light has-rdpo-sidebar">
<!-- the RDPO menu: a sidebar on wide screens, a Menu button on phones (shared by every RDPO page) -->
<?php require __DIR__ . '/assets/admin_sidebar.php'; ?>

<main class="container py-4">
    <h1 class="h3 mb-1">Registrar completion list</h1>

    <!-- the filters: choosing a year or department and pressing Show reloads this page with them in the address -->
    <form method="get" action="admin_completion_list.php" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label for="year" class="form-label">Year</label>
                <select id="year" name="year" class="form-select">
                    <option value="">All years</option>
                    <?php foreach ($filterOptions['years'] as $year): ?>
                        <option value="<?= (int) $year ?>" <?php if ((string) $year === $filters['year']): ?>selected<?php endif; ?>><?= (int) $year ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6">
                <label for="department" class="form-label">Department</label>
                <select id="department" name="department" class="form-select">
                    <option value="">All departments</option>
                    <?php foreach ($filterOptions['departments'] as $department): ?>
                        <option value="<?= escapeHtml($department) ?>" <?php if ($department === $filters['department']): ?>selected<?php endif; ?>><?= escapeHtml($department) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Show</button>
                <a href="admin_completion_list.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </div>
    </form>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <p class="mb-0" role="status">
            <?= count($rows) ?> <?php if (count($rows) === 1): ?>student<?php else: ?>students<?php endif; ?>
            from <?= $thesisCount ?> <?php if ($thesisCount === 1): ?>thesis<?php else: ?>theses<?php endif; ?>
        </p>
        <?php if (count($rows) > 0): ?>
            <!-- the file itself holds names only, one per row as "Last name, First name"; the table below is for RDPO to check -->
            <div class="text-md-end">
                <a href="<?= escapeHtml($downloadLink) ?>" class="btn btn-success">Download CSV (opens in Excel)</a>
            </div>
        <?php endif; ?>
    </div>

    <?php if (count($rows) === 0): ?>
        <div class="alert alert-secondary">No students match these filters.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-striped bg-white align-middle">
                <thead>
                    <tr>
                        <th scope="col">No.</th>
                        <th scope="col">Last name</th>
                        <th scope="col">First names</th>
                        <th scope="col">Program</th>
                        <th scope="col" class="d-none d-md-table-cell">Department</th>
                        <th scope="col" class="d-none d-lg-table-cell">Thesis title</th>
                        <th scope="col">Final defense</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $index => $row): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><?= escapeHtml(lastNameOrFullName($row)) ?></td>
                            <td><?= escapeHtml(givenNamesWithSuffix($row)) ?></td>
                            <td class="small"><?= escapeHtml((string) $row['program']) ?></td>
                            <td class="small d-none d-md-table-cell"><?= escapeHtml((string) $row['department']) ?></td>
                            <td class="small d-none d-lg-table-cell">
                                <a href="admin_thesis.php?id=<?= (int) $row['thesis_id'] ?>"><?= escapeHtml($row['title']) ?></a>
                            </td>
                            <td class="small"><?= escapeHtml(readableDate($row['final_defense_date'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>
</body>

</html>
