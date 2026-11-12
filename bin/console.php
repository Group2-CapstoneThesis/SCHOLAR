<?php
declare(strict_types=1); // keep typed method arguments strict

use Scholar\AccountStatus;
use Scholar\AdminProvisioner;
use Scholar\AuditLog;
use Scholar\Backup;
use Scholar\DemoTheses;
use Scholar\Auth\LoginThrottle;
use Scholar\Auth\StaffAccounts;
use Scholar\LegacyBaseline;
use Scholar\PhinxCommands;
use Scholar\Search\SearchIndex;
use Scholar\Search\SearchUnavailable;

// these commands belong in PowerShell, not in browser requests
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

// argv holds terminal arguments; argv[1] is the command after this script's filename
$command = '';
if (isset($argv[1])) {
    $command = $argv[1];
}
$allowedCommands = ['check-db', 'migrate', 'migration-status', 'adopt-legacy', 'seed', 'provision-admin', 'disable-account', 'enable-account', 'set-role',
    'backup', 'verify-backup', 'restore-backup', 'reindex-search', 'search-status', 'demo-data', 'emergency-access'];
if (!in_array($command, $allowedCommands, true)) {
    fwrite(STDERR, "Usage: php bin/console.php check-db|migrate|migration-status|adopt-legacy|seed|provision-admin|disable-account|enable-account|set-role|backup|verify-backup|restore-backup|reindex-search|search-status|demo-data|emergency-access\n");
    exit(2); // an invalid command is a usage error
}

