<?php
// WHAT THIS FILE DOES: checks the staff account rules (M12) without a browser, session, or database.
// STEP 1: Load the real classes: Access (who may do what) and StaffAccounts (account checks and temporary passwords).
// STEP 2: Check the roles: the provisional head administrator may manage accounts, an ordinary RDPO administrator may not,
//         and both may still work on thesis records; a permission switched off for one account is refused,
//         a switch can never add what the role lacks, and a modified form cannot invent switches.
// STEP 3: Check the username rules used on the New staff account form.
// STEP 4: Check the New staff account form values, including a modified form that sends an array or an unknown role.
// STEP 5: Check the new-password rules: length in bytes, no username inside, both copies the same.
// STEP 6: Check the temporary passwords: their format, no look-alike characters, and a new one every time.
// STEP 7: Print the total result.
// Each case is worked out first and printed afterwards, so every case runs even when an earlier one fails.
// All names here are made up for testing.
declare(strict_types=1);

use Scholar\Auth\Access;
use Scholar\Auth\StaffAccounts;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

// true when both staff roles have this permission (used to check the switch list below)
function isStaffPermission(string $code): bool {
    return Access::roleAllows('rdpo_admin', $code) && Access::roleAllows('rdpo_head', $code);
}

// a normal filled-in New staff account form, changed per case with array_merge()
$goodForm = ['username' => 'sample.staff', 'role_code' => 'rdpo_admin'];

// twenty temporary passwords, to check that each one is new and none uses a look-alike character
$temporaryPasswords = [];
for ($count = 0; $count < 20; $count++) {
    $temporaryPasswords[] = StaffAccounts::temporaryPassword();
}
$allLookRight = true;
foreach ($temporaryPasswords as $temporaryPassword) {
    // four groups of four letters/digits joined by hyphens, never 0, 1, o, l or i
    if (!preg_match('/\A[a-hjkmnp-z2-9]{4}(-[a-hjkmnp-z2-9]{4}){3}\z/', $temporaryPassword)) {
        $allLookRight = false;
    }
}

