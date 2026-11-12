<?php
// WHAT THIS FILE DOES: checks the two-step login rules (Scholar\Auth\TwoFactor) without the real database.
// STEP 1: Load the application and make a made-up secret; the encryption key goes to a temporary file, never storage/.
// STEP 2: Check which roles must use it and which get backup codes.
// STEP 3: Check app codes: the current, previous and next 30-second code work; older or newer ones, wrong ones and
//         already used ones are refused; spaces are allowed.
// STEP 4: Check the QR code and the typed key: the address names S.C.H.O.L.A.R. and the account, the SVG has no script.
// STEP 5: Check backup codes: format, randomness, and that typing them in capitals or without the dash still works.
// STEP 6: Check that the secret is encrypted, decrypts again, and cannot be read with another key.
// STEP 7: Check that every new activity code has a readable name, then print the total result.
declare(strict_types=1);

use OTPHP\TOTP;
use Scholar\ActivityLog;
use Scholar\AuditLog;
use Scholar\Auth\TwoFactor;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

$secret = TwoFactor::newSecret();
$totp = TOTP::createFromSecret($secret);
$now = 1_800_000_015; // a fixed moment, 15 seconds into a 30-second window
$step = intdiv($now, 30);
// the code an app would show in one 30-second window (window number x 30 = its first second)
function codeAt(TOTP $totp, int $windowStep): string {
    return $totp->at($windowStep * 30);
}

// an in-memory SQLite connection stands in for MySQL; encryption needs no tables
$memory = new PDO('sqlite::memory:');
$keyFile = sys_get_temp_dir() . '/scholar_unit_two_factor_' . bin2hex(random_bytes(6)) . '.key';
$otherKeyFile = $keyFile . '.other';
$twoFactor = new TwoFactor($memory, $keyFile);
$encrypted = $twoFactor->encrypt($secret);
$decryptedAgain = $twoFactor->decrypt($encrypted);
$otherKey = new TwoFactor($memory, $otherKeyFile);
$readWithOtherKey = $otherKey->decrypt($encrypted);

$address = TwoFactor::setupAddress($secret, 'unit_head');
$svg = TwoFactor::qrCodeSvg($address);

$backupCodes = [];
for ($count = 0; $count < 200; $count++) {
    $backupCodes[] = TwoFactor::newBackupCode();
}
$allFormatted = true;
foreach ($backupCodes as $code) {
    if (!preg_match('/\A[a-hjkmnp-z2-9]{5}-[a-hjkmnp-z2-9]{5}\z/', $code)) {
        $allFormatted = false;
    }
}

$labelsMissing = [];
foreach (AuditLog::actionCodes() as $code) {
    if (!isset(ActivityLog::ACTION_LABELS[$code])) {
        $labelsMissing[] = $code;
    }
}

$cases = [
    ['Head administrators must use two-step login', TwoFactor::isRequiredFor('rdpo_head')],
    ['RDPO administrators may choose', !TwoFactor::isRequiredFor('rdpo_admin')],
    ['Only head administrators get backup codes', TwoFactor::usesBackupCodes('rdpo_head') && !TwoFactor::usesBackupCodes('rdpo_admin')],
    ['A new secret is 32 base32 letters (160 bits)', preg_match('/\A[A-Z2-7]{32}\z/', $secret) === 1],
    ['Two new secrets are different', TwoFactor::newSecret() !== $secret],
    ['The current code works and names its window', TwoFactor::matchingStep($secret, codeAt($totp, $step), $now, null) === $step],
    ['The previous window\'s code works (slow phone clock)', TwoFactor::matchingStep($secret, codeAt($totp, $step - 1), $now, null) === $step - 1],
    ['The next window\'s code works (fast phone clock)', TwoFactor::matchingStep($secret, codeAt($totp, $step + 1), $now, null) === $step + 1],
    ['A code two windows old is refused', TwoFactor::matchingStep($secret, codeAt($totp, $step - 2), $now, null) === null],
    ['A code two windows ahead is refused', TwoFactor::matchingStep($secret, codeAt($totp, $step + 2), $now, null) === null],
    ['An already used window is refused', TwoFactor::matchingStep($secret, codeAt($totp, $step), $now, $step) === null],
    ['A later window still works after an earlier one was used', TwoFactor::matchingStep($secret, codeAt($totp, $step + 1), $now, $step) === $step + 1],
    ['A wrong code is refused', TwoFactor::matchingStep($secret, codeAt($totp, $step) === '000000' ? '111111' : '000000', $now, null) === null],
    ['A code typed with a space works', TwoFactor::matchingStep($secret, substr(codeAt($totp, $step), 0, 3) . ' ' . substr(codeAt($totp, $step), 3), $now, null) === $step],
    ['Five or seven digits are refused', TwoFactor::matchingStep($secret, '12345', $now, null) === null && TwoFactor::matchingStep($secret, '1234567', $now, null) === null],
    ['Letters are not digits', TwoFactor::cleanAppCode('12a3-45 6') === '12345' . '6'],
    ['The QR address is an otpauth time-based address', str_starts_with($address, 'otpauth://totp/')],
    ['The QR address names S.C.H.O.L.A.R. and the account', strpos($address, 'issuer=S.C.H.O.L.A.R.%20WCC') !== false && strpos($address, 'unit_head') !== false],
    ['The QR address carries the secret', strpos($address, 'secret=' . $secret) !== false],
    ['The QR code is SVG without the XML line or any script', str_starts_with($svg, '<svg') && stripos($svg, '<script') === false],
    ['The typed key comes in groups of four', TwoFactor::readableSecret('ABCDEFGHIJ') === 'ABCD EFGH IJ'],
    ['Backup codes look like k7mqx-3pahr, without look-alike letters', $allFormatted],
    ['200 backup codes are all different', count(array_unique($backupCodes)) === 200],
    ['A backup code typed in capitals or without the dash is the same code', TwoFactor::cleanBackupCode(' K7MQX 3PAHR ') === 'k7mqx3pahr' && TwoFactor::cleanBackupCode('k7mqx-3pahr') === 'k7mqx3pahr'],
    ['The key file is made the first time it is needed', is_file($keyFile)],
    ['The stored secret is not the secret itself', strpos($encrypted, $secret) === false && strlen($encrypted) > strlen($secret)],
    ['Encrypting twice gives different stored text', $twoFactor->encrypt($secret) !== $encrypted],
    ['The secret decrypts again with its key', $decryptedAgain === $secret],
    ['Another key cannot read it', $readWithOtherKey === ''],
    ['Every activity code, including the new ones, has a readable name', $labelsMissing === []],
];

// the temporary key files are removed whatever the results are
foreach ([$keyFile, $otherKeyFile] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

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
