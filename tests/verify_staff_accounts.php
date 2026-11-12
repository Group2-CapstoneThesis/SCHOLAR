<?php
// WHAT THIS FILE DOES: automatically checks the staff account pages (M12) and the provisional head administrator role.
// It sends requests to our local Apache like a browser, using made up accounts only.

// STEP 1: Allow terminal use only, load the application, and connect to the development database.
// STEP 2: Create a temporary RDPO administrator, and a temporary head administrator with the "set-role" terminal command.
// STEP 3: Without logging in, open the account pages; they must send the visitor to login.
// STEP 4: Log in as the ordinary RDPO administrator; the account pages answer 403 and the menu has no "Staff accounts".
// STEP 5: Log in as the head administrator, open the list, and create a new staff account; its temporary password is
//         shown once only, and duplicate or badly typed details are refused.
// STEP 6: Log in as the new account; every RDPO page sends it to "My password" until it chooses its own password,
//         and wrong current passwords, different copies and passwords containing the username are refused.
// STEP 7: Change the new account's role to head administrator and back; the change applies on its next click, and as a
//         head administrator it is first sent to link an authenticator app. Reset its two-step login (not your own).
// STEP 8: Change its username; bad or already used ones are refused. The head administrator changes their own
//         username too, but not another head administrator's.
// STEP 9: Switch two permissions off for it: the pages refuse it, the buttons and links disappear, and switching
//         them back on restores them; the head administrator cannot switch their own permissions.
// STEP 10: Reset its password; its open session ends, the old password stops working, the new temporary one works.
// STEP 11: Disable and enable it; the disabled account is signed out and cannot log in.
// STEP 12: The head administrator cannot change the role of, reset or disable their own account.
// STEP 13: Modified requests (no form token, unknown action, unknown account) are refused,
//          and the audit log holds field names only, never a password.
// STEP 14: In cleanup, remove only this script's record, permission switches, accounts, audit rows and failed-login counters.

// Each check prints PASS; a failed check stops the remaining checks and reports failure.
// Cleanup is attempted in finally even when a check fails after preparation starts.
// The terminal exit code is 0 for success or 1 for a caught test failure.

declare(strict_types=1); // reject incorrect types passed into the test helpers

use Scholar\Auth\Access;
use Scholar\Auth\LoginThrottle;
use Scholar\Theses\ThesisRecords;

// run this script from the terminal; it is not a browser page
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/two_factor_helper.php'; // head administrators must type an app code at login

// print the action before performing it; static remembers the number between calls
function showStep(string $action): void {
    static $stepNumber = 0;
    $stepNumber++;
    echo "\nSTEPS $stepNumber: $action\n";
}

// report each expected result; throwing stops the test if a requirement fails
function check(bool $condition, string $description): void {
    if (!$condition) {
        throw new RuntimeException($description);
    }
    echo "PASS: $description\n";
}

// cURL is PHP's HTTP client; this helper sends one request to our existing Apache
// form is either an array of submitted fields or null for a normal GET request
function requestPage(CurlHandle $client, string $baseUrl, string $page, $form = null): array {
    $isPost = false;
    if ($form !== null) {
        $isPost = true;
    }
    curl_setopt_array($client, [
        CURLOPT_URL => $baseUrl . $page,
        CURLOPT_POST => $isPost,
        CURLOPT_HTTPGET => !$isPost, // switch the same handle back to GET after a POST
        CURLOPT_RETURNTRANSFER => true, // return the response instead of printing it
        CURLOPT_HEADER => true, // include headers so redirects can be inspected
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_PROXY => '', // send local test requests directly to Apache
    ]);
    if ($form !== null) {
        curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $response = curl_exec($client);
    if ($response === false) {
        throw new RuntimeException('HTTP request failed.');
    }
    $headerLength = curl_getinfo($client, CURLINFO_HEADER_SIZE);
    return [
        'status' => curl_getinfo($client, CURLINFO_HTTP_CODE),
        'headers' => substr($response, 0, $headerLength),
        'body' => substr($response, $headerLength),
    ];
}

// find the hidden CSRF input in the returned HTML, just as a browser form would use it
function tokenFrom(array $response): string {
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $response['body'], $matches)) {
        throw new RuntimeException('CSRF token missing.');
    }
    return $matches[1];
}

// open a fresh login form and submit the given credentials with its CSRF token
function submitLogin(CurlHandle $client, string $baseUrl, string $identifier, string $password): array {
    $login = requestPage($client, $baseUrl, 'login.php');
    return requestPage($client, $baseUrl, 'login.php', [
        'identifier' => $identifier,
        'password' => $password,
        'csrf_token' => tokenFrom($login),
    ]);
}