$cases = [
    // ---- roles ----
    ['Head administrator may manage accounts', Access::roleAllows('rdpo_head', 'account.manage')],
    ['RDPO administrator may not manage accounts', !Access::roleAllows('rdpo_admin', 'account.manage')],
    ['Head administrator may still edit records', Access::roleAllows('rdpo_head', 'thesis.edit')],
    ['RDPO administrator may still edit records', Access::roleAllows('rdpo_admin', 'thesis.edit')],
    ['Both roles may open the completion list', Access::roleAllows('rdpo_head', 'report.completion_list') && Access::roleAllows('rdpo_admin', 'report.completion_list')],
    ['Unknown role may do nothing', !Access::roleAllows('made_up_role', 'thesis.view_all')],
    ['Only the two staff roles can be assigned', Access::staffRoleCodes() === ['rdpo_admin', 'rdpo_head']],

    // ---- permission switches for one account ----
    ['Account with nothing switched off has what its role gives', Access::allows(['role_code' => 'rdpo_admin', 'revoked_permissions' => []], 'thesis.publish')],
    ['Switched-off permission is refused', !Access::allows(['role_code' => 'rdpo_admin', 'revoked_permissions' => ['thesis.publish']], 'thesis.publish')],
    ['Other permissions stay on', Access::allows(['role_code' => 'rdpo_admin', 'revoked_permissions' => ['thesis.publish']], 'thesis.edit')],
    ['A switch can never add what the role lacks', !Access::allows(['role_code' => 'rdpo_admin', 'revoked_permissions' => []], 'account.manage')],
    ['Unknown role still refused', !Access::allows(['role_code' => 'made_up_role', 'revoked_permissions' => []], 'thesis.view_all')],
    ['"View all records" and "Manage staff accounts" are not switches', !isset(Access::SWITCHABLE_PERMISSIONS['thesis.view_all']) && !isset(Access::SWITCHABLE_PERMISSIONS['account.manage'])],
    ['Every switch is a permission both roles have', count(array_filter(array_keys(Access::SWITCHABLE_PERMISSIONS), 'isStaffPermission')) === count(Access::SWITCHABLE_PERMISSIONS)],
    ['Form switches: real ones kept, made-up ones and repeats dropped', StaffAccounts::switchedOnFromForm(['permissions' => ['thesis.edit', 'account.manage', 'thesis.view_all', 'thesis.delete', ['x'], 'thesis.edit']]) === ['thesis.edit']],
    ['Form with no switches on gives an empty list', StaffAccounts::switchedOnFromForm([]) === [] && StaffAccounts::switchedOnFromForm(['permissions' => 'thesis.edit']) === []],

    // ---- usernames ----
    ['Ordinary username accepted', StaffAccounts::usernameProblem('juan.sample-2') === ''],
    ['Empty username refused', StaffAccounts::usernameProblem('') === 'Enter a username.'],
    ['Username with @ refused', StaffAccounts::usernameProblem('juan@sample') !== ''],
    ['Two-character username refused', StaffAccounts::usernameProblem('ab') !== ''],
    ['65-character username refused', StaffAccounts::usernameProblem(str_repeat('a', 65)) !== ''],
    ['Username with a space refused', StaffAccounts::usernameProblem('juan sample') !== ''],
    ['The form has no email any more (removed 2026-11-10)', !array_key_exists('email', StaffAccounts::newAccountValues($goodForm)['values'])],

    // ---- the New staff account form ----
    ['Good form has no problems', StaffAccounts::newAccountValues($goodForm)['errors'] === []],
    ['Spaces around the username are trimmed', StaffAccounts::newAccountValues(array_merge($goodForm, ['username' => '  sample.staff ']))['values']['username'] === 'sample.staff'],
    ['Head administrator role accepted', StaffAccounts::newAccountValues(array_merge($goodForm, ['role_code' => 'rdpo_head']))['errors'] === []],
    ['Unknown role refused', isset(StaffAccounts::newAccountValues(array_merge($goodForm, ['role_code' => 'superuser']))['errors']['role_code'])],
    ['Array instead of a username refused', isset(StaffAccounts::newAccountValues(array_merge($goodForm, ['username' => ['x']]))['errors']['username'])],
    ['Missing role falls back to RDPO administrator', StaffAccounts::newAccountValues(['username' => 'sample.staff'])['values']['role_code'] === 'rdpo_admin'],

    // ---- new passwords ----
    ['Good new password accepted', StaffAccounts::newPasswordProblem('river stone lamp 42', 'river stone lamp 42', 'sample.staff') === ''],
    ['11 characters refused', StaffAccounts::newPasswordProblem('abcdefghijk', 'abcdefghijk', 'sample.staff') === 'Use at least 12 characters.'],
    ['73 bytes refused', strpos(StaffAccounts::newPasswordProblem(str_repeat('a', 73), str_repeat('a', 73), 'sample.staff'), 'at most 72') !== false],
    ['36 ñ letters (72 bytes) accepted', StaffAccounts::newPasswordProblem(str_repeat('ñ', 36), str_repeat('ñ', 36), 'sample.staff') === ''],
    ['37 ñ letters (74 bytes) refused', StaffAccounts::newPasswordProblem(str_repeat('ñ', 37), str_repeat('ñ', 37), 'sample.staff') !== ''],
    ['Password with a null byte refused', StaffAccounts::newPasswordProblem("abcdef\0ghijklm", "abcdef\0ghijklm", 'sample.staff') !== ''],
    ['Username inside the password refused (any case)', StaffAccounts::newPasswordProblem('my SAMPLE.STAFF pass', 'my SAMPLE.STAFF pass', 'sample.staff') === 'Do not put your username in your password.'],
    ['Different second copy refused', StaffAccounts::newPasswordProblem('river stone lamp 42', 'river stone lamp 43', 'sample.staff') === 'The two new passwords are not the same.'],
    ['Spaces are kept, not trimmed', StaffAccounts::newPasswordProblem(' river stone 42 ', 'river stone 42', 'sample.staff') !== ''],

    // ---- temporary passwords ----
    ['Temporary password is 19 characters (16 random plus 3 hyphens)', strlen($temporaryPasswords[0]) === 19],
    ['Temporary passwords use no look-alike characters', $allLookRight],
    ['Every temporary password is new', count(array_unique($temporaryPasswords)) === 20],
    ['Temporary password passes the length rule', StaffAccounts::newPasswordProblem($temporaryPasswords[0], $temporaryPasswords[0], 'sample.staff') === ''],
];

$passed = 0;
$failed = 0;
foreach ($cases as $case) {
    echo 'STEPS ' . ($passed + $failed + 1) . ': ' . $case[0] . ".\n";
    if ($case[1]) {
        echo 'PASS: ' . $case[0] . "\n";
        $passed++;
    } else {
        echo 'FAIL: ' . $case[0] . "\n";
        $failed++;
    }
}
echo "RESULT: $passed passed; $failed failed.\n";
if ($failed > 0) {
    exit(1);
}
exit(0);
