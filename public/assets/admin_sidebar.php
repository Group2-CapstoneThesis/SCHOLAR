<?php
// the RDPO menu, shared by every RDPO page (user's request, 2026-11-09: the top bar had become crowded)
// on wide screens it is a fixed sidebar on the left; on phones and small windows it is hidden behind a "Menu" button
// and slides in from the left (Bootstrap "offcanvas-lg")
// a page includes it right after <body class="bg-light has-rdpo-sidebar"> with a one-line PHP block:
//     require __DIR__ . '/assets/admin_sidebar.php';
// (the PHP closing tag is not written in this comment, because inside a // comment it would end PHP mode)
// it expects the page's $account (from Access::requirePermission() or Session::requireAuthentication()) and $csrfToken
// it lives in public/assets (user's request, 2026-11-10; it was in templates/ before), which the browser can reach,
// so it is guarded twice against being opened on its own: public/assets/.htaccess refuses every .php file there,
// and the check below answers "not found" if this file is ever the page that was requested
// each link appears only when the account may use that page (Access::allows()); the pages still check again themselves

use Scholar\Auth\Access;

// SCRIPT_FILENAME is the file the browser asked for; when that is this file itself, nothing here may run
$requestedFile = '';
if (isset($_SERVER['SCRIPT_FILENAME']) && is_string($_SERVER['SCRIPT_FILENAME'])) {
    $requestedFile = realpath($_SERVER['SCRIPT_FILENAME']);
}
if ($requestedFile === __FILE__) {
    http_response_code(404);
    exit;
}