// a new HTTP client with its own empty cookie jar, like a separate browser
function newClient(): CurlHandle {
    $client = curl_init();
    curl_setopt($client, CURLOPT_COOKIEFILE, ''); // remember cookies like a browser
    curl_setopt($client, CURLOPT_RESOLVE, ['scholar.localhost:80:127.0.0.1']);
    return $client;
}

// true when the response is a redirect whose Location header contains $destination
function redirectsTo(array $response, string $destination): bool {
    if ($response['status'] !== 302 && $response['status'] !== 303) {
        return false;
    }
    return preg_match('/^Location: [^\r\n]*' . preg_quote($destination, '/') . '/mi', $response['headers']) === 1;
}

// sends one of the head administrator's forms on admin_account.php, with a fresh token from that page
function accountAction(CurlHandle $client, string $baseUrl, int $accountId, array $fields): array {
    $page = requestPage($client, $baseUrl, 'admin_account.php?id=' . $accountId);
    $fields['csrf_token'] = tokenFrom($page);
    return requestPage($client, $baseUrl, 'admin_account.php?id=' . $accountId, $fields);
}

// the temporary password shown on the account page (the element marked data-temporary-password), or '' when none
function temporaryPasswordOn(array $response): string {
    if (preg_match('/data-temporary-password>([a-z0-9-]{19})</', $response['body'], $matches)) {
        return $matches[1];
    }
    return '';
}

// fills in and sends the "My password" form
function changeOwnPassword(CurlHandle $client, string $baseUrl, string $current, string $new, string $confirmation): array {
    $page = requestPage($client, $baseUrl, 'admin_password.php');
    return requestPage($client, $baseUrl, 'admin_password.php', [
        'current_password' => $current,
        'new_password' => $new,
        'confirm_password' => $confirmation,
        'csrf_token' => tokenFrom($page),
    ]);
}