$pdo = connectDatabase();
switch ($command) {
    case 'check-db':
        // this query also confirms that the configured connection reaches the database server
        $serverVersion = $pdo->query('SELECT VERSION()')->fetchColumn();
        echo 'Connected to development database; server ' . $serverVersion . "\n";
        break;

    case 'migrate':
        PhinxCommands::run('migrate'); // apply only migrations not recorded as applied
        break;

    case 'migration-status':
        PhinxCommands::run('status'); // show applied and pending migration versions
        break;

    case 'adopt-legacy':
        // one-time transition for an old baseline database, never routine database setup
        $existingLedger = $pdo->query("SHOW TABLES LIKE 'phinxlog'")->fetchColumn();
        if ($existingLedger) {
            $migrationCount = (int) $pdo->query('SELECT COUNT(*) FROM phinxlog')->fetchColumn();
            if ($migrationCount !== 0) {
                throw new RuntimeException('Phinx history already exists; do not adopt again.');
            }
        }

        LegacyBaseline::assertMatches($pdo); // check history and actual structure before marking anything applied
        PhinxCommands::run('migrate', ['--fake' => true, '--target' => '20261003000400']);
        echo "Verified legacy baseline adopted without replaying DDL. Original ledger retained.\n";
        break;

    case 'seed':
        PhinxCommands::run('seed:run'); // insert fictional fixtures through the library's seed runner
        break;

    case 'provision-admin':
        // read the username from the arguments, but never take a password on the command line
        $username = '';
        if (isset($argv[2])) {
            $username = trim($argv[2]);
        }
        $password = getenv('SCHOLAR_ADMIN_PASSWORD'); // supplied temporarily through the process environment
        if ($password === false) {
            fwrite(STDERR, "Supply SCHOLAR_ADMIN_PASSWORD through a temporary environment variable.\n");
            exit(2);
        }

        try {
            $provisioner = new AdminProvisioner($pdo);
            $provisioner->createAdministrator($username, $password);
            echo "Local development administrator created. Existing accounts are never overwritten.\n";
        } catch (InvalidArgumentException $exception) {
            // report the expected input rules without echoing the password or a raw database error
            fwrite(STDERR, "Use a 3-64 character username (letters, digits, underscore, dot, hyphen) and a 12-72 byte password.\n");
            exit(2);
        } finally {
            putenv('SCHOLAR_ADMIN_PASSWORD'); // clear this process's temporary password even if creation failed
        }
        break;

    case 'disable-account':
    case 'enable-account':
        // both commands take one username, for example: disable-account local_admin
        $identifier = '';
        if (isset($argv[2])) {
            $identifier = trim($argv[2]);
        }
        if ($identifier === '' || strlen($identifier) > 64) {
            fwrite(STDERR, "Supply the account's username after the command.\n");
            exit(2);
        }

        $accountStatus = new AccountStatus($pdo);
        if ($command === 'disable-account') {
            $result = $accountStatus->disableAccount($identifier);
        } else {
            $result = $accountStatus->enableAccount($identifier);
        }

        // turn each named result into a sentence; 'not_found' is the only result treated as a failure
        $messages = [
            'disabled' => 'Account disabled. Its open sessions end on their next request.',
            'already_disabled' => 'Account was already disabled. Nothing changed.',
            'enabled' => 'Account enabled. It can log in again with its existing password.',
            'already_active' => 'Account was already active. Nothing changed.',
        ];
        if ($result === 'not_found') {
            fwrite(STDERR, "No account matches that username.\n");
            exit(1);
        }
        echo $messages[$result] . "\n";
        break;

    case 'set-role':
        // for example: set-role local_admin rdpo_head
        // makes the first head administrator (M12); after that, roles are changed on the Staff accounts page
        $identifier = '';
        $roleCode = '';
        if (isset($argv[2])) {
            $identifier = trim($argv[2]);
        }
        if (isset($argv[3])) {
            $roleCode = trim($argv[3]);
        }
        if ($identifier === '' || strlen($identifier) > 64 || $roleCode === '') {
            fwrite(STDERR, "Usage: php bin/console.php set-role <username> rdpo_admin|rdpo_head\n");
            exit(2);
        }

        $staffAccounts = new StaffAccounts($pdo);
        $result = $staffAccounts->changeRoleFromTerminal($identifier, $roleCode);
        if ($result === 'unknown_role') {
            fwrite(STDERR, "Unknown role. Use rdpo_admin or rdpo_head.\n");
            exit(2);
        }
        if ($result === 'not_found') {
            fwrite(STDERR, "No account matches that username.\n");
            exit(1);
        }
        if ($result === 'unchanged') {
            echo "The account already has that role. Nothing changed.\n";
        } else {
            echo "Role changed. It applies from the account's next click.\n";
        }
        break;

    case 'backup':
        // makes one backup file of the database and the stored PDFs (M12, FB-04)
        // IT can run this on a schedule, for example with Windows Task Scheduler
        $backup = Backup::create($pdo);
        $made = $backup->makeBackup();
        // the history entry is written after the backup, so it is not inside this backup itself
        AuditLog::record($pdo, null, 'backup.created', 'backup', null, [$made['file']]);
        echo 'Backup made: ' . $made['path'] . "\n";
        echo '  ' . array_sum($made['tables']) . ' database rows in ' . count($made['tables']) . ' tables, '
            . $made['pdf_count'] . ' PDFs, ' . round($made['size'] / 1048576, 1) . " MB\n";
        echo "Copy it to another drive: a backup on the same drive is lost if that drive fails.\n";
        break;

    case 'verify-backup':
    case 'restore-backup':
        // the backup is named either by its file name (looked up in the backup folder) or by a full path,
        // for example a copy on a USB drive: verify-backup E:\scholar_backup_2026-11-09_103000_a1b2c3.tar
        $backup = Backup::create($pdo);
        $given = '';
        if (isset($argv[2])) {
            $given = trim($argv[2]);
        }
        $path = $backup->pathOf($given);
        if ($path === null && $given !== '' && is_file($given)) {
            $path = $given;
        }
        if ($path === null) {
            fwrite(STDERR, "Name a backup file: its file name in the backup folder, or its full path.\n");
            exit(2);
        }

        $checked = Backup::check($path);
        if (count($checked['problems']) > 0) {
            fwrite(STDERR, "The backup did NOT pass its check:\n");
            foreach ($checked['problems'] as $problem) {
                fwrite(STDERR, '  - ' . $problem . "\n");
            }
            exit(1);
        }
        $manifest = $checked['manifest'];
        echo 'Backup OK: made ' . $manifest['created_at'] . ' from database ' . $manifest['database'] . ', '
            . array_sum($manifest['tables']) . ' rows in ' . count($manifest['tables']) . ' tables, '
            . count($manifest['files']) . " PDFs. Every part matches its fingerprint.\n";
        if ($command === 'verify-backup') {
            break;
        }

        // restoring REPLACES the live database and puts the backup's PDFs back, so it needs --confirm
        if (!in_array('--confirm', $argv, true)) {
            echo "\nRestoring REPLACES every table of the current database with this backup's contents\n";
            echo "and copies its PDFs back into storage/theses. Changes made after the backup are lost.\n";
            echo "A fresh backup of the current state is made first. To go ahead, run the same command with --confirm.\n";
            exit(2);
        }
        // the application's own database account may not DROP tables (least privilege), so a restore needs
        // a database administrator account, supplied only for this command through temporary environment variables
        // (the same way as tests/database_foundation.php); an omitted password is treated as blank
        $adminUser = getenv('SCHOLAR_DB_ADMIN_USER');
        $adminPassword = getenv('SCHOLAR_DB_ADMIN_PASSWORD');
        if ($adminUser === false || trim($adminUser) === '') {
            fwrite(STDERR, "Supply a database administrator through SCHOLAR_DB_ADMIN_USER (and SCHOLAR_DB_ADMIN_PASSWORD) for this command.\n");
            exit(2);
        }
        if ($adminPassword === false) {
            $adminPassword = '';
        }
        $databaseSettings = loadConfiguration()['database'];
        try {
            // the administrator connects to the SAME database the application uses, nothing else
            $adminPdo = new PDO(
                'mysql:host=' . $databaseSettings['host'] . ';port=' . $databaseSettings['port'] . ';dbname=' . $databaseSettings['name'] . ';charset=utf8mb4',
                $adminUser, $adminPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $exception) {
            fwrite(STDERR, "The database administrator could not connect. Nothing was changed.\n");
            exit(1);
        } finally {
            putenv('SCHOLAR_DB_ADMIN_PASSWORD'); // clear this process's copy of the password
        }

        $safety = $backup->makeBackup();
        AuditLog::record($pdo, null, 'backup.created', 'backup', null, [$safety['file']]);
        echo 'Safety backup of the current state made first: ' . $safety['file'] . "\n";

        $restored = Backup::restore($adminPdo, $path, __DIR__ . '/../storage/theses');
        // written into the RESTORED history, so the log shows that a restore happened and from which file
        AuditLog::record($pdo, null, 'backup.restored', 'backup', null, [basename($path)]);
        echo 'Restored ' . $restored['tables'] . ' tables and ' . $restored['pdf_count'] . " PDFs.\n";
        echo "If something looks wrong, restore the safety backup above the same way.\n";
        break;

    case 'reindex-search':
    case 'search-status':
        // semantic search (M14): Ollama makes the embeddings, Qdrant stores them; MySQL stays the source of truth
        $searchIndex = SearchIndex::create($pdo);
        if ($searchIndex === null) {
            fwrite(STDERR, "Semantic search is switched off (SEARCH_SEMANTIC_ENABLED=false in .env).\n");
            exit(2);
        }
        try {
            if ($command === 'search-status') {
                $status = $searchIndex->status();
                echo 'Collection ' . $status['collection'] . ': ' . $status['points'] . ' records in the index; '
                    . $status['records'] . ' records in the database, ' . $status['waiting'] . " waiting to be indexed.\n";
                break;
            }
            if (in_array('--missing', $argv, true)) {
                // only records that are new, changed, or failed to index earlier; also removes leftovers of deleted records
                $result = $searchIndex->catchUp();
                echo 'Indexed ' . $result['indexed'] . ' records; removed ' . $result['removed'] . " leftover entries.\n";
            } else {
                // the whole index again from scratch, e.g. after changing the embedding model
                echo "Rebuilding the search index from every record...\n";
                echo 'Indexed ' . $searchIndex->rebuild() . " records.\n";
            }
        } catch (SearchUnavailable $exception) {
            fwrite(STDERR, "Ollama or Qdrant did not answer. Check that both are running and the SEARCH_* settings in .env.\n");
            exit(1);
        }
        break;

    case 'demo-data':
        // loads (or with --remove, deletes) the made-up demo theses used to show semantic search
        $demo = new DemoTheses($pdo);
        $isRemoving = in_array('--remove', $argv, true);
        if ($isRemoving) {
            $changedIds = $demo->remove();
            echo 'Removed ' . count($changedIds) . " demo records.\n";
        } else {
            $changedIds = $demo->install();
            echo 'Added ' . count($changedIds) . ' demo records (' . (DemoTheses::count() - count($changedIds)) . " were already there).\n";
        }
        // keep the search index in step: add the new records, or remove the deleted ones
        $searchIndex = SearchIndex::create($pdo);
        if ($searchIndex !== null && count($changedIds) > 0) {
            try {
                $result = $searchIndex->catchUp();
                echo 'Search index updated: ' . $result['indexed'] . ' added, ' . $result['removed'] . " removed.\n";
            } catch (SearchUnavailable $exception) {
                echo "Ollama or Qdrant did not answer; run 'reindex-search --missing' once they are running.\n";
            }
        }
        break;

    case 'emergency-access':
        // the last resort (user's request, 2026-11-10), for example: emergency-access local_admin --confirm
        // for a head administrator who lost their password, their authenticator app and their backup codes at once
        // only someone who can open a terminal on the server can run it, which is what makes it safe to offer
        $identifier = '';
        if (isset($argv[2])) {
            $identifier = trim($argv[2]);
        }
        if ($identifier === '' || strlen($identifier) > 64 || $identifier === '--confirm') {
            fwrite(STDERR, "Usage: php bin/console.php emergency-access <username> --confirm\n");
            exit(2);
        }
        if (!in_array('--confirm', $argv, true)) {
            echo "This gives the account a new temporary password, signs it out everywhere and switches its two-factor authentication (2FA) off\n";
            echo "(the authenticator app, backup codes and remembered devices stop working). Add --confirm to go ahead.\n";
            exit(2);
        }

        $staffAccounts = new StaffAccounts($pdo);
        $result = $staffAccounts->emergencyAccessFromTerminal($identifier);
        if ($result['result'] === 'not_found') {
            fwrite(STDERR, "No account matches that username.\n");
            exit(1);
        }
        // forgive the account's recent failed logins, so the person is not blocked while trying the new password
        $limits = loadConfiguration()['login'];
        $throttle = new LoginThrottle($pdo, $limits['account_max_failures'], $limits['address_max_failures'], $limits['window_seconds']);
        $throttle->clearAccount($result['username']);

        // the temporary password is printed once, here, and never saved anywhere in readable form
        echo 'Temporary password for ' . $result['username'] . ': ' . $result['temporary_password'] . "\n";
        echo "Give it to the person in private. At the next login they choose a new password, then link an authenticator app again\n";
        echo "(head administrators must; for RDPO administrators it is optional).\n";
        if (!$result['is_active']) {
            echo "Note: this account is disabled. Run 'enable-account " . $result['username'] . "' if it should be able to log in.\n";
        }
        break;
}
