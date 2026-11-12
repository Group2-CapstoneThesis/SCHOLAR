<?php
declare(strict_types=1); // keep typed values strict

use Scholar\Auth\Session;
use Scholar\Auth\StaffAccounts;
use Scholar\Auth\TwoFactor;

// "My password": any signed-in staff member changes their own password here (M12)
// an account with a temporary password (new, or reset by the head administrator) is sent here by Access
// before it can open any other RDPO page, and stays here until it chooses its own password
require_once __DIR__ . '/../app/bootstrap.php';
Session::start();
$pdo = connectDatabase();
// only "logged in and active" is checked here, not a permission: every staff member may change their own password,
// and Access::requirePermission() would send a temporary-password account straight back to this page
$account = Session::requireAuthentication($pdo);

$requestMethod = $_SERVER['REQUEST_METHOD'];
if ($requestMethod !== 'GET' && $requestMethod !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

// with 2FA on, changing the password also needs a code from the authenticator app (user's request, 2026-11-10),
// so someone who learned the password cannot lock the owner out by changing it
// a temporary password (new account, reset, emergency access) is the exception: that person has no app linked
// yet, or must be able to choose a password before anything else
$needsCode = $account['two_factor_enabled'] && !$account['must_change_password'];
$acceptsBackupCode = TwoFactor::usesBackupCodes($account['role_code']);

$errorMessage = '';
if ($requestMethod === 'POST') {
    $submittedToken = null;
    if (isset($_POST['csrf_token'])) {
        $submittedToken = $_POST['csrf_token'];
    }
    if (!Session::verifyCsrf($submittedToken)) {
        http_response_code(403); // this is for preventing CSRF attacks
        exit('The form expired. Return to the password page and try again.');
    }

    // a modified form can send arrays; anything that is not text is treated as empty
    // passwords are never trimmed, because spaces can be part of a password
    $currentPassword = '';
    $newPassword = '';
    $confirmation = '';
    if (isset($_POST['current_password']) && is_string($_POST['current_password'])) {
        $currentPassword = $_POST['current_password'];
    }
    if (isset($_POST['new_password']) && is_string($_POST['new_password'])) {
        $newPassword = $_POST['new_password'];
    }
    if (isset($_POST['confirm_password']) && is_string($_POST['confirm_password'])) {
        $confirmation = $_POST['confirm_password'];
    }

    $typedCode = '';
    if (isset($_POST['two_factor_code']) && is_string($_POST['two_factor_code'])) {
        $typedCode = mb_substr($_POST['two_factor_code'], 0, 40);
    }

    $staffAccounts = new StaffAccounts($pdo);
    if ($needsCode) {
        // check everything else first, so a code (above all a single-use backup code) is not used up by a form
        // that is refused anyway for another mistake
        if (!$staffAccounts->passwordIsCorrect($account['id'], $currentPassword)) {
            $errorMessage = 'Your current password is not correct.';
        } else {
            $errorMessage = StaffAccounts::newPasswordProblem($newPassword, $confirmation, $account['username']);
        }
        if ($errorMessage === '' && hash_equals($currentPassword, $newPassword)) {
            $errorMessage = 'Choose a password that is different from your current one.';
        }
        if ($errorMessage === '') {
            $twoFactor = new TwoFactor($pdo);
            $codeIsRight = $twoFactor->verifyAppCode($account['id'], $typedCode);
            // a head administrator may type a backup code instead (10 letters and digits, the dash is optional)
            if (!$codeIsRight && $acceptsBackupCode && strlen(TwoFactor::cleanBackupCode($typedCode)) === 10) {
                $codeIsRight = $twoFactor->useBackupCode($account['id'], $typedCode);
            }
            if (!$codeIsRight) {
                $errorMessage = 'The 2FA code is not correct. Type the newest code from your authenticator app.';
            }
        }
    }
    if ($errorMessage === '') {
        $errorMessage = $staffAccounts->changeOwnPassword($account['id'], $currentPassword, $newPassword, $confirmation);
    }
    if ($errorMessage === '') {
        // a fresh session identifier and login time: sessions elsewhere that used the old password end on their
        // next click, while this one continues (see Session::requireAuthentication())
        Session::establishIdentity($account['id']);
        Session::setFlash('Your password was changed.');
        header('Location: admin.php', true, 303);
        exit;
    }
    http_response_code(422); // the form is shown again with the message
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
    <title>My password - S.C.H.O.L.A.R.</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="assets/scholar.css" rel="stylesheet">
</head>

<body class="bg-light has-rdpo-sidebar">
<!-- the RDPO menu: a sidebar on wide screens, a Menu button on phones (shared by every RDPO page) -->
<?php require __DIR__ . '/assets/admin_sidebar.php'; ?>

<main class="container py-4" style="max-width: 36rem;">
    <h1 class="h3 mb-3">My password</h1>

    <?php if ($flashMessage !== '' && $flashIsError): ?>
        <div class="alert alert-danger" role="alert"><?= escapeHtml($flashMessage) ?></div>
    <?php elseif ($flashMessage !== ''): ?>
        <div class="alert alert-success" role="status"><?= escapeHtml($flashMessage) ?></div>
    <?php endif; ?>

    <?php if ($account['must_change_password']): ?>
        <div class="alert alert-warning" role="status">
            This account has a temporary password. Choose your own password before you continue.
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= escapeHtml($errorMessage) ?></div>
    <?php endif; ?>

    <!-- the typed passwords are never put back into the form after a mistake -->
    <form method="post" action="admin_password.php" class="card card-body">
        <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
        <div class="mb-3">
            <label for="current_password" class="form-label">Current password</label>
            <?php if ($account['must_change_password']): ?>
                <div class="form-text mt-0 mb-1" id="current_help">The temporary password you were given.</div>
            <?php endif; ?>
            <input id="current_password" name="current_password" type="password" class="form-control"
                   autocomplete="current-password" maxlength="<?= StaffAccounts::PASSWORD_MAX_BYTES ?>" required
                   <?php if ($account['must_change_password']): ?>aria-describedby="current_help"<?php endif; ?>>
        </div>
        <div class="mb-3">
            <label for="new_password" class="form-label">New password</label>
            <input id="new_password" name="new_password" type="password" class="form-control"
                   autocomplete="new-password" minlength="<?= StaffAccounts::PASSWORD_MIN_BYTES ?>"
                   maxlength="<?= StaffAccounts::PASSWORD_MAX_BYTES ?>" required aria-describedby="new_help">
            <div class="form-text" id="new_help">
                At least <?= StaffAccounts::PASSWORD_MIN_BYTES ?> characters, without your username. A short sentence is easy to remember.
            </div>
        </div>
        <div class="mb-3">
            <label for="confirm_password" class="form-label">New password again</label>
            <input id="confirm_password" name="confirm_password" type="password" class="form-control"
                   autocomplete="new-password" maxlength="<?= StaffAccounts::PASSWORD_MAX_BYTES ?>" required>
        </div>
        <?php if ($needsCode): ?>
            <div class="mb-3">
                <label for="two_factor_code" class="form-label">2FA code</label>
                <div class="form-text mt-0 mb-1" id="code_help">
                    The 6-digit code from your authenticator app<?php if ($acceptsBackupCode): ?>, or one of your backup codes<?php endif; ?>.
                </div>
                <input id="two_factor_code" name="two_factor_code" type="text" class="form-control" style="max-width: 14rem;" required
                       maxlength="20" autocomplete="one-time-code" aria-describedby="code_help">
            </div>
        <?php endif; ?>
        <div>
            <button type="submit" class="btn btn-primary">Change password</button>
        </div>
        <p class="small text-secondary mt-3 mb-0">
            Changing your password signs out this account on every other computer where it is still logged in.
        </p>
    </form>
</main>
</body>

</html>