// runs one bin/console.php command quietly and returns its exit code (output is never printed: it may mention accounts)
function runConsole(array $arguments): int {
    // proc_open starts a child PHP command; the three pipes are its input/output/error streams
    $process = proc_open(array_merge([PHP_BINARY, __DIR__ . '/../bin/console.php'], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($process);
}

// the id of the account with this username, or 0 when there is none
function accountId(PDO $pdo, string $username): int {
    $statement = $pdo->prepare('SELECT id FROM users WHERE username = :username');
    $statement->execute(['username' => $username]);
    return (int) $statement->fetchColumn();
}

// one row of the users table, read straight from the database
function userRow(PDO $pdo, string $username): array {
    $statement = $pdo->prepare("
        SELECT u.id, u.is_active, u.must_change_password, u.password_hash, r.code AS role_code
        FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = :username
    ");
    $statement->execute(['username' => $username]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Account row missing.');
    }
    return $row;
}

// use our existing Laragon Apache; an alternative local URL can be the first argument
$baseUrl = 'http://scholar.localhost';
if (isset($argv[1])) {
    $baseUrl = $argv[1];
}
$baseUrl = rtrim($baseUrl, '/') . '/';
$pdo = connectDatabase();

// separate synthetic names so this script never touches another suite's data
$suffix = bin2hex(random_bytes(6));
$headUsername = 'verify_head_' . $suffix;
$adminUsername = 'verify_staff_' . $suffix;
$newUsername = 'verify_new_' . $suffix;
$password = bin2hex(random_bytes(20)); // never print or commit the temporary passwords
$ownPassword = 'own pass ' . bin2hex(random_bytes(8)); // the password the new account chooses for itself
$usernames = [$headUsername, $adminUsername, $newUsername];
$headClient = newClient();
$failed = false;
try {
    echo "PREPARATION: Create this script's temporary accounts.\n";
    putenv('SCHOLAR_ADMIN_PASSWORD=' . $password);
    check(runConsole(['provision-admin', $headUsername]) === 0, 'CLI provisions the future head administrator');
    check(runConsole(['provision-admin', $adminUsername]) === 0, 'CLI provisions an ordinary RDPO administrator');
    putenv('SCHOLAR_ADMIN_PASSWORD');
    check(runConsole(['set-role', $headUsername, 'rdpo_head']) === 0, 'CLI set-role makes the head administrator');
    check(runConsole(['set-role', $headUsername, 'superuser']) === 2, 'CLI set-role refuses an unknown role');
    check(runConsole(['set-role', 'verify_nobody_' . $suffix, 'rdpo_head']) === 1, 'CLI set-role reports an unknown account');
    $head = userRow($pdo, $headUsername);
    check($head['role_code'] === 'rdpo_head' && userRow($pdo, $adminUsername)['role_code'] === 'rdpo_admin', 'Roles saved as expected');
    $headId = (int) $head['id'];
    $headSecret = linkTestAuthenticator($pdo, $headId); // head administrators must use two-step login
    $audit = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action_code = 'account.role_changed' AND actor_user_id IS NULL AND entity_id = :id");
    $audit->execute(['id' => $headId]);
    check((int) $audit->fetchColumn() === 1, 'Terminal role change written to the history without an actor');

    showStep('Open the account pages without logging in.');
    $anonymous = newClient();
    try {
        foreach (['admin_accounts.php', 'admin_account_form.php', 'admin_account.php?id=' . $headId, 'admin_password.php'] as $page) {
            check(redirectsTo(requestPage($anonymous, $baseUrl, $page), 'login.php'), $page . ' redirects visitors to login');
        }
    } finally {
        unset($anonymous); // PHP releases the handle object without deprecated curl_close
    }

    showStep('Log in as an ordinary RDPO administrator.');
    $adminClient = newClient();
    try {
        check(submitLogin($adminClient, $baseUrl, $adminUsername, $password)['status'] === 303, 'RDPO administrator logs in');
        $records = requestPage($adminClient, $baseUrl, 'admin.php');
        check(strpos($records['body'], 'href="admin_accounts.php"') === false, 'Menu has no "Staff accounts" link');
        check(strpos($records['body'], 'href="admin_password.php"') !== false, 'Menu has a "My password" link');
        check(requestPage($adminClient, $baseUrl, 'admin_accounts.php')['status'] === 403, 'Account list answers 403');
        check(requestPage($adminClient, $baseUrl, 'admin_account_form.php')['status'] === 403, 'New account form answers 403');
        check(requestPage($adminClient, $baseUrl, 'admin_account.php?id=' . $headId)['status'] === 403, 'Account page answers 403');
        check(requestPage($adminClient, $baseUrl, 'admin_password.php')['status'] === 200, '"My password" opens for every staff member');
    } finally {
        unset($adminClient);
    }

    showStep('Log in as the head administrator and create a staff account.');
    $headLogin = answerCodeStep($headClient, $baseUrl, submitLogin($headClient, $baseUrl, $headUsername, $password), $pdo, $headId, $headSecret);
    check($headLogin['status'] === 303 && strpos($headLogin['headers'], 'Location: admin.php') !== false, 'Head administrator logs in with the password and the app code');
    check(strpos(requestPage($headClient, $baseUrl, 'admin.php')['body'], 'href="admin_accounts.php"') !== false, 'Menu has a "Staff accounts" link');
    $list = requestPage($headClient, $baseUrl, 'admin_accounts.php');
    check($list['status'] === 200 && strpos($list['body'], $headUsername) !== false && strpos($list['body'], $adminUsername) !== false, 'Account list shows both accounts');
    $form = requestPage($headClient, $baseUrl, 'admin_account_form.php');
    check(strpos($form['body'], 'name="email"') === false, 'The New account form has no email field (removed 2026-11-10)');
    check(strpos($list['body'], '>Email<') === false, 'The account list has no Email column');
    $created = requestPage($headClient, $baseUrl, 'admin_account_form.php', [
        'username' => $newUsername, 'role_code' => 'rdpo_admin', 'csrf_token' => tokenFrom($form),
    ]);
    $newRow = userRow($pdo, $newUsername);
    $newId = (int) $newRow['id'];
    check(redirectsTo($created, 'admin_account.php?id=' . $newId), 'Create account goes to the new account page');
    check((int) $newRow['must_change_password'] === 1 && $newRow['role_code'] === 'rdpo_admin', 'New account saved with a temporary password and the chosen role');
    $accountPage = requestPage($headClient, $baseUrl, 'admin_account.php?id=' . $newId);
    $temporaryPassword = temporaryPasswordOn($accountPage);
    check($temporaryPassword !== '' && password_verify($temporaryPassword, $newRow['password_hash']), 'Temporary password shown once and matches the saved hash');
    check(temporaryPasswordOn(requestPage($headClient, $baseUrl, 'admin_account.php?id=' . $newId)) === '', 'Reloading the page does not show it again');
    $form = requestPage($headClient, $baseUrl, 'admin_account_form.php');
    $duplicate = requestPage($headClient, $baseUrl, 'admin_account_form.php', [
        'username' => strtoupper($newUsername), 'role_code' => 'rdpo_admin', 'csrf_token' => tokenFrom($form),
    ]);
    check($duplicate['status'] === 422 && strpos($duplicate['body'], 'already used by another account') !== false, 'Duplicate username (in other capitals) refused');
    $badRole = requestPage($headClient, $baseUrl, 'admin_account_form.php', [
        'username' => 'verify_bad_' . $suffix, 'role_code' => 'superuser', 'csrf_token' => tokenFrom($form),
    ]);
    check($badRole['status'] === 422, 'Unknown role refused');
    $count = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
    $count->execute(['username' => 'verify_bad_' . $suffix]);
    check((int) $count->fetchColumn() === 0, 'Refused forms created no account');

    showStep('Log in as the new account with its temporary password.');
    $newClient = newClient();
    check(submitLogin($newClient, $baseUrl, $newUsername, $temporaryPassword)['status'] === 303, 'New account logs in with the temporary password');
    check(redirectsTo(requestPage($newClient, $baseUrl, 'admin.php'), 'admin_password.php'), 'Records page sends it to "My password"');
    check(redirectsTo(requestPage($newClient, $baseUrl, 'admin_completion_list.php'), 'admin_password.php'), 'Completion list sends it to "My password"');
    $passwordPage = requestPage($newClient, $baseUrl, 'admin_password.php');
    check(strpos($passwordPage['body'], 'This account has a temporary password.') !== false, '"My password" explains why');
    // the sidebar shows no page links while the password is temporary, only the Log out button
    check(strpos($passwordPage['body'], '>Records</a>') === false && strpos($passwordPage['body'], '>My password</a>') === false
        && strpos($passwordPage['body'], '>Log out</button>') !== false, 'Menu offers only Log out while the password is temporary');
    $wrong = changeOwnPassword($newClient, $baseUrl, 'wrong-' . $temporaryPassword, $ownPassword, $ownPassword);
    check($wrong['status'] === 422 && strpos($wrong['body'], 'Your current password is not correct.') !== false, 'Wrong current password refused');
    $different = changeOwnPassword($newClient, $baseUrl, $temporaryPassword, $ownPassword, $ownPassword . 'x');
    check($different['status'] === 422 && strpos($different['body'], 'not the same') !== false, 'Different second copy refused');
    $withName = changeOwnPassword($newClient, $baseUrl, $temporaryPassword, 'x ' . $newUsername, 'x ' . $newUsername);
    check($withName['status'] === 422 && strpos($withName['body'], 'Do not put your username') !== false, 'Password containing the username refused');
    $same = changeOwnPassword($newClient, $baseUrl, $temporaryPassword, $temporaryPassword, $temporaryPassword);
    check($same['status'] === 422 && strpos($same['body'], 'different from your current one') !== false, 'Keeping the temporary password refused');
    check(strpos($different['body'], $ownPassword) === false, 'Typed passwords are not put back into the form');
    $changed = changeOwnPassword($newClient, $baseUrl, $temporaryPassword, $ownPassword, $ownPassword);
    check(redirectsTo($changed, 'admin.php'), 'Own password accepted');
    $records = requestPage($newClient, $baseUrl, 'admin.php');
    check($records['status'] === 200 && strpos($records['body'], 'Your password was changed.') !== false, 'The same session continues on the records page');
    check((int) userRow($pdo, $newUsername)['must_change_password'] === 0, 'Temporary-password mark removed');
    check(redirectsTo(submitLogin(newClient(), $baseUrl, $newUsername, $ownPassword), 'admin.php'), 'New password works in another browser');

    showStep('Change the new account\'s role and back.');
    check(requestPage($newClient, $baseUrl, 'admin_accounts.php')['status'] === 403, 'Before: the new account may not open the account list');
    $roleChange = accountAction($headClient, $baseUrl, $newId, ['action' => 'change_role', 'role_code' => 'rdpo_head']);
    check(redirectsTo($roleChange, 'admin_account.php?id=' . $newId) && userRow($pdo, $newUsername)['role_code'] === 'rdpo_head', 'Role changed to head administrator');
    check(redirectsTo(requestPage($newClient, $baseUrl, 'admin_accounts.php'), 'admin_two_factor.php'), 'Its next click is sent to link an authenticator app first');
    $newSecret = linkTestAuthenticator($pdo, $newId);
    check(requestPage($newClient, $baseUrl, 'admin_accounts.php')['status'] === 200, 'With an app linked it may open the account list');
    // while it is a head administrator, the other head administrator may not change its username
    check(strpos(requestPage($headClient, $baseUrl, 'admin_account.php?id=' . $newId)['body'], 'name="username"') === false, 'No username form for another head administrator');
    $otherHeadRename = accountAction($headClient, $baseUrl, $newId, ['action' => 'change_username', 'username' => 'verify_x_' . $suffix]);
    check(redirectsTo($otherHeadRename, 'admin_account.php') && accountId($pdo, $newUsername) === $newId
        && strpos(requestPage($headClient, $baseUrl, 'admin_account.php?id=' . $newId)['body'], 'can only be changed by that person') !== false, 'Another head administrator\'s username is refused');
    accountAction($headClient, $baseUrl, $newId, ['action' => 'change_role', 'role_code' => 'rdpo_admin']);
    $resetTwoFactor = accountAction($headClient, $baseUrl, $newId, ['action' => 'reset_two_factor']);
    $twoFactorRow = $pdo->prepare('SELECT totp_enabled_at, totp_secret_encrypted FROM users WHERE id = :id');
    $twoFactorRow->execute(['id' => $newId]);
    $twoFactorAfter = $twoFactorRow->fetch(PDO::FETCH_ASSOC);
    check(redirectsTo($resetTwoFactor, 'admin_account.php') && $twoFactorAfter['totp_enabled_at'] === null && $twoFactorAfter['totp_secret_encrypted'] === null,
        'Reset two-step login unlinks the app of another account');
    check(accountAction($headClient, $baseUrl, $headId, ['action' => 'reset_two_factor'])['status'] === 303 && (new Scholar\Auth\TwoFactor($pdo))->isEnabled($headId),
        'The head administrator cannot reset their own two-step login');
    check(requestPage($newClient, $baseUrl, 'admin_accounts.php')['status'] === 403, 'Changed back: the account list answers 403 again');
    $unknownRole = accountAction($headClient, $baseUrl, $newId, ['action' => 'change_role', 'role_code' => 'superuser']);
    // the refusal comes back as a red message on the account page after the redirect
    $afterUnknownRole = requestPage($headClient, $baseUrl, 'admin_account.php?id=' . $newId);
    check(redirectsTo($unknownRole, 'admin_account.php') && userRow($pdo, $newUsername)['role_code'] === 'rdpo_admin'
        && strpos($afterUnknownRole['body'], 'Choose one of the listed roles.') !== false, 'Unknown role refused');

    showStep('Change the new account\'s username.');
    $renamed = 'verify_renamed_' . $suffix;
    check(strpos(requestPage($headClient, $baseUrl, 'admin_account.php?id=' . $newId)['body'], 'name="username"') !== false, 'An RDPO administrator\'s page has a username form');
    $badUsername = accountAction($headClient, $baseUrl, $newId, ['action' => 'change_username', 'username' => 'has space']);
    check($badUsername['status'] === 422 && strpos($badUsername['body'], 'Use 3 to 64 letters') !== false, 'Badly typed username refused');
    $takenUsername = accountAction($headClient, $baseUrl, $newId, ['action' => 'change_username', 'username' => strtoupper($adminUsername)]);
    check($takenUsername['status'] === 422 && strpos($takenUsername['body'], 'This username is already used by another account.') !== false, 'A username already used (in other capitals) is refused');
    check(redirectsTo(accountAction($headClient, $baseUrl, $newId, ['action' => 'change_username', 'username' => $renamed]), 'admin_account.php')
        && accountId($pdo, $renamed) === $newId, 'Username changed');
    check(redirectsTo(submitLogin(newClient(), $baseUrl, $renamed, $ownPassword), 'admin.php'), 'The person logs in with the new username');
    accountAction($headClient, $baseUrl, $newId, ['action' => 'change_username', 'username' => $newUsername]); // back, so cleanup finds it
    check(accountId($pdo, $newUsername) === $newId, 'Changed back');
    $ownRenamed = 'verify_ownname_' . $suffix;
    check(redirectsTo(accountAction($headClient, $baseUrl, $headId, ['action' => 'change_username', 'username' => $ownRenamed]), 'admin_account.php')
        && accountId($pdo, $ownRenamed) === $headId, 'The head administrator changes their own username');
    check(strpos(requestPage($headClient, $baseUrl, 'admin.php')['body'], 'Signed in as ' . $ownRenamed) !== false, 'The menu shows the new name on the next click');
    accountAction($headClient, $baseUrl, $headId, ['action' => 'change_username', 'username' => $headUsername]);
    check(accountId($pdo, $headUsername) === $headId, 'Own username changed back');
    check(strpos(requestPage($headClient, $baseUrl, 'admin_account.php?id=' . $newId)['body'], 'name="email"') === false, 'The account page has no email form');
    check(accountAction($headClient, $baseUrl, $newId, ['action' => 'change_email', 'email' => 'x@example.invalid'])['status'] === 422, 'A forged email change is an unknown action');

    showStep('Switch two of the new account\'s permissions off, then back on.');
    // one made-up unpublished record, so the record page's buttons can be checked
    $records = new ThesisRecords($pdo);
    $thesisId = $records->createThesis([
        'title' => 'Verify Staff Accounts ' . $suffix, 'authors' => [['given_names' => 'Sample', 'last_name' => 'Verifier', 'suffix' => null, 'full_name' => 'Sample Verifier']],
        'publication_year' => 2025, 'program' => 'BS Verification Studies', 'department' => null, 'campus' => 'WCC QC', 'adviser' => null,
        'abstract' => null, 'keywords' => null, 'date_submitted' => null, 'final_defense_date' => null, 'page_count' => null, 'is_public' => false,
    ], $headId);
    $recordPage = requestPage($newClient, $baseUrl, 'admin_thesis.php?id=' . $thesisId);
    check(strpos($recordPage['body'], 'value="publish"') !== false && strpos($recordPage['body'], 'href="admin_completion_list.php"') !== false, 'Before: Publish button and completion list link shown');
    $switchesPage = requestPage($headClient, $baseUrl, 'admin_account.php?id=' . $newId);
    check(substr_count($switchesPage['body'], 'type="checkbox" role="switch"') === count(Access::SWITCHABLE_PERMISSIONS) + 2, 'Account page shows a switch per permission plus the two fixed ones');
    $allOn = array_keys(Access::SWITCHABLE_PERMISSIONS);
    $someOff = array_values(array_diff($allOn, ['thesis.publish', 'report.completion_list']));
    // a modified form also sends a made-up permission and "Manage staff accounts"; both must be ignored
    $saved = accountAction($headClient, $baseUrl, $newId, ['action' => 'change_permissions', 'permissions' => array_merge($someOff, ['account.manage', 'thesis.delete'])]);
    check(redirectsTo($saved, 'admin_account.php?id=' . $newId), 'Permissions saved');
    $revoked = $pdo->prepare('SELECT permission_code, revoked_by FROM user_revoked_permissions WHERE user_id = :id ORDER BY permission_code');
    $revoked->execute(['id' => $newId]);
    $revokedRows = $revoked->fetchAll(PDO::FETCH_ASSOC);
    check(array_column($revokedRows, 'permission_code') === ['report.completion_list', 'thesis.publish'] && (int) $revokedRows[0]['revoked_by'] === $headId, 'Exactly the two switched-off permissions stored, with who did it');
    check(requestPage($newClient, $baseUrl, 'admin_accounts.php')['status'] === 403, 'The sent "Manage staff accounts" switch gave nothing');
    $afterPage = requestPage($headClient, $baseUrl, 'admin_account.php?id=' . $newId);
    check(strpos($afterPage['body'], 'Permissions saved.') !== false
        && preg_match('/value="thesis\.publish"\s+aria-describedby="[^"]+"\s*>/', $afterPage['body']) === 1, 'The publish switch now shows as off');
    check(requestPage($newClient, $baseUrl, 'admin_completion_list.php')['status'] === 403, 'Its next click: the completion list answers 403');
    $recordPage = requestPage($newClient, $baseUrl, 'admin_thesis.php?id=' . $thesisId);
    check($recordPage['status'] === 200 && strpos($recordPage['body'], 'value="publish"') === false
        && strpos($recordPage['body'], 'href="admin_completion_list.php"') === false, 'Publish button and completion list link hidden');
    check(strpos($recordPage['body'], 'value="archive"') !== false && strpos($recordPage['body'], '>Edit</a>') !== false, 'Permissions still on keep their buttons');
    $publish = requestPage($newClient, $baseUrl, 'admin_thesis.php?id=' . $thesisId, ['action' => 'publish', 'csrf_token' => tokenFrom($recordPage)]);
    $isPublic = $pdo->prepare('SELECT is_public FROM theses WHERE id = :id');
    $isPublic->execute(['id' => $thesisId]);
    check($publish['status'] === 403 && (int) $isPublic->fetchColumn() === 0, 'A sent "publish" is refused and the record stays unpublished');
    $ownSwitches = accountAction($headClient, $baseUrl, $headId, ['action' => 'change_permissions', 'permissions' => []]);
    $ownRows = $pdo->prepare('SELECT COUNT(*) FROM user_revoked_permissions WHERE user_id = :id');
    $ownRows->execute(['id' => $headId]);
    check(redirectsTo($ownSwitches, 'admin_account.php') && (int) $ownRows->fetchColumn() === 0
        && strpos(requestPage($headClient, $baseUrl, 'admin_account.php?id=' . $headId)['body'], 'You cannot do this to your own account.') !== false, 'Own permissions cannot be switched');
    accountAction($headClient, $baseUrl, $newId, ['action' => 'change_permissions', 'permissions' => $allOn]);
    $revoked->execute(['id' => $newId]);
    check(count($revoked->fetchAll()) === 0, 'All switched back on: no rows left');
    check(requestPage($newClient, $baseUrl, 'admin_completion_list.php')['status'] === 200, 'The completion list opens again');
    $unchanged = accountAction($headClient, $baseUrl, $newId, ['action' => 'change_permissions', 'permissions' => $allOn]);
    check(strpos(requestPage($headClient, $baseUrl, 'admin_account.php?id=' . $newId)['body'], 'Nothing changed.') !== false && redirectsTo($unchanged, 'admin_account.php'), 'Saving the same switches changes nothing');

    showStep('Reset the new account\'s password.');
    sleep(1); // the reset must be at least one second after the new account's login, so its session counts as older
    $reset = accountAction($headClient, $baseUrl, $newId, ['action' => 'reset_password']);
    check(redirectsTo($reset, 'admin_account.php?id=' . $newId), 'Reset password goes back to the account page');
    $resetPassword = temporaryPasswordOn(requestPage($headClient, $baseUrl, 'admin_account.php?id=' . $newId));
    check($resetPassword !== '' && $resetPassword !== $temporaryPassword, 'A new temporary password is shown once');
    check(redirectsTo(requestPage($newClient, $baseUrl, 'admin.php'), 'login.php?password_reset=1'), 'The open session ends on its next click');
    check(submitLogin(newClient(), $baseUrl, $newUsername, $ownPassword)['status'] === 401, 'The old password no longer works');
    $resetClient = newClient();
    check(submitLogin($resetClient, $baseUrl, $newUsername, $resetPassword)['status'] === 303, 'The new temporary password works');
    check(redirectsTo(requestPage($resetClient, $baseUrl, 'admin.php'), 'admin_password.php'), 'It must again choose its own password');

    showStep('Disable and enable the new account.');
    $disable = accountAction($headClient, $baseUrl, $newId, ['action' => 'disable']);
    check(redirectsTo($disable, 'admin_account.php') && (int) userRow($pdo, $newUsername)['is_active'] === 0, 'Account disabled');
    check(redirectsTo(requestPage($resetClient, $baseUrl, 'admin_password.php'), 'login.php?ended=1'), 'Its open session ends on its next click');
    check(submitLogin(newClient(), $baseUrl, $newUsername, $resetPassword)['status'] === 401, 'A disabled account cannot log in');
    $list = requestPage($headClient, $baseUrl, 'admin_accounts.php');
    check(strpos($list['body'], 'Disabled') !== false, 'Account list shows it as disabled');
    $enable = accountAction($headClient, $baseUrl, $newId, ['action' => 'enable']);
    check(redirectsTo($enable, 'admin_account.php') && (int) userRow($pdo, $newUsername)['is_active'] === 1, 'Account enabled');
    check(submitLogin(newClient(), $baseUrl, $newUsername, $resetPassword)['status'] === 303, 'It can log in again');
    unset($newClient, $resetClient);

    showStep('Try to change the head administrator\'s own account.');
    $ownPage = requestPage($headClient, $baseUrl, 'admin_account.php?id=' . $headId);
    check(strpos($ownPage['body'], 'value="disable"') === false && strpos($ownPage['body'], 'value="reset_password"') === false
        && strpos($ownPage['body'], 'value="change_role"') === false, 'Own account page has no role, reset or disable buttons');
    foreach ([['action' => 'disable'], ['action' => 'reset_password'], ['action' => 'change_role', 'role_code' => 'rdpo_admin']] as $fields) {
        accountAction($headClient, $baseUrl, $headId, $fields);
        $after = requestPage($headClient, $baseUrl, 'admin_account.php?id=' . $headId);
        check(strpos($after['body'], 'You cannot do this to your own account.') !== false, 'A sent "' . $fields['action'] . '" on your own account is refused');
    }
    $headNow = userRow($pdo, $headUsername);
    check((int) $headNow['is_active'] === 1 && $headNow['role_code'] === 'rdpo_head' && (int) $headNow['must_change_password'] === 0, 'Head administrator account unchanged');

    showStep('Send modified requests and read the audit log.');
    $noToken = requestPage($headClient, $baseUrl, 'admin_account.php?id=' . $newId, ['action' => 'disable']);
    check($noToken['status'] === 403 && (int) userRow($pdo, $newUsername)['is_active'] === 1, 'A form without its token is refused');
    check(accountAction($headClient, $baseUrl, $newId, ['action' => 'delete'])['status'] === 422, 'An unknown action is refused (accounts cannot be deleted)');
    check(requestPage($headClient, $baseUrl, 'admin_account.php?id=999999999999')['status'] === 404, 'An unknown account answers 404');
    check(requestPage($headClient, $baseUrl, 'admin_account.php?id=abc')['status'] === 404, 'A non-number id answers 404');
    $events = $pdo->prepare("SELECT action_code, actor_user_id, changed_fields FROM audit_logs WHERE entity_type = 'user' AND entity_id = :id AND action_code LIKE 'account.%' ORDER BY id");
    $events->execute(['id' => $newId]);
    $codes = [];
    $onlyFieldNames = true;
    foreach ($events->fetchAll(PDO::FETCH_ASSOC) as $event) {
        $codes[] = $event['action_code'];
        // permission changes list the permission codes that moved, which are not secret
        $allowedFields = ['role', 'username', 'password', 'report.completion_list, thesis.publish'];
        if ($event['changed_fields'] !== null && !in_array($event['changed_fields'], $allowedFields, true)) {
            $onlyFieldNames = false;
        }
    }
    // two_factor_enabled / two_factor_reset: while it was a head administrator it linked an app, which was then reset
    check($codes === ['account.created', 'account.password_changed', 'account.role_changed', 'account.two_factor_enabled', 'account.role_changed',
        'account.two_factor_reset', 'account.username_changed', 'account.username_changed',
        'account.permissions_changed', 'account.permissions_changed',
        'account.password_reset', 'account.disabled', 'account.enabled'], 'Each change written to the history once, in order');
    check($onlyFieldNames, 'History holds field names only, never a password');
    $history = requestPage($headClient, $baseUrl, 'admin_account.php?id=' . $newId);
    check(strpos($history['body'], 'Password reset by a head administrator') !== false && strpos($history['body'], 'Changed own password') !== false, 'Account page shows the history');
} catch (Throwable $exception) {
    $failed = true;
    echo 'FAIL: ' . $exception->getMessage() . "\n";
} finally {
    // remove only what this script created, in the order the foreign keys require:
    // permission switches and the made-up record -> audit rows -> accounts
    echo "\nCLEANUP: Remove this script's record, permission switches, accounts, audit rows and failed-login counters.\n";
    putenv('SCHOLAR_ADMIN_PASSWORD');
    unset($headClient);
    $identifiers = [$headUsername, $adminUsername, $newUsername];
    foreach ($identifiers as $identifier) {
        $pdo->prepare('DELETE FROM failed_logins WHERE account_key = :account_key')->execute(['account_key' => LoginThrottle::accountKey($identifier)]);
    }
    foreach ($usernames as $username) {
        $lookup = $pdo->prepare('SELECT id FROM users WHERE username = :username');
        $lookup->execute(['username' => $username]);
        $userId = $lookup->fetchColumn();
        if ($userId) {
            $pdo->prepare('DELETE FROM user_revoked_permissions WHERE user_id = :user_id OR revoked_by = :revoked_by')
                ->execute(['user_id' => $userId, 'revoked_by' => $userId]);
            $thesisIds = $pdo->prepare('SELECT id FROM theses WHERE created_by = :user_id AND title = :title');
            $thesisIds->execute(['user_id' => $userId, 'title' => 'Verify Staff Accounts ' . $suffix]);
            foreach ($thesisIds->fetchAll(PDO::FETCH_COLUMN) as $thesisId) {
                $pdo->prepare("DELETE FROM audit_logs WHERE entity_type = 'thesis' AND entity_id = :id")->execute(['id' => $thesisId]);
                $pdo->prepare('DELETE FROM thesis_authors WHERE thesis_id = :id')->execute(['id' => $thesisId]);
                $pdo->prepare('DELETE FROM theses WHERE id = :id')->execute(['id' => $thesisId]);
            }
        }
    }
    foreach ($usernames as $username) {
        $lookup = $pdo->prepare('SELECT id FROM users WHERE username = :username');
        $lookup->execute(['username' => $username]);
        $userId = $lookup->fetchColumn();
        if ($userId) {
            $pdo->prepare("DELETE FROM audit_logs WHERE actor_user_id = :actor_id OR (entity_type = 'user' AND entity_id = :entity_id)")
                ->execute(['actor_id' => $userId, 'entity_id' => $userId]);
            forgetTestTwoFactor($pdo, [(int) $userId]); // backup codes and remembered devices go before the account
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
        }
    }
    $left = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username IN (:a, :b, :c)');
    $left->execute(['a' => $headUsername, 'b' => $adminUsername, 'c' => $newUsername]);
    if ((int) $left->fetchColumn() !== 0) {
        $failed = true;
        echo "FAIL: cleanup left temporary accounts behind\n";
    } else {
        echo "PASS: temporary accounts removed\n";
    }
}

if ($failed) {
    echo "RESULT: staff account checks failed.\n";
    exit(1);
}
echo "RESULT: staff account checks passed.\n";
exit(0);
