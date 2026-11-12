<?php
declare(strict_types=1); // keep typed values strict

use Scholar\Auth\Session;
use Scholar\Auth\StaffAccounts;
use Scholar\Auth\TwoFactor;

// "Two-step login": any signed-in staff member links an authenticator app here (user's request, 2026-11-10)
// head administrators are sent here by Access until they have linked one; for RDPO administrators it is optional
// on this page the person can also make a new set of backup codes (head administrators), forget the remembered
// devices, link a different app, or (RDPO administrators only) switch two-step login off
require_once __DIR__ . '/../app/bootstrap.php';
Session::start();
$pdo = connectDatabase();
// only "logged in and active" is checked here, like My password: Access::requirePermission() would send a
// head administrator without an app straight back to this page
$account = Session::requireAuthentication($pdo);
if ($account['must_change_password']) {
    header('Location: admin_password.php', true, 303); // the temporary password is replaced first
    exit;
}

$requestMethod = $_SERVER['REQUEST_METHOD'];
if ($requestMethod !== 'GET' && $requestMethod !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$twoFactor = new TwoFactor($pdo);
$staffAccounts = new StaffAccounts($pdo);
$isRequired = TwoFactor::isRequiredFor($account['role_code']);
$usesBackupCodes = TwoFactor::usesBackupCodes($account['role_code']);
$errorMessage = '';

// while the app is not linked yet, the secret waits in the session (not the database) until a correct code proves
// the app has it; it stays the same on refresh, so the QR code does not change while the person is scanning it
if (!$account['two_factor_enabled']) {
    $setup = $_SESSION['two_factor_setup'] ?? null;
    if (!is_array($setup) || !isset($setup['user_id'], $setup['secret']) || $setup['user_id'] !== $account['id']) {
        $_SESSION['two_factor_setup'] = ['user_id' => $account['id'], 'secret' => TwoFactor::newSecret()];
    }
    $setupSecret = $_SESSION['two_factor_setup']['secret'];
}

if ($requestMethod === 'POST') {
    if (!Session::verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403); // this is for preventing CSRF attacks
        exit('The form expired. Return to the two-factor authentication page and try again.');
    }
    $action = '';
    if (isset($_POST['action']) && is_string($_POST['action'])) {
        $action = $_POST['action'];
    }
    // the actions that make sense right now; anything else is refused
    if ($account['two_factor_enabled']) {
        $allowedActions = ['forget_devices', 'relink'];
        if ($usesBackupCodes) {
            $allowedActions[] = 'new_backup_codes';
        }
        if (!$isRequired) {
            $allowedActions[] = 'turn_off';
        }
    } else {
        $allowedActions = ['confirm_setup'];
    }
    if (!in_array($action, $allowedActions, true)) {
        http_response_code(422);
        exit('Unknown action.');
    }

    $typedPassword = '';
    if (isset($_POST['current_password']) && is_string($_POST['current_password'])) {
        $typedPassword = $_POST['current_password']; // never trimmed; spaces can be part of a password
    }
    // making new codes, linking another app and switching off all ask for the password again
    $needsPassword = in_array($action, ['new_backup_codes', 'relink', 'turn_off'], true);

    if ($needsPassword && !$staffAccounts->passwordIsCorrect($account['id'], $typedPassword)) {
        $errorMessage = 'Your password is not correct. Nothing was changed.';
    } elseif ($action === 'confirm_setup') {
        $typedCode = '';
        if (isset($_POST['code']) && is_string($_POST['code'])) {
            $typedCode = mb_substr($_POST['code'], 0, 40);
        }
        if ($twoFactor->confirmSetup($account['id'], $setupSecret, $typedCode)) {
            unset($_SESSION['two_factor_setup']); // the secret is now saved encrypted; the session copy is not needed
            if ($usesBackupCodes) {
                // shown ONCE after the redirect, then removed from the session
                $_SESSION['new_backup_codes'] = ['user_id' => $account['id'], 'codes' => $twoFactor->newBackupCodes($account['id'])];
            }
            Session::setFlash('2FA is on. From now on you will type a code from your app after your password.');
            header('Location: admin_two_factor.php', true, 303);
            exit;
        }
        $errorMessage = 'That code is not correct. Scan the QR code again or check the key you typed, then try the newest code.';
    } elseif ($action === 'new_backup_codes') {
        $_SESSION['new_backup_codes'] = ['user_id' => $account['id'], 'codes' => $twoFactor->newBackupCodes($account['id'])];
        Session::setFlash('New backup codes made. The old ones no longer work.');
        header('Location: admin_two_factor.php', true, 303);
        exit;
    } elseif ($action === 'forget_devices') {
        $twoFactor->forgetDevices($account['id'], $account['id']);
        Session::setFlash('Every remembered device was forgotten. They will ask for a code at the next login.');
        header('Location: admin_two_factor.php', true, 303);
        exit;
    } else {
        // relink and turn_off both remove the app, the backup codes and the remembered devices
        $twoFactor->turnOff($account['id'], $account['id'], 'account.two_factor_disabled');
        unset($_SESSION['two_factor_setup']);
        if ($action === 'relink') {
            Session::setFlash('Your old app no longer works for this account. Scan the new QR code with the app you want to use.');
        } else {
            Session::setFlash('2FA is off. Logging in needs only your password.');
        }
        header('Location: admin_two_factor.php', true, 303);
        exit;
    }
    http_response_code(422); // the page is shown again with the message
}

