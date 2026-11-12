<?php
declare(strict_types=1); // keep typed values strict

use Scholar\AuditLog;
use Scholar\Auth\Access;
use Scholar\Auth\Session;
use Scholar\UsageReport;

// Visits and logins (M12, FB-06, provisional meaning chosen by the user on 2026-11-09): how often the website is
// visited and how often each staff account logs in, per day, week or month, with a CSV download for Excel
// admin_usage.php?group=weekly&from=2026-09-01&to=2026-11-09               -> the page
// admin_usage.php?group=weekly&from=2026-09-01&to=2026-11-09&download=csv  -> the same numbers as a file
require_once __DIR__ . '/../app/bootstrap.php';
Session::start();
$pdo = connectDatabase();
$account = Access::requirePermission($pdo, 'report.usage');

// this page only reads; the filters use GET so a view can be bookmarked
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$filters = UsageReport::filtersFromQuery($_GET, date('Y-m-d'));
$usageReport = new UsageReport($pdo);
$report = $usageReport->build($filters);

// ---- the download: sent instead of the page, then stop ----
if (isset($_GET['download']) && $_GET['download'] === 'csv') {
    // the file names staff accounts and how often they log in, so each download is written to the history
    AuditLog::record($pdo, $account['id'], 'report.usage_exported', 'usage_report', null);
    $fileName = 'visits_and_logins_' . $filters['group'] . '_' . $filters['from'] . '_to_' . $filters['to'] . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'wb');
    UsageReport::writeCsv($report, $output);
    fclose($output);
    exit;
}

// the download link keeps the same grouping and dates as the page
$downloadLink = 'admin_usage.php?' . http_build_query([
    'group' => $filters['group'], 'from' => $filters['from'], 'to' => $filters['to'], 'download' => 'csv',
]);
$groupWord = ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month'];

// "2026-11-03" -> "Nov 3, 2026"
function readableDay(string $day): string {
    return date('M j, Y', strtotime($day));
}

$csrfToken = Session::csrfToken(); // token for the logout form
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visits and logins - S.C.H.O.L.A.R.</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="assets/scholar.css" rel="stylesheet">
</head>

<body class="bg-light has-rdpo-sidebar">
<!-- the RDPO menu: a sidebar on wide screens, a Menu button on phones (shared by every RDPO page) -->
<?php require __DIR__ . '/assets/admin_sidebar.php'; ?>

<main class="container py-4" style="max-width: 60rem;">
    <h1 class="h3 mb-1">Visits and logins</h1>

    <!-- the filters: grouping and date range; the page reloads with them in the address -->
    <form method="get" action="admin_usage.php" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <span class="form-label d-block" id="group_label">Show per</span>
                <!-- btn-check radio buttons look like a button group but still work without JavaScript -->
                <div class="btn-group w-100" role="group" aria-labelledby="group_label">
                    <?php foreach (UsageReport::GROUPINGS as $code => $grouping): ?>
                        <input type="radio" class="btn-check" name="group" id="group_<?= escapeHtml($code) ?>" value="<?= escapeHtml($code) ?>"
                               <?php if ($filters['group'] === $code): ?>checked<?php endif; ?>>
                        <label class="btn btn-outline-primary" for="group_<?= escapeHtml($code) ?>"><?= escapeHtml($grouping['label']) ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <label for="from" class="form-label">From</label>
                <input id="from" name="from" type="date" class="form-control" value="<?= escapeHtml($filters['typed_from']) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label for="to" class="form-label">To</label>
                <input id="to" name="to" type="date" class="form-control" value="<?= escapeHtml($filters['typed_to']) ?>">
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-primary w-100">Show</button>
            </div>
        </div>
        <div class="mt-2 small">
            <a href="admin_usage.php">Reset</a>
        </div>
    </form>

    <?php if ($filters['was_shortened']): ?>
        <div class="alert alert-info small">That range is too long to show per <?= escapeHtml($groupWord[$filters['group']]) ?>, so it starts later. Choose a longer grouping to see more.</div>
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <p class="mb-0" role="status">
            <?= escapeHtml(readableDay($filters['from'])) ?> to <?= escapeHtml(readableDay($filters['to'])) ?>:
            <strong data-total-visits="<?= $report['total_visits'] ?>"><?= $report['total_visits'] ?></strong>
            <?php if ($report['total_visits'] === 1): ?>visit<?php else: ?>visits<?php endif; ?>,
            <strong data-total-logins="<?= $report['total_logins'] ?>"><?= $report['total_logins'] ?></strong>
            <?php if ($report['total_logins'] === 1): ?>login<?php else: ?>logins<?php endif; ?>
        </p>
        <a href="<?= escapeHtml($downloadLink) ?>" class="btn btn-success">Download CSV (opens in Excel)</a>
    </div>

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <h2 class="h5">Per <?= escapeHtml($groupWord[$filters['group']]) ?></h2>
            <div class="table-responsive">
                <table class="table table-sm table-striped bg-white align-middle">
                    <thead>
                        <tr>
                            <th scope="col"><?= escapeHtml(ucfirst($groupWord[$filters['group']])) ?></th>
                            <th scope="col" class="text-end">Visits</th>
                            <th scope="col" class="text-end">Logins</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report['periods'] as $period): ?>
                            <tr data-period="<?= escapeHtml($period['start']) ?>">
                                <td class="small"><?= escapeHtml($period['label']) ?></td>
                                <td class="text-end"><?= $period['visits'] ?></td>
                                <td class="text-end"><?= $period['logins'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="col-12 col-lg-5">
            <h2 class="h5">Logins per account</h2>
            <div class="table-responsive">
                <table class="table table-sm table-striped bg-white align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Account</th>
                            <th scope="col" class="text-end">Logins</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report['accounts'] as $staffMember): ?>
                            <tr>
                                <td class="small text-break">
                                    <a href="admin_account.php?id=<?= (int) $staffMember['id'] ?>"><?= escapeHtml($staffMember['username']) ?></a>
                                    <?php if (!$staffMember['is_active']): ?><span class="text-secondary">(disabled)</span><?php endif; ?>
                                </td>
                                <td class="text-end" data-account-logins="<?= escapeHtml($staffMember['username']) ?>"><?= $staffMember['logins'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>
</body>

</html>
