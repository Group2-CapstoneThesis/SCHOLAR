<?php
declare(strict_types=1); // keep typed values strict

use Scholar\AuditLog;
use Scholar\Auth\Access;
use Scholar\Auth\Session;
use Scholar\Auth\StaffAccounts;
use Scholar\Auth\TwoFactor;

// one staff account (M12): its details and history, and the head administrator's buttons to change its role or username,
// switch single permissions off or on, reset a forgotten password or a lost authenticator app, or disable / enable it
require_once __DIR__ . '/../app/bootstrap.php';
Session::start();
$pdo = connectDatabase();
$account = Access::requirePermission($pdo, 'account.manage');

$requestMethod = $_SERVER['REQUEST_METHOD'];
if ($requestMethod !== 'GET' && $requestMethod !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

// digits only; anything else is treated as an account that does not exist
$accountId = 0;
if (isset($_GET['id']) && is_string($_GET['id']) && preg_match('/\A[1-9][0-9]{0,18}\z/', $_GET['id'])) {
    $accountId = (int) $_GET['id'];
}
$staffAccounts = new StaffAccounts($pdo);
$staffMember = null;
if ($accountId > 0) {
    $staffMember = $staffAccounts->findAccount($accountId);
}
if ($staffMember === null) {
    http_response_code(404);
    exit('Account not found.');
}
$isSelf = $accountId === $account['id'];
$roleOptions = $staffAccounts->roleOptions();
$usernameError = '';
$typedUsername = $staffMember['username'];
// your own username, and those of RDPO administrators; not another head administrator's (user's request, 2026-11-10)
$mayChangeUsername = StaffAccounts::mayChangeUsername($staffMember, $account['id']);

if ($requestMethod === 'POST') {
    $submittedToken = null;
    if (isset($_POST['csrf_token'])) {
        $submittedToken = $_POST['csrf_token'];
    }
    if (!Session::verifyCsrf($submittedToken)) {
        http_response_code(403); // this is for preventing CSRF attacks
        exit('The form expired. Return to the account page and try again.');
    }

    // each form on this page sends one of these action names; anything else is refused
    $action = '';
    if (isset($_POST['action']) && is_string($_POST['action'])) {
        $action = $_POST['action'];
    }
    $allowedActions = ['change_role', 'change_username', 'change_permissions', 'reset_password', 'reset_two_factor', 'disable', 'enable'];
    if (!in_array($action, $allowedActions, true)) {
        http_response_code(422);
        exit('Unknown action.');
    }

    // what to tell the head administrator after each named result
    $resultMessages = [
        'changed' => 'Saved.',
        'unchanged' => 'Nothing changed.',
        'self' => 'You cannot do this to your own account. Ask another head administrator.',
        'unknown_role' => 'Choose one of the listed roles.',
        'not_found' => 'Account not found.',
        'disabled' => 'Account disabled. If the person is logged in, they are signed out on their next click.',
        'enabled' => 'Account enabled. The person can log in again with their existing password.',
    ];
    $result = '';
    $showFormAgain = false;

    if ($action === 'change_role') {
        $roleCode = '';
        if (isset($_POST['role_code']) && is_string($_POST['role_code'])) {
            $roleCode = $_POST['role_code'];
        }
        $result = $staffAccounts->changeRole($accountId, $roleCode, $account['id']);
        if ($result === 'changed') {
            $resultMessages['changed'] = 'Role changed. It applies from the person\'s next click.';
        }
    } elseif ($action === 'change_username') {
        if (isset($_POST['username']) && is_string($_POST['username'])) {
            $typedUsername = trim($_POST['username']);
        } else {
            $typedUsername = '';
        }
        $usernameError = StaffAccounts::usernameProblem($typedUsername);
        if ($usernameError === '') {
            $result = $staffAccounts->changeUsername($accountId, $typedUsername, $account['id']);
            if ($result === 'taken') {
                $usernameError = 'This username is already used by another account.';
            }
            if ($result === 'other_head') {
                $resultMessages['other_head'] = 'Another head administrator\'s username can only be changed by that person.';
            }
            if ($result === 'changed') {
                $resultMessages['changed'] = 'Username changed. It is used from the next login.';
            }
        }
        if ($usernameError !== '') {
            $showFormAgain = true; // show the page with the typed username and the message beside the field
        }
    } elseif ($action === 'change_permissions') {
        // only the switches that are ON arrive; switchedOnFromForm() drops anything that is not a real switch
        $switchedOn = StaffAccounts::switchedOnFromForm($_POST);
        $result = $staffAccounts->savePermissions($accountId, $switchedOn, $account['id']);
        if ($result === 'changed') {
            $resultMessages['changed'] = 'Permissions saved.';
        }
    } elseif ($action === 'reset_password') {
        $reset = $staffAccounts->resetPassword($accountId, $account['id']);
        $result = $reset['result'];
        if ($result === 'reset') {
            // shown once below, after the redirect, and then removed from the session
            $_SESSION['temporary_password'] = ['account_id' => $accountId, 'password' => $reset['temporary_password']];
            $resultMessages['reset'] = 'Password reset. The person was signed out everywhere.';
        }
    } elseif ($action === 'reset_two_factor') {
        // for someone who lost the phone with their authenticator app (user's request, 2026-11-10);
        // a head administrator is then asked to link a new app on their next click, an RDPO administrator may
        // log in with the password alone until they link one again
        if ($accountId === $account['id']) {
            $result = 'self';
        } else {
            $twoFactor = new TwoFactor($pdo);
            $twoFactor->turnOff($accountId, $account['id'], 'account.two_factor_reset');
            $result = 'two_factor_reset';
            $resultMessages['two_factor_reset'] = '2FA reset. The person links an authenticator app again.';
        }
    } elseif ($action === 'disable') {
        $result = $staffAccounts->setActive($accountId, false, $account['id']);
    } else {
        $result = $staffAccounts->setActive($accountId, true, $account['id']);
    }

    if (!$showFormAgain) {
        $isError = in_array($result, ['self', 'unknown_role', 'not_found', 'taken', 'other_head'], true);
        Session::setFlash($resultMessages[$result], $isError);
        // POST/redirect/GET: refreshing the page afterwards will not press the button again
        header('Location: admin_account.php?id=' . $accountId, true, 303);
        exit;
    }
    http_response_code(422);
}

// the temporary password from "Create account" or "Reset password", shown ONCE and then forgotten
// it is removed even when it belongs to another account, so it never stays in the session longer than one page view
$temporaryPassword = '';
if (isset($_SESSION['temporary_password']) && is_array($_SESSION['temporary_password'])) {
    $waiting = $_SESSION['temporary_password'];
    if (isset($waiting['account_id'], $waiting['password']) && $waiting['account_id'] === $accountId) {
        $temporaryPassword = $waiting['password'];
    }
    unset($_SESSION['temporary_password']);
}

$history = AuditLog::historyFor($pdo, 'user', $accountId, 20); // newest 20 events about this account
// readable names for the history list; the database keeps the fixed machine codes
$actionLabels = [
    'auth.login' => 'Logged in',
    'auth.logout' => 'Logged out',
    'account.created' => 'Account created',
    'account.role_changed' => 'Role changed',
    'account.email_changed' => 'Email changed', // older entries only: emails were removed on 2026-11-10
    'account.username_changed' => 'Username changed',
    'account.password_reset' => 'Password reset by a head administrator',
    'account.password_changed' => 'Changed own password',
    'account.permissions_changed' => 'Permissions changed',
    'account.disabled' => 'Disabled',
    'account.enabled' => 'Enabled',
    'account.two_factor_enabled' => '2FA turned on',
    'account.two_factor_disabled' => '2FA turned off',
    'account.two_factor_reset' => '2FA reset by a head administrator',
    'account.backup_codes_generated' => 'New backup codes made',
    'account.remembered_devices_forgotten' => 'Remembered devices forgotten',
    'account.emergency_access' => 'Emergency access from the terminal',
    'auth.backup_code_used' => 'Logged in with a backup code',
];
foreach ($history as $index => $event) {
    $history[$index]['label'] = $event['action_code'];
    if (isset($actionLabels[$event['action_code']])) {
        $history[$index]['label'] = $actionLabels[$event['action_code']];
    }
}

// "2026-11-09 14:05:00" -> "Nov 9, 2026 2:05 PM"
function readableDateTime(?string $dateTime, string $whenEmpty): string {
    if ($dateTime === null || $dateTime === '') {
        return $whenEmpty;
    }
    return date('M j, Y g:i A', strtotime($dateTime));
}

$isActive = (int) $staffMember['is_active'] === 1;

// the permission switches: a switch is ON when the role allows the permission and it was not switched off
$revokedPermissions = $staffAccounts->revokedPermissions($accountId);
$switches = [];
foreach (Access::SWITCHABLE_PERMISSIONS as $code => $text) {
    $switchIsOn = false;
    if (Access::roleAllows($staffMember['role_code'], $code) && !in_array($code, $revokedPermissions, true)) {
        $switchIsOn = true;
    }
    $switches[] = ['code' => $code, 'label' => $text['label'], 'help' => $text['help'], 'is_on' => $switchIsOn];
}
$isStaffRole = in_array($staffMember['role_code'], Access::staffRoleCodes(), true);
$managesAccounts = Access::roleAllows($staffMember['role_code'], 'account.manage');
$flashMessage = Session::takeFlash();
$flashIsError = Session::takeFlashIsError();
$csrfToken = Session::csrfToken();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escapeHtml($staffMember['username']) ?> - Staff accounts - S.C.H.O.L.A.R.</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="assets/scholar.css" rel="stylesheet">
</head>

<body class="bg-light has-rdpo-sidebar">
<!-- the RDPO menu: a sidebar on wide screens, a Menu button on phones (shared by every RDPO page) -->
<?php require __DIR__ . '/assets/admin_sidebar.php'; ?>

<main class="container py-4" style="max-width: 52rem;">
    <h1 class="h3 mb-1"><?= escapeHtml($staffMember['username']) ?></h1>
    <p class="text-secondary mb-3"><?= escapeHtml($staffMember['role_label']) ?></p>

    <?php if ($flashMessage !== '' && $flashIsError): ?>
        <div class="alert alert-danger" role="alert"><?= escapeHtml($flashMessage) ?></div>
    <?php elseif ($flashMessage !== ''): ?>
        <div class="alert alert-success" role="status"><?= escapeHtml($flashMessage) ?></div>
    <?php endif; ?>

    <?php if ($temporaryPassword !== ''): ?>
        <!-- shown only on this one page view; reloading the page will not show it again -->
        <div class="alert alert-warning" role="status">
            <p class="mb-1"><strong>Temporary password for <?= escapeHtml($staffMember['username']) ?>:</strong></p>
            <p class="fs-4 font-monospace mb-2 user-select-all" data-temporary-password><?= escapeHtml($temporaryPassword) ?></p>
            <p class="small mb-0">
                Please give this temporary password to the user immediately as it will not be shown again. They must choose their own password when they first log in. If it is lost, use "Reset password" to make a new one.
            </p>
        </div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-4">Status</dt>
                <dd class="col-sm-8">
                    <?php if (!$isActive): ?>
                        Disabled since <?= escapeHtml(readableDateTime($staffMember['disabled_at'], '-')) ?>
                    <?php elseif ((int) $staffMember['must_change_password'] === 1): ?>
                        Active, waiting for the person to replace the temporary password
                    <?php else: ?>
                        Active
                    <?php endif; ?>
                </dd>
                <dt class="col-sm-4">2FA</dt>
                <dd class="col-sm-8">
                    <?php if ($staffMember['totp_enabled_at'] !== null): ?>
                        On since <?= escapeHtml(readableDateTime($staffMember['totp_enabled_at'], '-')) ?>
                    <?php elseif (TwoFactor::isRequiredFor($staffMember['role_code'])): ?>
                        Not linked yet; required for this role, so it is asked at the next login
                    <?php else: ?>
                        Off
                    <?php endif; ?>
                </dd>
                <dt class="col-sm-4">Created</dt>
                <dd class="col-sm-8"><?= escapeHtml(readableDateTime($staffMember['created_at'], '-')) ?></dd>
                <dt class="col-sm-4">Last login</dt>
                <dd class="col-sm-8"><?= escapeHtml(readableDateTime($staffMember['last_login_at'], 'Never')) ?></dd>
            </dl>
        </div>
    </div>

    <?php if ($isSelf): ?>
        <div class="alert alert-secondary">
            This is your own account. Another head administrator must change its role, reset its password or disable it.
            You can change your own password on <a href="admin_password.php">My password</a>.
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <?php if (!$isSelf): ?>
            <div class="col-12 col-md-6">
                <form method="post" action="admin_account.php?id=<?= $accountId ?>" class="card card-body h-100">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                    <input type="hidden" name="action" value="change_role">
                    <label for="role_code" class="form-label fw-semibold">Role</label>
                    <select id="role_code" name="role_code" class="form-select mb-2">
                        <?php foreach ($roleOptions as $code => $label): ?>
                            <option value="<?= escapeHtml($code) ?>" <?php if ($code === $staffMember['role_code']): ?>selected<?php endif; ?>><?= escapeHtml($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div><button type="submit" class="btn btn-outline-primary">Change role</button></div>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($mayChangeUsername): ?>
            <div class="col-12 col-md-6">
                <form method="post" action="admin_account.php?id=<?= $accountId ?>" class="card card-body h-100" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                    <input type="hidden" name="action" value="change_username">
                    <label for="username" class="form-label fw-semibold">Username</label>
                    <input id="username" name="username" type="text" maxlength="64" autocomplete="off"
                           class="form-control mb-2<?php if ($usernameError !== ''): ?> is-invalid<?php endif; ?>"
                           value="<?= escapeHtml($typedUsername) ?>"
                           aria-describedby="username_help<?php if ($usernameError !== ''): ?> username_error<?php endif; ?>">
                    <?php if ($usernameError !== ''): ?>
                        <div class="invalid-feedback d-block mt-n1 mb-2" id="username_error"><?= escapeHtml($usernameError) ?></div>
                    <?php endif; ?>
                    <div class="form-text mt-0 mb-2" id="username_help">3 to 64 letters, digits, underscores, dots or hyphens. The person logs in with the new one.</div>
                    <div><button type="submit" class="btn btn-outline-primary">Change username</button></div>
                </form>
            </div>
        <?php endif; ?>

        <?php if (!$isSelf): ?>
            <div class="col-12 col-md-6">
                <form method="post" action="admin_account.php?id=<?= $accountId ?>" class="card card-body h-100"
                      onsubmit="return confirm('Make a new temporary password for this account and sign it out everywhere?');">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                    <input type="hidden" name="action" value="reset_password">
                    <p class="fw-semibold mb-1">Forgotten password</p>
                    <p class="small text-secondary">Makes a new temporary password and signs the person out everywhere.</p>
                    <div><button type="submit" class="btn btn-outline-warning">Reset password</button></div>
                </form>
            </div>

            <?php if ($staffMember['totp_enabled_at'] !== null): ?>
                <div class="col-12 col-md-6">
                    <form method="post" action="admin_account.php?id=<?= $accountId ?>" class="card card-body h-100"
                          onsubmit="return confirm('Unlink the authenticator app of this account? Their backup codes and remembered devices stop working too.');">
                        <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                        <input type="hidden" name="action" value="reset_two_factor">
                        <p class="fw-semibold mb-1">Lost authenticator app</p>
                        <p class="small text-secondary">Unlinks the app, so the person can link a new phone. Their backup codes and remembered devices stop working.</p>
                        <div><button type="submit" class="btn btn-outline-warning">Reset 2FA</button></div>
                    </form>
                </div>
            <?php endif; ?>

            <div class="col-12 col-md-6">
                <form method="post" action="admin_account.php?id=<?= $accountId ?>" class="card card-body h-100"
                      <?php if ($isActive): ?>onsubmit="return confirm('Disable this account? The person will be signed out.');"<?php endif; ?>>
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                    <?php if ($isActive): ?>
                        <input type="hidden" name="action" value="disable">
                        <p class="fw-semibold mb-1">Disable</p>
                        <p class="small text-secondary">Stops this account from logging in. Nothing is deleted.</p>
                        <div><button type="submit" class="btn btn-outline-danger">Disable account</button></div>
                    <?php else: ?>
                        <input type="hidden" name="action" value="enable">
                        <p class="fw-semibold mb-1">Enable</p>
                        <p class="small text-secondary">Lets this account log in again with its existing password.</p>
                        <div><button type="submit" class="btn btn-outline-success">Enable account</button></div>
                    <?php endif; ?>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($isStaffRole): ?>
        <!-- permission switches: the role sets the most this account may do; a switch turns one permission off or back on -->
        <form method="post" action="admin_account.php?id=<?= $accountId ?>" class="card mb-4">
            <div class="card-body">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                <input type="hidden" name="action" value="change_permissions">
                <h2 class="h5 mb-1">Permissions</h2>
                <p class="small text-secondary mb-3">
                    Switch off what this person should not do. Changes apply from their next click.
                </p>
                <!-- fieldset disabled greys out every switch at once on your own account -->
                <fieldset <?php if ($isSelf): ?>disabled<?php endif; ?>>
                    <legend class="visually-hidden">Permissions for <?= escapeHtml($staffMember['username']) ?></legend>
                    <!-- the two permissions that cannot be switched, shown as fixed so the list is complete -->
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="permission_view_all" checked disabled
                               aria-describedby="permission_view_all_help">
                        <label class="form-check-label" for="permission_view_all">View all records</label>
                        <div class="form-text mt-0" id="permission_view_all_help">Every staff account has this.</div>
                    </div>
                    <?php foreach ($switches as $switch): ?>
                        <?php $inputId = 'permission_' . str_replace('.', '_', $switch['code']); ?>
                        <div class="form-check form-switch mb-2">
                            <!-- role="switch" lets screen readers announce it as on/off instead of checked/unchecked -->
                            <input class="form-check-input" type="checkbox" role="switch" name="permissions[]"
                                   id="<?= escapeHtml($inputId) ?>" value="<?= escapeHtml($switch['code']) ?>"
                                   aria-describedby="<?= escapeHtml($inputId) ?>_help"
                                   <?php if ($switch['is_on']): ?>checked<?php endif; ?>>
                            <label class="form-check-label" for="<?= escapeHtml($inputId) ?>"><?= escapeHtml($switch['label']) ?></label>
                            <div class="form-text mt-0" id="<?= escapeHtml($inputId) ?>_help"><?= escapeHtml($switch['help']) ?></div>
                        </div>
                    <?php endforeach; ?>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="permission_manage" disabled
                               aria-describedby="permission_manage_help" <?php if ($managesAccounts): ?>checked<?php endif; ?>>
                        <label class="form-check-label" for="permission_manage">Manage staff accounts and the activity log</label>
                        <div class="form-text mt-0" id="permission_manage_help">Comes with the head administrator role; change the role above to give or remove it.</div>
                    </div>
                </fieldset>
                <?php if ($isSelf): ?>
                    <p class="small text-secondary mb-0">You cannot change your own permissions.</p>
                <?php else: ?>
                    <button type="submit" class="btn btn-outline-primary">Save permissions</button>
                <?php endif; ?>
            </div>
        </form>
    <?php endif; ?>

    <h2 class="h5">History</h2>
    <?php if (count($history) === 0): ?>
        <p class="text-secondary">No events recorded yet.</p>
    <?php else: ?>
        <ul class="list-group mb-2">
            <?php foreach ($history as $event): ?>
                <li class="list-group-item d-flex flex-wrap justify-content-between gap-2">
                    <span>
                        <?= escapeHtml($event['label']) ?>
                        <span class="text-secondary small">
                            by <?php if ($event['username'] === null): ?>terminal<?php else: ?><?= escapeHtml($event['username']) ?><?php endif; ?>
                        </span>
                    </span>
                    <span class="text-secondary small"><?= escapeHtml(readableDateTime($event['created_at'], '-')) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="small text-secondary">The newest 20 events.</p>
    <?php endif; ?>
</main>
</body>

</html>