// which page is open, so its link can be highlighted; basename() keeps only "admin_thesis.php" from the full path
$currentScript = basename($_SERVER['SCRIPT_NAME']);
$isNewRecord = $currentScript === 'admin_thesis_form.php' && !isset($_GET['id']);
// the menu entries in their groups; [link, label, permission, is this the open page]
$menuGroups = [
    'Thesis records' => [
        ['admin.php', 'Records', 'thesis.view_all', in_array($currentScript, ['admin.php', 'admin_thesis.php'], true) || ($currentScript === 'admin_thesis_form.php' && !$isNewRecord)],
        ['admin_thesis_form.php', 'New record', 'thesis.create', $isNewRecord],
    ],
    'Reports' => [
        ['admin_completion_list.php', 'Completion list', 'report.completion_list', $currentScript === 'admin_completion_list.php'],
        ['admin_analytics.php', 'Analytics', 'report.analytics', $currentScript === 'admin_analytics.php'],
    ],
    'Administration' => [
        ['admin_accounts.php', 'Staff accounts', 'account.manage', in_array($currentScript, ['admin_accounts.php', 'admin_account.php', 'admin_account_form.php'], true)],
        ['admin_activity_log.php', 'Activity log', 'audit.view', $currentScript === 'admin_activity_log.php'],
        ['admin_usage.php', 'Visits and logins', 'report.usage', $currentScript === 'admin_usage.php'],
        ['admin_backups.php', 'Backups', 'backup.manage', $currentScript === 'admin_backups.php'],
    ],
];
// while the password is temporary every other page sends the person back to "My password", so only Log out is offered;
// the same while a head administrator still has to link an authenticator app (Two-step login)
$showMenuLinks = !$account['must_change_password'] && !$account['must_set_up_two_factor'];
?>
<style>
    /* on wide screens (992px and up) the sidebar is fixed on the left and the page moves right to make room */
    @media (min-width: 992px) {
        body.has-rdpo-sidebar { padding-left: 15rem; }
        .rdpo-sidebar { position: fixed; top: 0; bottom: 0; left: 0; width: 15rem; overflow-y: auto; }
    }
    .rdpo-sidebar .nav-link { color: rgba(255, 255, 255, 0.8); border-radius: 0.375rem; }
    .rdpo-sidebar .nav-link:hover, .rdpo-sidebar .nav-link:focus { color: #fff; background-color: rgba(255, 255, 255, 0.1); }
    .rdpo-sidebar .nav-link.active { color: #fff; background-color: var(--bs-primary); }
</style>

<!-- phones and small windows: a slim bar with the name and a Menu button (hidden on wide screens) -->
<div class="navbar navbar-dark bg-dark d-lg-none px-3">
    <a class="navbar-brand" href="admin.php">S.C.H.O.L.A.R. RDPO</a>
    <button class="btn btn-sm btn-outline-light" type="button" data-bs-toggle="offcanvas" data-bs-target="#rdpoMenu" aria-controls="rdpoMenu">
        Menu
    </button>
</div>

<!-- the menu itself: "offcanvas-lg" is a slide-in panel below 992px and an ordinary block from 992px up -->
<nav class="rdpo-sidebar offcanvas-lg offcanvas-start text-bg-dark" tabindex="-1" id="rdpoMenu" aria-labelledby="rdpoMenuTitle">
    <div class="offcanvas-header">
        <span class="h5 mb-0" id="rdpoMenuTitle">Menu</span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#rdpoMenu" aria-label="Close menu"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column p-3 h-100">
        <a class="d-none d-lg-block text-white text-decoration-none fs-5 mb-3" href="admin.php">S.C.H.O.L.A.R. RDPO</a>

        <?php if ($showMenuLinks): ?>
            <?php foreach ($menuGroups as $groupName => $entries): ?>
                <?php
                // only the entries this account may open; a group with none is left out entirely
                $allowedEntries = [];
                foreach ($entries as $entry) {
                    if (Access::allows($account, $entry[2])) {
                        $allowedEntries[] = $entry;
                    }
                }
                ?>
                <?php if (count($allowedEntries) > 0): ?>
                    <p class="text-uppercase text-white-50 small fw-semibold mb-1 mt-2"><?= escapeHtml($groupName) ?></p>
                    <ul class="nav nav-pills flex-column mb-2">
                        <?php foreach ($allowedEntries as $entry): ?>
                            <li class="nav-item">
                                <!-- aria-current tells screen readers which page is open -->
                                <a href="<?= escapeHtml($entry[0]) ?>" class="nav-link py-1<?php if ($entry[3]): ?> active<?php endif; ?>"
                                   <?php if ($entry[3]): ?>aria-current="page"<?php endif; ?>><?= escapeHtml($entry[1]) ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endforeach; ?>
            <ul class="nav nav-pills flex-column mb-2">
                <li class="nav-item"><a href="index.php" class="nav-link py-1">Public search</a></li>
            </ul>
        <?php endif; ?>

        <!-- mt-auto pushes the account part to the bottom of the sidebar -->
        <div class="mt-auto pt-3 border-top border-secondary">
            <p class="small text-white-50 mb-2 text-break">Signed in as <?= escapeHtml($account['username']) ?></p>
            <?php if ($showMenuLinks): ?>
                <a href="admin_password.php" class="nav-link px-0 py-1 mb-2<?php if ($currentScript === 'admin_password.php'): ?> text-white fw-semibold<?php endif; ?>"
                   <?php if ($currentScript === 'admin_password.php'): ?>aria-current="page"<?php endif; ?>>My password</a>
            <?php endif; ?>
            <?php if (!$account['must_change_password']): ?>
                <a href="admin_two_factor.php" class="nav-link px-0 py-1 mb-2<?php if ($currentScript === 'admin_two_factor.php'): ?> text-white fw-semibold<?php endif; ?>"
                   <?php if ($currentScript === 'admin_two_factor.php'): ?>aria-current="page"<?php endif; ?>>Two-factor authentication</a>
            <?php endif; ?>
            <form method="post" action="logout.php" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                <button type="submit" class="btn btn-sm btn-light w-100">Log out</button>
            </form>
        </div>
    </div>
</nav>

<!-- Bootstrap's JavaScript, needed only to open and close the menu on phones; "defer" lets the page show first -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
