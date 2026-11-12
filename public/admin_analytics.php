<?php
declare(strict_types=1); // keep typed values strict

use Scholar\Auth\Access;
use Scholar\Auth\Session;
use Scholar\Theses\ResearchAnalytics;

// Research analytics (M13, FB-05): totals of theses and student authors per year and per department,
// as tables (no charts, at RDPO's request), with a CSV download for Excel
// admin_analytics.php?basis=defense&scope=public&from_year=2020&to_year=2025               -> the page
// admin_analytics.php?basis=defense&scope=public&from_year=2020&to_year=2025&download=csv  -> the year-by-department table as a file
require_once __DIR__ . '/../app/bootstrap.php';
Session::start();
$pdo = connectDatabase();
$account = Access::requirePermission($pdo, 'report.analytics');

// this page only reads; the filters use GET so a view can be bookmarked
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$filters = ResearchAnalytics::filtersFromQuery($_GET);
$analytics = new ResearchAnalytics($pdo);
$report = $analytics->build($filters);
$availableYears = $analytics->availableYears($filters['basis']); // the choices for the From / To year dropdowns

// ---- the download: sent instead of the page, then stop ----
// only totals are in the file (no names), so unlike the completion list it is not written to the history
if (isset($_GET['download']) && $_GET['download'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . ResearchAnalytics::fileName($filters) . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'wb');
    ResearchAnalytics::writeCsv($report, $output);
    fclose($output);
    exit;
}

// the download link keeps every filter shown on the page, including the year range
$downloadLink = 'admin_analytics.php?' . http_build_query([
    'basis' => $filters['basis'], 'scope' => $filters['scope'],
    'from_year' => $filters['from_year'], 'to_year' => $filters['to_year'], 'download' => 'csv',
]);

// the year range in words for the summary line, e.g. "from 2020 to 2025", "from 2020 on", "up to 2025"
$rangeText = '';
if ($filters['from_year'] !== '' && $filters['to_year'] !== '') {
    if ($filters['from_year'] === $filters['to_year']) {
        $rangeText = 'in ' . $filters['from_year'];
    } else {
        $rangeText = 'from ' . $filters['from_year'] . ' to ' . $filters['to_year'];
    }
} elseif ($filters['from_year'] !== '') {
    $rangeText = 'from ' . $filters['from_year'] . ' on';
} elseif ($filters['to_year'] !== '') {
    $rangeText = 'up to ' . $filters['to_year'];
}
$csrfToken = Session::csrfToken(); // token for the logout form
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Research analytics - S.C.H.O.L.A.R.</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="assets/scholar.css" rel="stylesheet">
    <style>
        /* the Year column of the year-by-department table stays in place while the department columns scroll sideways;
           "position: sticky; left: 0" keeps a cell at the left edge of its scrolling box */
        .frozen-first-column .frozen-cell {
            position: sticky;
            left: 0;
            z-index: 1; /* above the cells that slide underneath it */
            background-color: var(--bs-body-bg); /* solid, so the numbers sliding underneath do not show through */
            box-shadow: inset -2px 0 0 var(--bs-border-color); /* a divider that stays at the column's right edge */
        }
    </style>
</head>

<body class="bg-light has-rdpo-sidebar">
<!-- the RDPO menu: a sidebar on wide screens, a Menu button on phones (shared by every RDPO page) -->
<?php require __DIR__ . '/assets/admin_sidebar.php'; ?>

