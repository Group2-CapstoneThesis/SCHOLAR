<?php
declare(strict_types=1);

use Scholar\Auth\Authentication;
use Scholar\Auth\LoginThrottle;
use Scholar\Auth\Session;
use Scholar\Auth\TwoFactor;
use Scholar\AuditLog;

// step 2 of logging in (user's request, 2026-11-10): the password was right, now the 6-digit code from the
// authenticator app, or (head administrators) one of the 10 backup codes
// login.php sends people here; the session only holds "half-way through logging in" until the code is right
require_once __DIR__ . '/../app/bootstrap.php';
Session::start();

$requestMethod = $_SERVER['REQUEST_METHOD'];
if ($requestMethod !== 'GET' && $requestMethod !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

// nobody is half-way through logging in (or the 5 minutes are over): start again at the password
$pending = Session::pendingTwoFactor();
if ($pending === null) {
    header('Location: login.php?code_expired=1', true, 302);
    exit;
}

$pdo = connectDatabase();
$twoFactor = new TwoFactor($pdo);

// the role decides whether the "use a backup code" option is shown
$statement = $pdo->prepare('SELECT r.code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id');
$statement->execute(['id' => $pending['user_id']]);
$roleCode = (string) $statement->fetchColumn();
$offersBackupCodes = TwoFactor::usesBackupCodes($roleCode);

// ?backup=1 shows the backup code box instead of the app code box (a plain link, no JavaScript needed)
$isBackupMode = $offersBackupCodes && isset($_GET['backup']) && $_GET['backup'] === '1';

$errorMessage = '';
if ($requestMethod === 'POST') {
    if (!Session::verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403); // this is for preventing CSRF attacks
        exit('The form expired. Return to the login page and try again.');
    }
    $typedCode = '';
    if (isset($_POST['code']) && is_string($_POST['code'])) {
        $typedCode = mb_substr($_POST['code'], 0, 40); // a code is short; anything longer is cut, never stored
    }
    $isBackupMode = $offersBackupCodes && isset($_POST['method']) && $_POST['method'] === 'backup';
    $rememberDevice = isset($_POST['remember_device']) && $_POST['remember_device'] === '1';

    // the same failed-login limit as the password: wrong codes count toward it, so guessing codes is as slow
    // as guessing passwords (5 wrong tries per account in 15 minutes by default)
    $limits = loadConfiguration()['login'];
    $throttle = new LoginThrottle($pdo, $limits['account_max_failures'], $limits['address_max_failures'], $limits['window_seconds']);
    $visitorAddress = 'unknown';
    if (isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
        $visitorAddress = $_SERVER['REMOTE_ADDR'];
    }
    if ($throttle->isBlocked($pending['identifier'], $visitorAddress, time())) {
        Session::cancelTwoFactor(); // the password has to be typed again after the wait
        header('Location: login.php?code_blocked=1', true, 303);
        exit;
    }

    $codeIsRight = false;
    if ($isBackupMode) {
        $codeIsRight = $twoFactor->useBackupCode($pending['user_id'], $typedCode);
    } else {
        $codeIsRight = $twoFactor->verifyAppCode($pending['user_id'], $typedCode);
    }

    if ($codeIsRight) {
        $userId = $pending['user_id'];
        $auth = new Authentication($pdo, $throttle);
        $auth->finishLogin($userId, $pending['identifier']); // a fresh session identifier with the logged-in account
        if ($rememberDevice) {
            $twoFactor->rememberThisDevice($userId);
        }
        if ($isBackupMode) {
            AuditLog::record($pdo, $userId, 'auth.backup_code_used', 'user', $userId);
            $codesLeft = $twoFactor->summary($userId)['backup_codes_left'];
            if ($codesLeft === 0) {
                Session::setFlash('You used your last backup code. Make a new set on the Two-factor authentication page now.', true);
            } else {
                Session::setFlash('Backup code used. You have ' . $codesLeft . ' left.');
            }
        }
        header('Location: admin.php', true, 303);
        exit;
    }

    $throttle->recordFailure($pending['identifier'], $visitorAddress, time());
    http_response_code(401);
    if ($isBackupMode) {
        $errorMessage = 'That backup code is not correct or was already used.';
    } else {
        $errorMessage = 'That code is not correct. Check that you used the S.C.H.O.L.A.R. entry in your app, then try the newest code.';
    }
}

$csrfToken = Session::csrfToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login code - S.C.H.O.L.A.R.</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="assets/scholar.css" rel="stylesheet">
</head>

<body class="bg-light">
<main class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-5">
            <h1 class="h3 mb-3">Two-factor authentication</h1>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= escapeHtml($errorMessage) ?></div>
            <?php endif; ?>

            <form method="post" action="login_code.php<?php if ($isBackupMode): ?>?backup=1<?php endif; ?>" class="card card-body">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                <?php if ($isBackupMode): ?>
                    <input type="hidden" name="method" value="backup">
                    <div class="mb-3">
                        <label for="code" class="form-label">Backup code</label>
                        <div class="form-text mt-0 mb-1" id="code_help">One of the 10 codes you wrote down, for example k7mqx-3pahr. Each code works only once.</div>
                        <input id="code" name="code" type="text" class="form-control" required maxlength="20"
                               autocomplete="off" autocapitalize="off" spellcheck="false" aria-describedby="code_help" autofocus>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="method" value="app">
                    <div class="mb-3">
                        <label for="code" class="form-label">6-digit code</label>
                        <div class="form-text mt-0 mb-1" id="code_help">Open your authenticator app and type the code shown for S.C.H.O.L.A.R.</div>
                        <!-- one-time-code lets phones offer the code; numeric shows the number keypad -->
                        <input id="code" name="code" type="text" class="form-control" required maxlength="7"
                               inputmode="numeric" pattern="[0-9 ]{6,7}" autocomplete="one-time-code" aria-describedby="code_help" autofocus>
                    </div>
                <?php endif; ?>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="remember_device" value="1" id="remember_device" aria-describedby="remember_help">
                    <label class="form-check-label" for="remember_device">Remember this device for <?= TwoFactor::REMEMBER_DAYS ?> days</label>
                    <div class="form-text" id="remember_help">You will still need your password. Do not tick this on a shared or public computer.</div>
                </div>

                <div><button type="submit" class="btn btn-primary">Continue</button></div>
            </form>

            <div class="mt-3 small">
                <?php if ($offersBackupCodes && !$isBackupMode): ?>
                    <p class="mb-1"><a href="login_code.php?backup=1">Use a backup code instead</a></p>
                <?php elseif ($isBackupMode): ?>
                    <p class="mb-1"><a href="login_code.php">Use the authenticator app instead</a></p>
                <?php endif; ?>
                <p class="mb-0"><a href="login.php">Back to the login page</a></p>
            </div>
        </div>
    </div>
</main>
</body>

</html>
