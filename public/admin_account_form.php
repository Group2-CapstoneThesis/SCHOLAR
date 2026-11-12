<?php
declare(strict_types=1); // keep typed values strict

use Scholar\Auth\Access;
use Scholar\Auth\Session;
use Scholar\Auth\StaffAccounts;

// "New staff account" (M12): the head administrator enters a username and role;
// the system makes a temporary password, shown once on the next page, which the person must replace when they first log in
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

$staffAccounts = new StaffAccounts($pdo);
$roleOptions = $staffAccounts->roleOptions();
$values = ['username' => '', 'role_code' => 'rdpo_admin'];
$errors = [];

if ($requestMethod === 'POST') {
    $submittedToken = null;
    if (isset($_POST['csrf_token'])) {
        $submittedToken = $_POST['csrf_token'];
    }
    if (!Session::verifyCsrf($submittedToken)) {
        http_response_code(403); // this is for preventing CSRF attacks
        exit('The form expired. Return to the staff accounts page and try again.');
    }

    $checked = StaffAccounts::newAccountValues($_POST);
    $values = $checked['values'];
    $errors = $checked['errors'];
    if (count($errors) === 0) {
        $created = $staffAccounts->createAccount($values, $account['id']);
        if ($created['result'] === 'created') {
            // the temporary password waits in this session for ONE showing on the account page, then it is removed;
            // it never goes into the address bar, the audit log or a file of its own
            $_SESSION['temporary_password'] = ['account_id' => $created['id'], 'password' => $created['temporary_password']];
            Session::setFlash('Account created.');
            header('Location: admin_account.php?id=' . $created['id'], true, 303);
            exit;
        }
        $errors['username'] = 'This username is already used by another account.';
    }
    http_response_code(422); // show the form again with the problems marked
}

$csrfToken = Session::csrfToken();

// the Bootstrap class for one input: red border when that field has a problem
function inputClass(array $errors, string $field): string {
    if (isset($errors[$field])) {
        return 'form-control is-invalid';
    }
    return 'form-control';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New staff account - S.C.H.O.L.A.R.</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="assets/scholar.css" rel="stylesheet">
</head>

<body class="bg-light has-rdpo-sidebar">
<!-- the RDPO menu: a sidebar on wide screens, a Menu button on phones (shared by every RDPO page) -->
<?php require __DIR__ . '/assets/admin_sidebar.php'; ?>

<main class="container py-4" style="max-width: 40rem;">
    <h1 class="h3 mb-3">New staff account</h1>

    <?php if (count($errors) > 0): ?>
        <div class="alert alert-danger" role="alert">The account was not created. Check the marked fields.</div>
    <?php endif; ?>

    <form method="post" action="admin_account_form.php" class="card card-body" novalidate>
        <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">

        <div class="mb-3">
            <label for="username" class="form-label">Username</label>
            <input id="username" name="username" type="text" class="<?= inputClass($errors, 'username') ?>"
                   value="<?= escapeHtml($values['username']) ?>" maxlength="64" autocomplete="off" required
                   aria-describedby="username_help<?php if (isset($errors['username'])): ?> username_error<?php endif; ?>">
            <div class="form-text" id="username_help">3 to 64 letters, digits, underscores, dots or hyphens, for example <code>juan.delacruz</code>. A head administrator can change it later.</div>
            <?php if (isset($errors['username'])): ?>
                <div class="invalid-feedback" id="username_error"><?= escapeHtml($errors['username']) ?></div>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <label for="role_code" class="form-label">Role</label>
            <select id="role_code" name="role_code" class="form-select<?php if (isset($errors['role_code'])): ?> is-invalid<?php endif; ?>"
                    aria-describedby="role_help">
                <?php foreach ($roleOptions as $code => $label): ?>
                    <option value="<?= escapeHtml($code) ?>" <?php if ($code === $values['role_code']): ?>selected<?php endif; ?>><?= escapeHtml($label) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text" id="role_help">A head administrator can also manage staff accounts.</div>
            <?php if (isset($errors['role_code'])): ?>
                <div class="invalid-feedback"><?= escapeHtml($errors['role_code']) ?></div>
            <?php endif; ?>
        </div>

        <p class="small text-secondary">
            A temporary password is made for the new account and shown once on the next page.
            Give it to the person directly; they must choose their own password when they first log in.
        </p>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">Create account</button>
            <a href="admin_accounts.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</main>
</body>

</html>