<main class="container py-4">
    <h1 class="h3 mb-1">Research analytics</h1>

    <!-- the filters: radio buttons styled as button groups; pressing Show reloads the page with them in the address -->
    <form method="get" action="admin_analytics.php" class="card card-body mb-3">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-6">
                <span class="form-label d-block" id="basis_label">Count each thesis in its</span>
                <div class="btn-group w-100" role="group" aria-labelledby="basis_label">
                    <?php foreach (ResearchAnalytics::YEAR_BASES as $code => $label): ?>
                        <input type="radio" class="btn-check" name="basis" id="basis_<?= escapeHtml($code) ?>" value="<?= escapeHtml($code) ?>"
                               <?php if ($filters['basis'] === $code): ?>checked<?php endif; ?>>
                        <label class="btn btn-outline-primary" for="basis_<?= escapeHtml($code) ?>"><?= escapeHtml($label) ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <label for="scope" class="form-label">Records</label>
                <select id="scope" name="scope" class="form-select">
                    <?php foreach (ResearchAnalytics::SCOPES as $code => $label): ?>
                        <option value="<?= escapeHtml($code) ?>" <?php if ($filters['scope'] === $code): ?>selected<?php endif; ?>><?= escapeHtml($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- the year range: only years that have records are offered; "Earliest" / "Latest" mean no limit -->
            <div class="col-6 col-md-3">
                <label for="from_year" class="form-label">From year</label>
                <select id="from_year" name="from_year" class="form-select">
                    <option value="">Earliest</option>
                    <?php foreach ($availableYears as $year): ?>
                        <option value="<?= escapeHtml($year) ?>" <?php if ($filters['from_year'] === $year): ?>selected<?php endif; ?>><?= escapeHtml($year) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label for="to_year" class="form-label">To year</label>
                <select id="to_year" name="to_year" class="form-select">
                    <option value="">Latest</option>
                    <?php foreach ($availableYears as $year): ?>
                        <option value="<?= escapeHtml($year) ?>" <?php if ($filters['to_year'] === $year): ?>selected<?php endif; ?>><?= escapeHtml($year) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <button type="submit" class="btn btn-primary w-100">Show</button>
            </div>
            <div class="col-6 col-md-3">
                <a href="admin_analytics.php" class="btn btn-outline-secondary w-100">Reset</a>
            </div>
        </div>
        <?php if ($filters['basis'] === 'defense'): ?>
            <p class="small text-secondary mt-2 mb-0">A thesis without a final defense date is counted in its thesis year, like the Registrar completion list.</p>
        <?php endif; ?>
    </form>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <p class="mb-0" role="status">
            <strong data-total-theses="<?= $report['total_theses'] ?>"><?= $report['total_theses'] ?></strong>
            <?php if ($report['total_theses'] === 1): ?>thesis<?php else: ?>theses<?php endif; ?> by
            <strong data-total-students="<?= $report['total_students'] ?>"><?= $report['total_students'] ?></strong>
            <?php if ($report['total_students'] === 1): ?>student<?php else: ?>students<?php endif; ?>
            <?php if ($rangeText !== ''): ?><span data-year-range><?= escapeHtml($rangeText) ?></span><?php endif; ?>
        </p>
        <?php if ($report['total_theses'] > 0): ?>
            <a href="<?= escapeHtml($downloadLink) ?>" class="btn btn-success">Download CSV (opens in Excel)</a>
        <?php endif; ?>
    </div>

    <?php if ($report['total_theses'] === 0): ?>
        <?php if (ResearchAnalytics::hasYearRange($filters)): ?>
            <div class="alert alert-secondary">No records in this year range. Choose other years or press Reset.</div>
        <?php else: ?>
            <div class="alert alert-secondary">There are no records to count yet.</div>
        <?php endif; ?>
    <?php else: ?>
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-6">
                <h2 class="h5">Per year</h2>
                <div class="table-responsive">
                    <table class="table table-sm table-striped bg-white align-middle">
                        <thead>
                            <tr>
                                <th scope="col">Year</th>
                                <th scope="col" class="text-end">Theses</th>
                                <th scope="col" class="text-end">Students</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report['years'] as $yearKey): ?>
                                <tr data-year="<?= escapeHtml($yearKey) ?>">
                                    <th scope="row" class="fw-normal"><?= escapeHtml(ResearchAnalytics::yearLabel($yearKey)) ?></th>
                                    <td class="text-end"><?= $report['by_year'][$yearKey]['theses'] ?></td>
                                    <td class="text-end"><?= $report['by_year'][$yearKey]['students'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="fw-semibold">
                                <th scope="row">Total</th>
                                <td class="text-end"><?= $report['total_theses'] ?></td>
                                <td class="text-end"><?= $report['total_students'] ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <h2 class="h5">Per department</h2>
                <div class="table-responsive">
                    <table class="table table-sm table-striped bg-white align-middle">
                        <thead>
                            <tr>
                                <th scope="col">Department</th>
                                <th scope="col" class="text-end">Theses</th>
                                <th scope="col" class="text-end">Students</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report['departments'] as $department): ?>
                                <tr data-department="<?= escapeHtml($department) ?>">
                                    <th scope="row" class="fw-normal text-break"><?= escapeHtml($department) ?></th>
                                    <td class="text-end"><?= $report['by_department'][$department]['theses'] ?></td>
                                    <td class="text-end"><?= $report['by_department'][$department]['students'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="fw-semibold">
                                <th scope="row">Total</th>
                                <td class="text-end"><?= $report['total_theses'] ?></td>
                                <td class="text-end"><?= $report['total_students'] ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <h2 class="h5">Theses per year and department</h2>
        <!-- a wide table scrolls sideways inside its own box, not the whole page; the Year column stays frozen on the left -->
        <div class="table-responsive">
            <table class="table table-sm table-bordered bg-white align-middle frozen-first-column">
                <thead>
                    <tr>
                        <th scope="col" class="frozen-cell">Year</th>
                        <?php foreach ($report['departments'] as $department): ?>
                            <th scope="col" class="text-end small"><?= escapeHtml($department) ?></th>
                        <?php endforeach; ?>
                        <th scope="col" class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['years'] as $yearKey): ?>
                        <tr>
                            <th scope="row" class="fw-normal text-nowrap frozen-cell"><?= escapeHtml(ResearchAnalytics::yearLabel($yearKey)) ?></th>
                            <?php foreach ($report['departments'] as $department): ?>
                                <?php $count = ResearchAnalytics::cell($report, $yearKey, $department); ?>
                                <td class="text-end<?php if ($count === 0): ?> text-secondary<?php endif; ?>" data-cell="<?= escapeHtml($yearKey . '|' . $department) ?>"><?= $count ?></td>
                            <?php endforeach; ?>
                            <td class="text-end fw-semibold"><?= $report['by_year'][$yearKey]['theses'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="fw-semibold">
                        <th scope="row" class="frozen-cell">Total</th>
                        <?php foreach ($report['departments'] as $department): ?>
                            <td class="text-end"><?= $report['by_department'][$department]['theses'] ?></td>
                        <?php endforeach; ?>
                        <td class="text-end"><?= $report['total_theses'] ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</main>
</body>

</html>