// the new backup codes, shown ONCE and then forgotten (only their hashes are in the database)
$newBackupCodes = [];
if (isset($_SESSION['new_backup_codes']) && is_array($_SESSION['new_backup_codes'])) {
    $waiting = $_SESSION['new_backup_codes'];
    if (isset($waiting['user_id'], $waiting['codes']) && $waiting['user_id'] === $account['id']) {
        $newBackupCodes = $waiting['codes'];
    }
    unset($_SESSION['new_backup_codes']);
}

$summary = $twoFactor->summary($account['id']);
$qrCodeSvg = '';
$readableSecret = '';
if (!$account['two_factor_enabled']) {
    $qrCodeSvg = TwoFactor::qrCodeSvg(TwoFactor::setupAddress($setupSecret, $account['username']));
    $readableSecret = TwoFactor::readableSecret($setupSecret);
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
    <title>Two-factor authentication - S.C.H.O.L.A.R.</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="assets/scholar.css" rel="stylesheet">
    <style>
        /* when printing the backup codes, print only the codes card */
        @media print {
            body * { visibility: hidden; }
            #backup-codes, #backup-codes * { visibility: visible; }
            #backup-codes { position: absolute; top: 0; left: 0; }
        }
    </style>
</head>

<body class="bg-light has-rdpo-sidebar">
<!-- the RDPO menu: a sidebar on wide screens, a Menu button on phones (shared by every RDPO page) -->
<?php require __DIR__ . '/assets/admin_sidebar.php'; ?>

<main class="container py-4" style="max-width: 40rem;">
    <h1 class="h3 mb-3">Two-factor authentication (2FA)</h1>

    <?php if ($flashMessage !== '' && $flashIsError): ?>
        <div class="alert alert-danger" role="alert"><?= escapeHtml($flashMessage) ?></div>
    <?php elseif ($flashMessage !== ''): ?>
        <div class="alert alert-success" role="status"><?= escapeHtml($flashMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= escapeHtml($errorMessage) ?></div>
    <?php endif; ?>

    <?php if (count($newBackupCodes) > 0): ?>
        <!-- the codes themselves are only on this one page view; after leaving, they cannot be shown again -->
        <div class="card border-warning mb-4" id="backup-codes">
            <div class="card-body">
                <h2 class="h5">Your backup codes</h2>
                <p>Write these down or print them, and keep them somewhere safe away from your phone. Each code works
                    once, in place of the app code. <strong>They will not be shown again.</strong></p>
                <ol class="font-monospace fs-5 row row-cols-2 mb-3 ps-4">
                    <?php foreach ($newBackupCodes as $code): ?>
                        <li class="col"><?= escapeHtml($code) ?></li>
                    <?php endforeach; ?>
                </ol>
                <button type="button" class="btn btn-outline-secondary btn-sm d-print-none" onclick="window.print();">Print the codes</button>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$account['two_factor_enabled']): ?>
        <?php if ($isRequired): ?>
            <div class="alert alert-warning" role="status">
                Head administrators must use two-factor authentication (2FA). Link an authenticator app before you continue.
            </div>
        <?php else: ?>
            <p>2FA is <strong>off</strong>. Turning it on means a stolen password alone is not enough to get into this account.</p>
        <?php endif; ?>

        <div class="card card-body mb-4">
            <h2 class="h5">1. Scan the QR code</h2>
            <p class="mb-2">Open an authenticator app on your phone, such as 2FAS, Google Authenticator or Microsoft
                Authenticator, choose to add an account, and scan this code.</p>
            <!-- the SVG is made on the server from the secret; it contains no script -->
            <div class="text-center mb-2" role="img" aria-label="QR code for linking an authenticator app"><?= $qrCodeSvg ?></div>
            <details class="mb-3">
                <summary>Cannot scan it? Type this key instead</summary>
                <p class="mt-2 mb-1 small text-secondary">Choose "enter a setup key" in the app, name it S.C.H.O.L.A.R., and choose "time based".</p>
                <p class="font-monospace fs-6 text-break mb-0"><?= escapeHtml($readableSecret) ?></p>
            </details>

            <h2 class="h5">2. Type the code from the app</h2>
            <form method="post" action="admin_two_factor.php">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                <input type="hidden" name="action" value="confirm_setup">
                <label for="code" class="form-label">6-digit code</label>
                <input id="code" name="code" type="text" class="form-control mb-3" style="max-width: 12rem;" required maxlength="7"
                       inputmode="numeric" pattern="[0-9 ]{6,7}" autocomplete="one-time-code">
                <button type="submit" class="btn btn-primary">Turn on 2FA</button>
            </form>
            <?php if ($usesBackupCodes): ?>
                <p class="small text-secondary mt-3 mb-0">After this you will get 10 backup codes to write down.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p>2FA is <strong>on</strong> since <?= escapeHtml(date('M j, Y g:i A', strtotime($summary['enabled_at']))) ?>.
            After your password you type the code from your authenticator app.</p>

        <?php if ($usesBackupCodes): ?>
            <div class="card card-body mb-3">
                <h2 class="h5">Backup codes</h2>
                <?php if ($summary['backup_codes_left'] === 0): ?>
                    <div class="alert alert-danger mb-2" role="alert">You have no backup codes left. Make a new set now, in case you lose your phone.</div>
                <?php elseif ($summary['backup_codes_left'] <= 3): ?>
                    <div class="alert alert-warning mb-2" role="status">Only <?= $summary['backup_codes_left'] ?> backup codes left. Consider making a new set.</div>
                <?php endif; ?>
                <p><?= $summary['backup_codes_left'] ?> of <?= $summary['backup_codes_total'] ?> codes unused.
                    Making a new set replaces all of them.</p>
                <form method="post" action="admin_two_factor.php" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                    <input type="hidden" name="action" value="new_backup_codes">
                    <div class="col-12 col-sm">
                        <label for="password_codes" class="form-label small">Your password</label>
                        <input id="password_codes" name="current_password" type="password" class="form-control" required
                               maxlength="<?= StaffAccounts::PASSWORD_MAX_BYTES ?>" autocomplete="current-password">
                    </div>
                    <div class="col-12 col-sm-auto"><button type="submit" class="btn btn-outline-primary">Make 10 new codes</button></div>
                </form>
            </div>
        <?php endif; ?>

        <div class="card card-body mb-3">
            <h2 class="h5">Remembered devices</h2>
            <p><?= $summary['remembered_devices'] ?> device(s) skip the code for this account until their 30 days are over.</p>
            <form method="post" action="admin_two_factor.php">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                <input type="hidden" name="action" value="forget_devices">
                <button type="submit" class="btn btn-outline-secondary" <?php if ($summary['remembered_devices'] === 0): ?>disabled<?php endif; ?>>Forget all remembered devices</button>
            </form>
        </div>

        <div class="card card-body mb-3">
            <h2 class="h5">New phone?</h2>
            <p>Link a different authenticator app. The old one stops working<?php if ($usesBackupCodes): ?>, and you get new backup codes<?php endif; ?>.</p>
            <form method="post" action="admin_two_factor.php" class="row g-2 align-items-end"
                  onsubmit="return confirm('Unlink the current app and link a new one?');">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                <input type="hidden" name="action" value="relink">
                <div class="col-12 col-sm">
                    <label for="password_relink" class="form-label small">Your password</label>
                    <input id="password_relink" name="current_password" type="password" class="form-control" required
                           maxlength="<?= StaffAccounts::PASSWORD_MAX_BYTES ?>" autocomplete="current-password">
                </div>
                <div class="col-12 col-sm-auto"><button type="submit" class="btn btn-outline-warning">Link a different app</button></div>
            </form>
        </div>

        <?php if (!$isRequired): ?>
            <div class="card card-body mb-3">
                <h2 class="h5">Turn off</h2>
                <p>Logging in will need only your password again.</p>
                <form method="post" action="admin_two_factor.php" class="row g-2 align-items-end"
                      onsubmit="return confirm('Turn off 2FA?');">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                    <input type="hidden" name="action" value="turn_off">
                    <div class="col-12 col-sm">
                        <label for="password_off" class="form-label small">Your password</label>
                        <input id="password_off" name="current_password" type="password" class="form-control" required
                               maxlength="<?= StaffAccounts::PASSWORD_MAX_BYTES ?>" autocomplete="current-password">
                    </div>
                    <div class="col-12 col-sm-auto"><button type="submit" class="btn btn-outline-danger">Turn off 2FA</button></div>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>

</html>
