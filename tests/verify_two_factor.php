<?php
// WHAT THIS FILE DOES: automatically checks two-step login (user's request, 2026-11-10): linking an authenticator app,
// the code step at login, backup codes, "remember this device for 30 days", and the emergency terminal command.
// It sends requests to our local Apache like a browser, using made up accounts only, and never prints a code or password.

// STEP 1: Allow terminal use only, load the application, and connect to the development database.
// STEP 2: Create a temporary head administrator (with "set-role") and a temporary RDPO administrator.
// STEP 3: The public pages' "RDPO login" button goes to the records page, which sends visitors to the login page.
// STEP 4: The head administrator logs in with the password only (no app yet); every RDPO page sends it to
//         "Two-step login", whose menu shows no other pages, and which shows a QR code and a typed key.
// STEP 5: A wrong code is refused; the right code links the app. The 10 backup codes are shown once only, and the
//         database holds only the encrypted secret and password hashes of the codes.
// STEP 6: Logging in now asks for the code: no RDPO page opens before it, a wrong code is refused, the right code
//         logs in, and the same code cannot be used a second time.
// STEP 7: A backup code logs in once and says how many are left; the same backup code is refused afterwards.
// STEP 8: "Remember this device" skips the code on that browser only, until "Forget all remembered devices".
// STEP 9: A new set of backup codes needs the password and makes the old set stop working; using the last code
//         warns that none are left.
// STEP 10: Too many wrong codes send the person back to the password; an expired or missing login goes to login.php.
// STEP 11: An RDPO administrator is not forced, can turn two-step login on and off with the password; a head
//          administrator cannot turn it off.
// STEP 12: Changing the password needs a 2FA code too (an app code, or a backup code for head administrators);
//          a missing or wrong code changes nothing. A temporary password is changed without a code (STEP 13).
// STEP 13: "emergency-access" does nothing without --confirm; with it, the old session ends, the app, codes and remembered
//          devices stop working, the temporary password works, and after a new password the app must be linked again.
// STEP 14: The audit log holds the two-step actions with no codes, secrets or passwords.
// STEP 15: In cleanup, remove only this script's accounts, codes, remembered devices, audit rows and failed-login counters.

// Each check prints PASS; a failed check stops the remaining checks and reports failure.
// Cleanup is attempted in finally even when a check fails after preparation starts.
// The terminal exit code is 0 for success or 1 for a caught test failure.

declare(strict_types=1); // reject incorrect types passed into the test helpers

use Scholar\Auth\LoginThrottle;
use Scholar\Auth\TwoFactor;

// run this script from the terminal; it is not a browser page
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/two_factor_helper.php';

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
    return requestPage($client, $baseUrl, 'login.php', ['identifier' => $identifier, 'password' => $password, 'csrf_token' => tokenFrom($login)]);
}

// types a code on login_code.php; $method is 'app' or 'backup'; $remember ticks "remember this device"
function submitCode(CurlHandle $client, string $baseUrl, string $code, string $method, bool $remember): array {
    $page = 'login_code.php';
    if ($method === 'backup') {
        $page = 'login_code.php?backup=1';
    }
    $form = requestPage($client, $baseUrl, $page);
    $fields = ['code' => $code, 'method' => $method, 'csrf_token' => tokenFrom($form)];
    if ($remember) {
        $fields['remember_device'] = '1';
    }
    return requestPage($client, $baseUrl, $page, $fields);
}

