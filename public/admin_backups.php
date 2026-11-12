<?php
declare(strict_types=1); // keep typed values strict

use Scholar\AuditLog;
use Scholar\Auth\Access;
use Scholar\Auth\Session;
use Scholar\Backup;

// Backups (M12, FB-04, provisional until RDPO says who handles backups): the head administrator makes a backup of
// the database and the stored PDFs, sees the recent ones and downloads one to copy to another drive
// restoring is NOT on this page: it replaces all data, so it is a terminal command (bin/console.php restore-backup)
// admin_backups.php                         -> the page
// admin_backups.php?download=<backup file>  -> the backup file itself
require_once __DIR__ . '/../app/bootstrap.php';
Session::start();
$pdo = connectDatabase();
$account = Access::requirePermission($pdo, 'backup.manage');

$requestMethod = $_SERVER['REQUEST_METHOD'];
if ($requestMethod !== 'GET' && $requestMethod !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$backup = Backup::create($pdo);

// ---- "Make a backup now" ----
if ($requestMethod === 'POST') {
    $submittedToken = null;
    if (isset($_POST['csrf_token'])) {
        $submittedToken = $_POST['csrf_token'];
    }
    if (!Session::verifyCsrf($submittedToken)) {
        http_response_code(403); // this is for preventing CSRF attacks
        exit('The form expired. Return to the backups page and try again.');
    }
    if (!isset($_POST['action']) || $_POST['action'] !== 'make_backup') {
        http_response_code(422);
        exit('Unknown action.');
    }
    // copying many PDFs can take longer than PHP's usual 30-second limit, so lift it for this request only
    set_time_limit(0);
    // the session file is released while the backup runs, so the same person's other tabs are not stuck waiting
    session_write_close();
    $made = $backup->makeBackup();
    AuditLog::record($pdo, $account['id'], 'backup.created', 'backup', null, [$made['file']]);
    session_start(); // reopen it to leave the message for the next page
    Session::setFlash('Backup made: ' . $made['file'] . ' (' . $made['pdf_count'] . ' PDFs). Download it and keep a copy on another drive.');
    header('Location: admin_backups.php', true, 303);
    exit;
}

// ---- the download: sent instead of the page, then stop ----
if (isset($_GET['download'])) {
    $path = null;
    if (is_string($_GET['download'])) {
        // pathOf() only accepts our own backup file names, so "../.env" or any other file is refused
        $path = $backup->pathOf($_GET['download']);
    }
    if ($path === null) {
        http_response_code(404);
        exit('Backup not found.');
    }
    // a backup holds every record and every staff account's password hash, so each download is logged
    AuditLog::record($pdo, $account['id'], 'backup.downloaded', 'backup', null, [basename($path)]);
    session_write_close();
    set_time_limit(0);
    // empty PHP's output buffers so a large file is streamed straight to the browser instead of held in memory
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/x-tar');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path); // readfile() sends the file in pieces
    exit;
}

$backups = $backup->listBackups();
$backupDirectory = $backup->backupDirectory();
// true when backups are still in the default folder inside the application, i.e. on the same drive as the data
$isDefaultFolder = realpath($backupDirectory) === realpath(__DIR__ . '/../storage/backups');

// a reminder when there is no backup yet, or the newest one is more than a week old (provisional, until RDPO decides)
$daysSinceNewest = null;
if (count($backups) > 0) {
    $daysSinceNewest = (int) floor((time() - $backups[0]['made_at']) / 86400);
}

// 3883008 -> "3.7 MB"
function readableSize(int $bytes): string {
    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 1) . ' GB';
    }
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    return max(1, (int) round($bytes / 1024)) . ' KB';
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
    <title>Backups - S.C.H.O.L.A.R.</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="assets/scholar.css" rel="stylesheet">
</head>

<body class="bg-light has-rdpo-sidebar">
<!-- the RDPO menu: a sidebar on wide screens, a Menu button on phones (shared by every RDPO page) -->
<?php require __DIR__ . '/assets/admin_sidebar.php'; ?>

<main class="container py-4" style="max-width: 56rem;">
    <h1 class="h3 mb-1">Backups</h1>

    <?php if ($flashMessage !== '' && $flashIsError): ?>
        <div class="alert alert-danger" role="alert"><?= escapeHtml($flashMessage) ?></div>
    <?php elseif ($flashMessage !== ''): ?>
        <div class="alert alert-success" role="status"><?= escapeHtml($flashMessage) ?></div>
    <?php endif; ?>

    <?php if ($daysSinceNewest === null): ?>
        <div class="alert alert-warning" role="status">There is no backup yet. Make one now.</div>
    <?php elseif ($daysSinceNewest > 7): ?>
        <div class="alert alert-warning" role="status">The newest backup is <?= $daysSinceNewest ?> days old. Make a new one.</div>
    <?php endif; ?>

    <div class="card card-body mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <p class="fw-semibold mb-1">Make a backup now</p>
                <p class="small text-secondary mb-0">This can take a while when there are many PDFs. Keep this page open until it finishes.</p>
            </div>
            <!-- the button is disabled after the first click so a slow backup is not started twice -->
            <form method="post" action="admin_backups.php" class="m-0" onsubmit="this.querySelector('button').disabled = true;">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                <input type="hidden" name="action" value="make_backup">
                <button type="submit" class="btn btn-primary">Make a backup now</button>
            </form>
        </div>
    </div>

    <?php if ($isDefaultFolder): ?>
        <!-- <div class="alert alert-info small">
            Backups are saved on the same computer and drive as the system, so a broken drive would lose both.
            Download each backup and keep the copy on another drive, or ask IT to set <code>BACKUP_DIRECTORY</code>
            to another drive or network folder.
        </div> -->
    <?php else: ?>
        <p class="small text-secondary">Backups are saved in <code class="text-break"><?= escapeHtml($backupDirectory) ?></code>.</p>
    <?php endif; ?>

    <h2 class="h5">Recent backups</h2>
    <?php if (count($backups) === 0): ?>
        <p class="text-secondary">None yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-striped bg-white align-middle">
                <thead>
                    <tr>
                        <th scope="col">Made</th>
                        <th scope="col">Size</th>
                        <th scope="col" class="d-none d-md-table-cell">File</th>
                        <th scope="col"><span class="visually-hidden">Download</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $item): ?>
                        <tr>
                            <td class="small text-nowrap"><?= escapeHtml(date('M j, Y g:i A', $item['made_at'])) ?></td>
                            <td class="small text-nowrap"><?= escapeHtml(readableSize($item['size'])) ?></td>
                            <td class="small text-break d-none d-md-table-cell"><?= escapeHtml($item['file']) ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="admin_backups.php?<?= escapeHtml(http_build_query(['download' => $item['file']])) ?>">Download</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h2 class="h5 mt-4">Restoring a backup</h2>
    <p class="small">
        Restoring replaces every record, account and history entry with the backup's contents, so it is done from the
        terminal on the server by whoever maintains it, not from this page. The command checks the file first and makes
        a fresh backup of the current state before replacing anything. It also needs a database administrator account
        (<code>SCHOLAR_DB_ADMIN_USER</code> and <code>SCHOLAR_DB_ADMIN_PASSWORD</code>, set only for that command), because
        the system's own database account is not allowed to delete tables:
    </p>
    <pre class="small bg-white border rounded p-2 text-wrap"><code>php bin/console.php restore-backup scholar_backup_....tar --confirm</code></pre>
</main>
</body>

</html>
