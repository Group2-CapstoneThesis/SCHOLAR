<?php
declare(strict_types=1); // keep typed values strict

use Scholar\ActivityLog;
use Scholar\Auth\Access;
use Scholar\Auth\Session;

// the Activity log (M12, FB-07): every audit entry, newest first, with filters,
// and the head administrator's button to delete entries older than one year (the deletion itself is logged)
// admin_activity_log.php?action=auth.login&account=5&from=2026-11-01&to=2026-11-30&page=2
require_once __DIR__ . '/../app/bootstrap.php';
Session::start();
$pdo = connectDatabase();
$account = Access::requirePermission($pdo, 'audit.view');

$requestMethod = $_SERVER['REQUEST_METHOD'];
if ($requestMethod !== 'GET' && $requestMethod !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$activityLog = new ActivityLog($pdo);

// ---- the only POST: "Delete entries older than one year" ----
if ($requestMethod === 'POST') {
    $submittedToken = null;
    if (isset($_POST['csrf_token'])) {
        $submittedToken = $_POST['csrf_token'];
    }
    if (!Session::verifyCsrf($submittedToken)) {
        http_response_code(403); // this is for preventing CSRF attacks
        exit('The form expired. Return to the activity log and try again.');
    }
    if (!isset($_POST['action']) || $_POST['action'] !== 'delete_old') {
        http_response_code(422);
        exit('Unknown action.');
    }
    // viewing the log and deleting from it are separate permissions, checked separately
    if (!Access::allows($account, 'audit.delete_old')) {
        http_response_code(403);
        exit('You do not have permission to do that.');
    }

    $deletedCount = $activityLog->deleteOlderThanRetention($account['id']);
    if ($deletedCount === 0) {
        Session::setFlash('There were no entries older than one year. Nothing was deleted.');
    } else {
        Session::setFlash('Deleted ' . $deletedCount . ' entries older than one year. This deletion is recorded at the top of the log.');
    }
    // POST/redirect/GET: refreshing the page afterwards will not press the button again
    header('Location: admin_activity_log.php', true, 303);
    exit;
}

$filters = ActivityLog::filtersFromQuery($_GET);
$results = $activityLog->search($filters);
$actorOptions = $activityLog->actorOptions();
$oldCount = $activityLog->countOlderThanRetention();
$cutoff = $activityLog->retentionCutoff();
$canDeleteOld = Access::allows($account, 'audit.delete_old');

// numbers for the "Showing 51-100 of 340" line
$firstShown = 0;
$lastShown = 0;
if ($results['total'] > 0) {
    $firstShown = ($results['page'] - 1) * ActivityLog::ENTRIES_PER_PAGE + 1;
    $lastShown = $firstShown + count($results['rows']) - 1;
}

// the address of another page of the same filtered list; http_build_query() encodes every value
function pageLink(array $filters, int $page): string {
    $query = [];
    foreach (['action', 'account', 'from', 'to'] as $field) {
        if ($filters[$field] !== '') {
            $query[$field] = $filters[$field];
        }
    }
    $query['page'] = $page;
    return 'admin_activity_log.php?' . http_build_query($query);
}

// the readable name of an action, or the code itself if a label is missing
function actionLabel(string $actionCode): string {
    if (isset(ActivityLog::ACTION_LABELS[$actionCode])) {
        return ActivityLog::ACTION_LABELS[$actionCode];
    }
    return $actionCode;
}

// what the entry was about: ['text' => ..., 'link' => address or ''] for the "Item" column
function itemFor(array $row): array {
    if ($row['entity_type'] === 'thesis') {
        if ($row['thesis_title'] === null) {
            return ['text' => 'Record #' . (int) $row['entity_id'] . ' (no longer exists)', 'link' => ''];
        }
        return ['text' => $row['thesis_title'], 'link' => 'admin_thesis.php?id=' . (int) $row['entity_id']];
    }
    if ($row['entity_type'] === 'user') {
        if ($row['affected_username'] === null) {
            return ['text' => 'Account #' . (int) $row['entity_id'] . ' (no longer exists)', 'link' => ''];
        }
        return ['text' => 'Account ' . $row['affected_username'], 'link' => 'admin_account.php?id=' . (int) $row['entity_id']];
    }
    if ($row['entity_type'] === 'report') {
        return ['text' => 'Registrar completion list', 'link' => 'admin_completion_list.php'];
    }
    if ($row['entity_type'] === 'audit_log') {
        return ['text' => 'Activity log', 'link' => ''];
    }
    if ($row['entity_type'] === 'usage_report') {
        return ['text' => 'Visits and logins report', 'link' => 'admin_usage.php'];
    }
    if ($row['entity_type'] === 'backup') {
        return ['text' => 'Backups', 'link' => 'admin_backups.php'];
    }
    return ['text' => '-', 'link' => ''];
}

// the Details column: for a permission change, "thesis.publish, report.completion_list" becomes the switch names
// "Publish and unpublish, Registrar completion list"; other entries show their field names as saved
function detailsFor(array $row): string {
    $details = (string) $row['changed_fields'];
    if ($row['action_code'] !== 'account.permissions_changed' || $details === '') {
        return $details;
    }
    $names = [];
    foreach (explode(', ', $details) as $code) {
        if (isset(Access::SWITCHABLE_PERMISSIONS[$code])) {
            $names[] = Access::SWITCHABLE_PERMISSIONS[$code]['label'];
        } else {
            $names[] = $code;
        }
    }
    return implode(', ', $names);
}

// "2026-11-09 14:05:00" -> "Nov 9, 2026 2:05 PM"
function readableDateTime(string $dateTime): string {
    return date('M j, Y g:i A', strtotime($dateTime));
}

$flashMessage = Session::takeFlash();
$flashIsError = Session::takeFlashIsError();
$csrfToken = Session::csrfToken();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity log - S.C.H.O.L.A.R.</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="assets/scholar.css" rel="stylesheet">
</head>

<body class="bg-light has-rdpo-sidebar">
<!-- the RDPO menu: a sidebar on wide screens, a Menu button on phones (shared by every RDPO page) -->
<?php require __DIR__ . '/assets/admin_sidebar.php'; ?>

<main class="container py-4">
    <h1 class="h3 mb-1">Activity log</h1>
    <p class="text-secondary small mb-3">
        Who did what and when: logins, record changes, PDF uploads and opens, downloads and account changes.
        Only the names of changed fields are kept, never their contents or any password.
    </p>

    <?php if ($flashMessage !== '' && $flashIsError): ?>
        <div class="alert alert-danger" role="alert"><?= escapeHtml($flashMessage) ?></div>
    <?php elseif ($flashMessage !== ''): ?>
        <div class="alert alert-success" role="status"><?= escapeHtml($flashMessage) ?></div>
    <?php endif; ?>

    <?php if ($canDeleteOld): ?>
        <!-- keeping one year (FB-07): the head administrator deletes older entries by hand; the deletion is logged -->
        <div class="card card-body mb-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <p class="fw-semibold mb-1">Entries older than one year</p>
                    <p class="small text-secondary mb-0" data-old-count="<?= $oldCount ?>">
                        <?php if ($oldCount === 0): ?>
                            None. Every entry is from the last year (since <?= escapeHtml(date('M j, Y', strtotime($cutoff))) ?>).
                        <?php else: ?>
                            <?= $oldCount ?> <?php if ($oldCount === 1): ?>entry is<?php else: ?>entries are<?php endif; ?>
                            from before <?= escapeHtml(date('M j, Y', strtotime($cutoff))) ?> and can be deleted.
                        <?php endif; ?>
                    </p>
                </div>
                <?php if ($oldCount > 0): ?>
                    <form method="post" action="admin_activity_log.php" class="m-0"
                          onsubmit="return confirm('Permanently delete <?= $oldCount ?> activity entries older than one year? This cannot be undone.');">
                        <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                        <input type="hidden" name="action" value="delete_old">
                        <button type="submit" class="btn btn-outline-danger">Delete entries older than one year</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- the filters use GET so a filtered view can be bookmarked; each value is checked again by filtersFromQuery() -->
    <form method="get" action="admin_activity_log.php" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label for="action" class="form-label">What</label>
                <select id="action" name="action" class="form-select">
                    <option value="">Everything</option>
                    <?php foreach (ActivityLog::ACTION_LABELS as $code => $label): ?>
                        <option value="<?= escapeHtml($code) ?>" <?php if ($filters['action'] === $code): ?>selected<?php endif; ?>><?= escapeHtml($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label for="account" class="form-label">Who</label>
                <select id="account" name="account" class="form-select">
                    <option value="">Everyone</option>
                    <?php foreach ($actorOptions as $actor): ?>
                        <option value="<?= (int) $actor['id'] ?>" <?php if ($filters['account'] === (string) $actor['id']): ?>selected<?php endif; ?>><?= escapeHtml($actor['username']) ?></option>
                    <?php endforeach; ?>
                    <option value="terminal" <?php if ($filters['account'] === 'terminal'): ?>selected<?php endif; ?>>Terminal (no account)</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="from" class="form-label">From</label>
                <input id="from" name="from" type="date" class="form-control" value="<?= escapeHtml($filters['from']) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label for="to" class="form-label">To</label>
                <input id="to" name="to" type="date" class="form-control" value="<?= escapeHtml($filters['to']) ?>">
            </div>
            <div class="col-12 col-md-1">
                <button type="submit" class="btn btn-primary w-100">Show</button>
            </div>
        </div>
        <div class="mt-2"><a href="admin_activity_log.php" class="small">Clear filters</a></div>
    </form>

    <?php if ($results['total'] === 0): ?>
        <div class="alert alert-secondary">No activity matches these filters.</div>
    <?php else: ?>
        <p class="small text-secondary mb-2" role="status">Showing <?= $firstShown ?>-<?= $lastShown ?> of <?= $results['total'] ?></p>
        <div class="table-responsive">
            <table class="table table-sm table-striped bg-white align-middle">
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Who</th>
                        <th scope="col">What</th>
                        <th scope="col" class="d-none d-md-table-cell">Item</th>
                        <th scope="col" class="d-none d-lg-table-cell">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results['rows'] as $row): ?>
                        <?php $item = itemFor($row); ?>
                        <tr>
                            <td class="small text-nowrap"><?= escapeHtml(readableDateTime($row['created_at'])) ?></td>
                            <td class="small text-break">
                                <?php if ($row['actor_username'] === null): ?>
                                    <span class="text-secondary">terminal</span>
                                <?php else: ?>
                                    <?= escapeHtml($row['actor_username']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= escapeHtml(actionLabel($row['action_code'])) ?></td>
                            <td class="small d-none d-md-table-cell">
                                <?php if ($item['link'] !== ''): ?>
                                    <a href="<?= escapeHtml($item['link']) ?>"><?= escapeHtml($item['text']) ?></a>
                                <?php else: ?>
                                    <?= escapeHtml($item['text']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="small text-secondary d-none d-lg-table-cell"><?= escapeHtml(detailsFor($row)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($results['pages'] > 1): ?>
            <nav aria-label="Activity log pages">
                <ul class="pagination pagination-sm flex-wrap">
                    <?php if ($results['page'] > 1): ?>
                        <li class="page-item"><a class="page-link" href="<?= escapeHtml(pageLink($filters, $results['page'] - 1)) ?>">Newer</a></li>
                    <?php endif; ?>
                    <li class="page-item disabled"><span class="page-link">Page <?= $results['page'] ?> of <?= $results['pages'] ?></span></li>
                    <?php if ($results['page'] < $results['pages']): ?>
                        <li class="page-item"><a class="page-link" href="<?= escapeHtml(pageLink($filters, $results['page'] + 1)) ?>">Older</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>

</html>