// sends one form of the Two-step login page with a fresh token from that page
function twoFactorAction(CurlHandle $client, string $baseUrl, array $fields): array {
    $page = requestPage($client, $baseUrl, 'admin_two_factor.php');
    $fields['csrf_token'] = tokenFrom($page);
    return requestPage($client, $baseUrl, 'admin_two_factor.php', $fields);
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

// the backup codes listed on the page (only right after they are made), e.g. ['k7mqx-3pahr', ...]
function backupCodesOn(array $response): array {
    preg_match_all('/<li class="col">([a-hjkmnp-z2-9]{5}-[a-hjkmnp-z2-9]{5})<\/li>/', $response['body'], $matches);
    return $matches[1];
}

// the typed key under "Cannot scan it?", with the spaces removed
function setupKeyOn(array $response): string {
    if (!preg_match('/<p class="font-monospace fs-6 text-break mb-0">([A-Z2-7 ]+)<\/p>/', $response['body'], $matches)) {
        return '';
    }
    return str_replace(' ', '', $matches[1]);
}

// runs one bin/console.php command and returns [exit code, its printed output]; the output is never echoed,
// because it holds a temporary password
function runConsole(array $arguments): array {
    // proc_open starts a child PHP command; the three pipes are its input/output/error streams
    $process = proc_open(array_merge([PHP_BINARY, __DIR__ . '/../bin/console.php'], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output];
}

function accountId(PDO $pdo, string $username): int {
    $statement = $pdo->prepare('SELECT id FROM users WHERE username = :username');
    $statement->execute(['username' => $username]);
    return (int) $statement->fetchColumn();
}

// the two-step columns of one account, and how many backup codes and remembered devices it has
function twoFactorState(PDO $pdo, int $userId): array {
    $statement = $pdo->prepare('SELECT totp_secret_encrypted, totp_enabled_at, must_change_password FROM users WHERE id = :id');
    $statement->execute(['id' => $userId]);
    $state = $statement->fetch(PDO::FETCH_ASSOC);
    $statement = $pdo->prepare('SELECT COUNT(*) FROM user_backup_codes WHERE user_id = :id AND used_at IS NULL');
    $statement->execute(['id' => $userId]);
    $state['unused_codes'] = (int) $statement->fetchColumn();
    $statement = $pdo->prepare('SELECT COUNT(*) FROM user_remembered_devices WHERE user_id = :id');
    $statement->execute(['id' => $userId]);
    $state['devices'] = (int) $statement->fetchColumn();
    return $state;
}

// a code is accepted once per 30-second window; tests log in many times within seconds, so the mark is cleared
function allowCodeAgain(PDO $pdo, int $userId): void {
    $pdo->prepare('UPDATE users SET totp_last_step = NULL WHERE id = :id')->execute(['id' => $userId]);
}

// the RDPO page a logged-in head administrator lands on
function isLoggedIn(CurlHandle $client, string $baseUrl): bool {
    return requestPage($client, $baseUrl, 'admin.php')['status'] === 200;
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
$headUsername = 'verify_2fa_head_' . $suffix;
$adminUsername = 'verify_2fa_staff_' . $suffix;
$usernames = [$headUsername, $adminUsername];
$password = bin2hex(random_bytes(20)); // never print or commit passwords
$failed = false;
try {
    echo "PREPARATION: Create this script's temporary accounts.\n";
    putenv('SCHOLAR_ADMIN_PASSWORD=' . $password);
    check(runConsole(['provision-admin', $headUsername])[0] === 0, 'CLI provisions the future head administrator');
    check(runConsole(['provision-admin', $adminUsername])[0] === 0, 'CLI provisions an RDPO administrator');
    putenv('SCHOLAR_ADMIN_PASSWORD');
    check(runConsole(['set-role', $headUsername, 'rdpo_head'])[0] === 0, 'CLI set-role makes the head administrator');
    $headId = accountId($pdo, $headUsername);
    $adminId = accountId($pdo, $adminUsername);

    showStep('The public "RDPO login" button goes to the records page.');
    $anonymous = newClient();
    $publicPage = requestPage($anonymous, $baseUrl, 'index.php');
    check(strpos($publicPage['body'], 'href="admin.php">RDPO login</a>') !== false && strpos($publicPage['body'], 'href="login.php"') === false, 'Search page links to admin.php');
    check(redirectsTo(requestPage($anonymous, $baseUrl, 'admin.php'), 'login.php'), 'Records page sends a visitor to the login page');
    check(redirectsTo(requestPage($anonymous, $baseUrl, 'login_code.php'), 'login.php?code_expired=1'), 'Code page without a password first goes back to login');

    showStep('A head administrator without an app is sent to link one.');
    $headClient = newClient();
    check(redirectsTo(submitLogin($headClient, $baseUrl, $headUsername, $password), 'admin.php'), 'Password alone logs in while no app is linked yet');
    check(redirectsTo(requestPage($headClient, $baseUrl, 'admin.php'), 'admin_two_factor.php'), 'Records page sends it to Two-step login');
    check(redirectsTo(requestPage($headClient, $baseUrl, 'admin_accounts.php'), 'admin_two_factor.php'), 'Staff accounts page sends it to Two-step login');
    $setupPage = requestPage($headClient, $baseUrl, 'admin_two_factor.php');
    check($setupPage['status'] === 200 && strpos($setupPage['body'], 'Head administrators must use two-factor authentication (2FA)') !== false, 'Setup page explains it is required');
    check(strpos($setupPage['body'], 'href="admin_accounts.php"') === false && strpos($setupPage['body'], 'Log out') !== false, 'Menu offers only Log out until it is linked');
    check(strpos($setupPage['body'], '<svg') !== false && strpos($setupPage['body'], '<?xml') === false, 'The QR code is drawn on the page');
    $setupKey = setupKeyOn($setupPage);
    check(strlen($setupKey) === 32, 'The typed key is shown for people who cannot scan');
    check(setupKeyOn(requestPage($headClient, $baseUrl, 'admin_two_factor.php')) === $setupKey, 'Refreshing keeps the same QR code');

    showStep('Link the app: a wrong code is refused, the right one turns it on and shows the backup codes once.');
    $wrongSetupCode = testAppCode($setupKey) === '000000' ? '111111' : '000000';
    $wrongSetup = twoFactorAction($headClient, $baseUrl, ['action' => 'confirm_setup', 'code' => $wrongSetupCode]);
    check($wrongSetup['status'] === 422 && twoFactorState($pdo, $headId)['totp_enabled_at'] === null, 'Wrong code refused, still off');
    $linked = twoFactorAction($headClient, $baseUrl, ['action' => 'confirm_setup', 'code' => testAppCode($setupKey)]);
    check(redirectsTo($linked, 'admin_two_factor.php') && twoFactorState($pdo, $headId)['totp_enabled_at'] !== null, 'Right code turns two-step login on');
    $headSecret = $setupKey;
    $codesPage = requestPage($headClient, $baseUrl, 'admin_two_factor.php');
    $backupCodes = backupCodesOn($codesPage);
    check(count($backupCodes) === 10 && count(array_unique($backupCodes)) === 10, '10 different backup codes are shown');
    check(backupCodesOn(requestPage($headClient, $baseUrl, 'admin_two_factor.php')) === [], 'They are not shown a second time');
    $state = twoFactorState($pdo, $headId);
    check($state['unused_codes'] === 10, '10 unused codes saved');
    check(strpos($state['totp_secret_encrypted'], $headSecret) === false, 'The database holds the secret encrypted, not the secret itself');
    $storedCodeHashes = $pdo->prepare('SELECT code_hash FROM user_backup_codes WHERE user_id = :id');
    $storedCodeHashes->execute(['id' => $headId]);
    $hashText = implode(' ', $storedCodeHashes->fetchAll(PDO::FETCH_COLUMN));
    check(strpos($hashText, TwoFactor::cleanBackupCode($backupCodes[0])) === false && strpos($hashText, '$2y$') !== false, 'Backup codes are stored as password hashes only');
    check(isLoggedIn($headClient, $baseUrl), 'Linked: the records page opens');

    showStep('Logging in now asks for the code.');
    $codeClient = newClient();
    check(redirectsTo(submitLogin($codeClient, $baseUrl, $headUsername, $password), 'login_code.php'), 'Right password goes to the code page');
    check(redirectsTo(requestPage($codeClient, $baseUrl, 'admin.php'), 'login.php'), 'No RDPO page opens before the code');
    $wrongCode = testAppCode($headSecret) === '000000' ? '111111' : '000000';
    check(submitCode($codeClient, $baseUrl, $wrongCode, 'app', false)['status'] === 401, 'Wrong code refused');
    allowCodeAgain($pdo, $headId);
    $goodCode = testAppCode($headSecret);
    check(redirectsTo(submitCode($codeClient, $baseUrl, $goodCode, 'app', false), 'admin.php') && isLoggedIn($codeClient, $baseUrl), 'Right code logs in');
    $replayClient = newClient();
    submitLogin($replayClient, $baseUrl, $headUsername, $password);
    check(submitCode($replayClient, $baseUrl, $goodCode, 'app', false)['status'] === 401, 'The same code cannot be used a second time');

    showStep('A backup code logs in once.');
    $backupClient = newClient();
    submitLogin($backupClient, $baseUrl, $headUsername, $password);
    check(strpos(requestPage($backupClient, $baseUrl, 'login_code.php')['body'], 'href="login_code.php?backup=1"') !== false, 'Code page offers a backup code to a head administrator');
    $usedBackup = submitCode($backupClient, $baseUrl, strtoupper($backupCodes[0]), 'backup', false);
    check(redirectsTo($usedBackup, 'admin.php'), 'Backup code (typed in capitals) logs in');
    check(strpos(requestPage($backupClient, $baseUrl, 'admin.php')['body'], 'You have 9 left.') !== false, 'It says how many are left');
    check(twoFactorState($pdo, $headId)['unused_codes'] === 9, 'That code is used up');
    $againClient = newClient();
    submitLogin($againClient, $baseUrl, $headUsername, $password);
    check(submitCode($againClient, $baseUrl, $backupCodes[0], 'backup', false)['status'] === 401, 'The same backup code is refused afterwards');
    $pdo->prepare('DELETE FROM failed_logins WHERE account_key = :key')->execute(['key' => LoginThrottle::accountKey($headUsername)]);

    showStep('"Remember this device" skips the code on that browser only.');
    $rememberedClient = newClient();
    submitLogin($rememberedClient, $baseUrl, $headUsername, $password);
    allowCodeAgain($pdo, $headId);
    $remembered = submitCode($rememberedClient, $baseUrl, testAppCode($headSecret), 'app', true);
    check(redirectsTo($remembered, 'admin.php') && preg_match('/Set-Cookie: scholar_device=[0-9a-f]{64};[^\r\n]*HttpOnly/i', $remembered['headers']) === 1, 'Ticking it sends a long HttpOnly cookie');
    $deviceRow = $pdo->prepare('SELECT token_hash, DATEDIFF(expires_at, NOW()) AS days FROM user_remembered_devices WHERE user_id = :id');
    $deviceRow->execute(['id' => $headId]);
    $device = $deviceRow->fetch(PDO::FETCH_ASSOC);
    preg_match('/Set-Cookie: scholar_device=([0-9a-f]{64})/i', $remembered['headers'], $cookieMatch);
    check($device['token_hash'] === hash('sha256', $cookieMatch[1]) && (int) $device['days'] >= 29 && (int) $device['days'] <= 30, 'Only a hash is stored, for 30 days');
    $logoutPage = requestPage($rememberedClient, $baseUrl, 'admin.php');
    requestPage($rememberedClient, $baseUrl, 'logout.php', ['csrf_token' => tokenFrom($logoutPage)]);
    check(redirectsTo(submitLogin($rememberedClient, $baseUrl, $headUsername, $password), 'admin.php') && isLoggedIn($rememberedClient, $baseUrl), 'The same browser logs in with the password only');
    check(redirectsTo(submitLogin(newClient(), $baseUrl, $headUsername, $password), 'login_code.php'), 'Another browser is still asked for the code');
    check(redirectsTo(submitLogin($rememberedClient, $baseUrl, $headUsername, 'wrong password ' . $suffix), 'admin.php') === false, 'A remembered device still needs the right password');
    check(redirectsTo(twoFactorAction($headClient, $baseUrl, ['action' => 'forget_devices']), 'admin_two_factor.php') && twoFactorState($pdo, $headId)['devices'] === 0, 'Forget all remembered devices');
    check(redirectsTo(submitLogin($rememberedClient, $baseUrl, $headUsername, $password), 'login_code.php'), 'The forgotten browser is asked for the code again');
    $pdo->prepare('DELETE FROM failed_logins WHERE account_key = :key')->execute(['key' => LoginThrottle::accountKey($headUsername)]);

    showStep('A new set of backup codes, and running out of codes.');
    check(twoFactorAction($headClient, $baseUrl, ['action' => 'new_backup_codes', 'current_password' => 'wrong ' . $suffix])['status'] === 422, 'A wrong password makes no new codes');
    check(redirectsTo(twoFactorAction($headClient, $baseUrl, ['action' => 'new_backup_codes', 'current_password' => $password]), 'admin_two_factor.php'), 'The right password makes new codes');
    $newCodes = backupCodesOn(requestPage($headClient, $baseUrl, 'admin_two_factor.php'));
    check(count($newCodes) === 10 && array_intersect($newCodes, $backupCodes) === [], '10 new, different codes are shown once');
    $oldCodeClient = newClient();
    submitLogin($oldCodeClient, $baseUrl, $headUsername, $password);
    check(submitCode($oldCodeClient, $baseUrl, $backupCodes[1], 'backup', false)['status'] === 401, 'An unused code from the old set no longer works');
    // use up nine of the new codes straight in the database, then the last one through the page
    $pdo->prepare('UPDATE user_backup_codes SET used_at = NOW() WHERE user_id = :id ORDER BY id LIMIT 9')->execute(['id' => $headId]);
    $lastCodeIndex = 9; // the page lists them in the order they were saved, so the tenth one is still unused
    $lastClient = newClient();
    submitLogin($lastClient, $baseUrl, $headUsername, $password);
    check(redirectsTo(submitCode($lastClient, $baseUrl, $newCodes[$lastCodeIndex], 'backup', false), 'admin.php'), 'The last backup code logs in');
    check(strpos(requestPage($lastClient, $baseUrl, 'admin.php')['body'], 'You used your last backup code.') !== false, 'It warns that none are left');
    check(strpos(requestPage($lastClient, $baseUrl, 'admin_two_factor.php')['body'], 'You have no backup codes left.') !== false, 'The Two-step login page says so too');
    $pdo->prepare('DELETE FROM failed_logins WHERE account_key = :key')->execute(['key' => LoginThrottle::accountKey($headUsername)]);

    showStep('Too many wrong codes, and an expired login.');
    $limits = loadConfiguration()['login'];
    $guessClient = newClient();
    submitLogin($guessClient, $baseUrl, $headUsername, $password);
    for ($try = 0; $try < $limits['account_max_failures']; $try++) {
        submitCode($guessClient, $baseUrl, $wrongCode, 'app', false);
    }
    check(redirectsTo(submitCode($guessClient, $baseUrl, $wrongCode, 'app', false), 'login.php?code_blocked=1'), 'After the limit, the code page sends the person back to the password');
    check(redirectsTo(requestPage($guessClient, $baseUrl, 'login_code.php'), 'login.php?code_expired=1'), 'The half-finished login is gone');
    check(submitLogin($guessClient, $baseUrl, $headUsername, $password)['status'] === 429, 'The password is refused too until the wait is over');
    $pdo->prepare('DELETE FROM failed_logins WHERE account_key = :key')->execute(['key' => LoginThrottle::accountKey($headUsername)]);

    showStep('RDPO administrators may choose; head administrators may not switch it off.');
    $adminClient = newClient();
    check(redirectsTo(submitLogin($adminClient, $baseUrl, $adminUsername, $password), 'admin.php') && isLoggedIn($adminClient, $baseUrl), 'An RDPO administrator logs in without an app');
    $adminSetup = requestPage($adminClient, $baseUrl, 'admin_two_factor.php');
    check(strpos($adminSetup['body'], '2FA is <strong>off</strong>') !== false && strpos($adminSetup['body'], 'you will get 10 backup codes') === false, 'Its page offers the app, without backup codes');
    $adminSecret = setupKeyOn($adminSetup);
    twoFactorAction($adminClient, $baseUrl, ['action' => 'confirm_setup', 'code' => testAppCode($adminSecret)]);
    check(twoFactorState($pdo, $adminId)['totp_enabled_at'] !== null && twoFactorState($pdo, $adminId)['unused_codes'] === 0, 'It can turn it on (and gets no backup codes)');
    check(twoFactorAction($adminClient, $baseUrl, ['action' => 'turn_off', 'current_password' => 'wrong ' . $suffix])['status'] === 422, 'Turning it off needs the right password');
    check(redirectsTo(twoFactorAction($adminClient, $baseUrl, ['action' => 'turn_off', 'current_password' => $password]), 'admin_two_factor.php')
        && twoFactorState($pdo, $adminId)['totp_enabled_at'] === null, 'With the password it is turned off');
    check(twoFactorAction($headClient, $baseUrl, ['action' => 'turn_off', 'current_password' => $password])['status'] === 422
        && twoFactorState($pdo, $headId)['totp_enabled_at'] !== null, 'A head administrator cannot turn it off');

    showStep('Changing the password needs a 2FA code.');
    $changedPassword = 'changed own ' . bin2hex(random_bytes(8));
    $passwordForm = requestPage($headClient, $baseUrl, 'admin_password.php');
    check(strpos($passwordForm['body'], 'name="two_factor_code"') !== false && strpos($passwordForm['body'], 'or one of your backup codes') !== false, 'The password page asks for a 2FA code (or a backup code)');
    $passwordFields = ['current_password' => $password, 'new_password' => $changedPassword, 'confirm_password' => $changedPassword];
    $noCode = requestPage($headClient, $baseUrl, 'admin_password.php', $passwordFields + ['csrf_token' => tokenFrom($passwordForm)]);
    check($noCode['status'] === 422 && strpos($noCode['body'], 'The 2FA code is not correct.') !== false, 'Without a code the password is not changed');
    $wrongCodeChange = requestPage($headClient, $baseUrl, 'admin_password.php', $passwordFields + ['two_factor_code' => $wrongCode, 'csrf_token' => tokenFrom($passwordForm)]);
    check($wrongCodeChange['status'] === 422, 'With a wrong code it is not changed');
    check(submitLogin(newClient(), $baseUrl, $headUsername, $password)['status'] === 303, 'The old password still works');
    allowCodeAgain($pdo, $headId);
    $withCode = requestPage($headClient, $baseUrl, 'admin_password.php', $passwordFields + ['two_factor_code' => testAppCode($headSecret), 'csrf_token' => tokenFrom($passwordForm)]);
    check(redirectsTo($withCode, 'admin.php') && submitLogin(newClient(), $baseUrl, $headUsername, $changedPassword)['status'] === 303, 'With the app code it is changed');
    // and back with a backup code (a new set first, because the last set was used up above)
    twoFactorAction($headClient, $baseUrl, ['action' => 'new_backup_codes', 'current_password' => $changedPassword]);
    $passwordCodes = backupCodesOn(requestPage($headClient, $baseUrl, 'admin_two_factor.php'));
    $passwordForm = requestPage($headClient, $baseUrl, 'admin_password.php');
    $backWithBackup = requestPage($headClient, $baseUrl, 'admin_password.php', [
        'current_password' => $changedPassword, 'new_password' => $password, 'confirm_password' => $password,
        'two_factor_code' => $passwordCodes[0], 'csrf_token' => tokenFrom($passwordForm),
    ]);
    check(redirectsTo($backWithBackup, 'admin.php') && twoFactorState($pdo, $headId)['unused_codes'] === 9, 'A backup code works too, and is used up');
    $pdo->prepare('DELETE FROM failed_logins WHERE account_key = :key')->execute(['key' => LoginThrottle::accountKey($headUsername)]);

    showStep('The emergency terminal command.');
    // sessions end when the reset is NEWER than their login (in whole seconds), and the password change above just
    // gave this browser a fresh login time, so wait one second for the emergency reset to be clearly later
    sleep(1);
    $before = twoFactorState($pdo, $headId);
    check(runConsole(['emergency-access', $headUsername])[0] === 2 && twoFactorState($pdo, $headId) == $before, 'Without --confirm nothing changes');
    check(runConsole(['emergency-access', 'verify_nobody_' . $suffix, '--confirm'])[0] === 1, 'An unknown account is reported');
    // remember this browser first, to see that the emergency also forgets it
    $emergencyBrowser = newClient();
    submitLogin($emergencyBrowser, $baseUrl, $headUsername, $password);
    allowCodeAgain($pdo, $headId);
    submitCode($emergencyBrowser, $baseUrl, testAppCode($headSecret), 'app', true);
    [$exitCode, $output] = runConsole(['emergency-access', $headUsername, '--confirm']);
    $temporaryPassword = '';
    if (preg_match('/Temporary password for [^:]+: ([a-z0-9-]{19})/', $output, $matches)) {
        $temporaryPassword = $matches[1];
    }
    check($exitCode === 0 && $temporaryPassword !== '', 'With --confirm it prints a temporary password');
    $after = twoFactorState($pdo, $headId);
    check($after['totp_enabled_at'] === null && $after['totp_secret_encrypted'] === null && $after['unused_codes'] === 0 && $after['devices'] === 0
        && (int) $after['must_change_password'] === 1, 'The app, backup codes and remembered devices are gone, and the password is temporary');
    check(redirectsTo(requestPage($headClient, $baseUrl, 'admin.php'), 'login.php?password_reset=1'), 'The old session ended');
    check(submitLogin(newClient(), $baseUrl, $headUsername, $password)['status'] === 401, 'The old password no longer works');
    $recovered = newClient();
    check(redirectsTo(submitLogin($emergencyBrowser, $baseUrl, $headUsername, $temporaryPassword), 'admin.php'), 'The temporary password logs in without a code, even on the once-remembered browser');
    check(redirectsTo(submitLogin($recovered, $baseUrl, $headUsername, $temporaryPassword), 'admin.php'), 'The temporary password works in a new browser');
    check(redirectsTo(requestPage($recovered, $baseUrl, 'admin.php'), 'admin_password.php'), 'First it must choose a new password');
    check(redirectsTo(requestPage($recovered, $baseUrl, 'admin_two_factor.php'), 'admin_password.php'), 'Two-step login waits until the password is chosen');
    $newPassword = 'emergency own ' . bin2hex(random_bytes(8));
    $passwordPage = requestPage($recovered, $baseUrl, 'admin_password.php');
    requestPage($recovered, $baseUrl, 'admin_password.php', [
        'current_password' => $temporaryPassword, 'new_password' => $newPassword, 'confirm_password' => $newPassword, 'csrf_token' => tokenFrom($passwordPage),
    ]);
    check(redirectsTo(requestPage($recovered, $baseUrl, 'admin.php'), 'admin_two_factor.php'), 'Then it must link an authenticator app again');
    $relinkKey = setupKeyOn(requestPage($recovered, $baseUrl, 'admin_two_factor.php'));
    check($relinkKey !== '' && $relinkKey !== $headSecret, 'With a new QR code');
    twoFactorAction($recovered, $baseUrl, ['action' => 'confirm_setup', 'code' => testAppCode($relinkKey)]);
    check(count(backupCodesOn(requestPage($recovered, $baseUrl, 'admin_two_factor.php'))) === 10 && isLoggedIn($recovered, $baseUrl), 'Linked again, with 10 new backup codes');

    showStep('The audit log holds the two-step actions, with no codes, secrets or passwords.');
    $audit = $pdo->prepare("SELECT action_code, actor_user_id, changed_fields FROM audit_logs WHERE entity_type = 'user' AND entity_id = :id");
    $audit->execute(['id' => $headId]);
    $auditRows = $audit->fetchAll(PDO::FETCH_ASSOC);
    $auditCodes = array_column($auditRows, 'action_code');
    foreach (['account.two_factor_enabled', 'account.backup_codes_generated', 'auth.backup_code_used', 'account.remembered_devices_forgotten', 'account.emergency_access'] as $code) {
        check(in_array($code, $auditCodes, true), 'Audit has ' . $code);
    }
    $emergencyRow = null;
    foreach ($auditRows as $row) {
        if ($row['action_code'] === 'account.emergency_access') {
            $emergencyRow = $row;
        }
    }
    check($emergencyRow !== null && $emergencyRow['actor_user_id'] === null, 'The emergency entry has no actor (the terminal)');
    $auditText = implode(' ', array_filter(array_column($auditRows, 'changed_fields')));
    check(strpos($auditText, $headSecret) === false && strpos($auditText, $backupCodes[2]) === false && strpos($auditText, $temporaryPassword) === false, 'No code, secret or password is in the audit log');
    $activityPage = requestPage($recovered, $baseUrl, 'admin_activity_log.php'); // newest entries first
    check(strpos($activityPage['body'], 'Emergency access from the terminal') !== false && strpos($activityPage['body'], 'Logged in with a backup code') !== false, 'The Activity log shows readable names');

    echo "\nRESULT: two-step login checks passed.\n";
} catch (Throwable $exception) {
    $failed = true;
    echo 'FAIL: ' . $exception->getMessage() . "\n";
} finally {
    // remove only what this script created, in the order the foreign keys require:
    // codes and remembered devices -> audit rows -> accounts
    echo "\nCLEANUP: Remove this script's accounts, codes, remembered devices, audit rows and failed-login counters.\n";
    putenv('SCHOLAR_ADMIN_PASSWORD');
    foreach ($usernames as $username) {
        foreach ([$username] as $identifier) {
            $pdo->prepare('DELETE FROM failed_logins WHERE account_key = :account_key')->execute(['account_key' => LoginThrottle::accountKey($identifier)]);
        }
        $userId = accountId($pdo, $username);
        if ($userId > 0) {
            forgetTestTwoFactor($pdo, [$userId]);
            $pdo->prepare("DELETE FROM audit_logs WHERE actor_user_id = :actor_id OR (entity_type = 'user' AND entity_id = :entity_id)")
                ->execute(['actor_id' => $userId, 'entity_id' => $userId]);
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
        }
    }
}

if ($failed) {
    echo "RESULT: two-step login checks failed.\n";
    exit(1);
}
exit(0);
