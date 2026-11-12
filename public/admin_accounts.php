<?php
declare(strict_types=1); // keep typed values strict

use Scholar\Auth\Access;
use Scholar\Auth\Session;
use Scholar\Auth\StaffAccounts;

// the list of RDPO staff accounts (M12); only the head administrator may open it
// (rdpo_head is provisional until RDPO confirms a head-administrator level, FB-07)
require_once __DIR__ . '/../app/bootstrap.php';
Session::start();
$pdo = connectDatabase();
$account = Access::requirePermission($pdo, 'account.manage');

// this page only reads; the changes happen on admin_account_form.php and admin_account.php
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$staffAccounts = new StaffAccounts($pdo);
$accounts = $staffAccounts->listAccounts();

// "2026-11-09 14:05:00" -> "Nov 9, 2026 2:05 PM"; "Never" when the account has not logged in yet
function readableDateTime(?string $dateTime, string $whenEmpty): string {
    if ($dateTime === null || $dateTime === '') {
        return $whenEmpty;
    }
    return date('M j, Y g:i A', strtotime($dateTime));
}

$flashMessage = Session::takeFlash();
$flashIsError = Session::takeFlashIsError();
$csrfToken = Session::csrfToken(); // token for the logout form
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff accounts - S.C.H.O.L.A.R.</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="assets/scholar.css" rel="stylesheet">
</head>

<body class="bg-light has-rdpo-sidebar">
<!-- the RDPO menu: a sidebar on wide screens, a Menu button on phones (shared by every RDPO page) -->
<?php require __DIR__ . '/assets/admin_sidebar.php'; ?>

<main class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">Staff accounts</h1>
        <a href="admin_account_form.php" class="btn btn-primary">New staff account</a>
    </div>

    <?php if ($flashMessage !== '' && $flashIsError): ?>
        <div class="alert alert-danger" role="alert"><?= escapeHtml($flashMessage) ?></div>
    <?php elseif ($flashMessage !== ''): ?>
        <div class="alert alert-success" role="status"><?= escapeHtml($flashMessage) ?></div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-sm table-striped bg-white align-middle">
            <thead>
                <tr>
                    <th scope="col">Username</th>
                    <th scope="col">Role</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="d-none d-lg-table-cell">Last login</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $staffMember): ?>
                    <tr>
                        <td class="text-break"><!-- long usernames may break onto two lines on a phone -->
                            <a href="admin_account.php?id=<?= (int) $staffMember['id'] ?>"><?= escapeHtml($staffMember['username']) ?></a>
                            <?php if ((int) $staffMember['id'] === $account['id']): ?>
                                <span class="small text-secondary">(you)</span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= escapeHtml($staffMember['role_label']) ?></td>
                        <td>
                            <?php if ((int) $staffMember['is_active'] !== 1): ?>
                                <span class="badge text-bg-secondary">Disabled</span>
                            <?php elseif ((int) $staffMember['must_change_password'] === 1): ?>
                                <!-- text-wrap lets this longer badge use two lines on a phone instead of widening the table -->
                                <span class="badge text-bg-warning text-wrap">Temporary password</span>
                            <?php else: ?>
                                <span class="badge text-bg-success">Active</span>
                            <?php endif; ?>
                        </td>
                        <td class="small d-none d-lg-table-cell"><?= escapeHtml(readableDateTime($staffMember['last_login_at'], 'Never')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>
</body>

</html>
