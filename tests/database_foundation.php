<?php
// WHAT THIS FILE DOES: automatically checks database setup in a separate temporary database.
// These are integration tests for configuration loading, Phinx migrations/seeding, and MySQL.

// STEP 1: Allow terminal use only and load the application setup.
// STEP 2: Read the database administrator credentials supplied through environment variables. SCHOLAR_DB_ADMIN_USER is required; an omitted password is treated as blank. Use the inspected local credentials, not an assumed username or password.
// STEP 3: Load the existing database connection settings and connect to the local MySQL server.
// STEP 4: Create a separate, randomly named database beginning with scholar_verify_.
// STEP 5: Point this test process at that database without editing the application's .env file.
// STEP 6: Check that settings supplied by the test take priority over settings in .env.
// STEP 7: Temporarily supply an invalid database port, check rejection, then restore the valid port.
// STEP 8: Run the Phinx migrations twice; completed migrations must not run again.
// STEP 9: Run the synthetic seed twice; it must not duplicate the sample thesis or its authors.
// STEP 10: Check for nine migration records, and the thirteen synthetic theses with their 24 authors from the two seeds.
// STEP 11: Compare the created table structures with the saved structure for the database engine.
// STEP 12: In finally, restore the process settings and remove only this test's temporary database. Cleanup is attempted even if a check fails after the temporary database was created.

// Successful checks print PASS; a failed check throws an error and stops further checks.
// A missing administrator username exits with code 2; other errors are handled by the application.
// Use only synthetic sample data; do not place passwords in this file or its printed output!!!

declare(strict_types=1); // keep typed helper arguments strict

use Scholar\Configuration;
use Scholar\LegacyBaseline;
use Scholar\PhinxCommands;

// only run this test from the terminal, never as a browser endpoint
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

// these inspected credentials are needed only to create a separate disposable database
$adminUser = getenv('SCHOLAR_DB_ADMIN_USER');
$adminPassword = getenv('SCHOLAR_DB_ADMIN_PASSWORD');
if ($adminUser === false) {
    fwrite(STDERR, "Supply inspected temporary DB administrator environment variables for this test.\n");
    exit(2);
}
if ($adminPassword === false) {
    $adminPassword = '';
}

$configuration = loadConfiguration();
$originalDatabase = $configuration['database'];
$connectionString = 'mysql:host=' . $originalDatabase['host'];
$connectionString .= ';port=' . $originalDatabase['port'] . ';charset=utf8mb4';
$admin = new PDO($connectionString, $adminUser, $adminPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// only this randomly named database may be created or removed by this test
$testDatabase = 'scholar_verify_' . bin2hex(random_bytes(8));
$created = false;
$originalEnvironment = $_ENV;
$originalServer = $_SERVER; // restore both arrays in finally, even if a check fails

try {
    $admin->exec('CREATE DATABASE ' . $testDatabase . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;

    // point only this PHP test process at its new database; do not edit the real .env
    $_SERVER['DB_DATABASE'] = $testDatabase;
    $_ENV['DB_DATABASE'] = $testDatabase;
    $_SERVER['DB_USERNAME'] = $adminUser;
    $_ENV['DB_USERNAME'] = $adminUser;
    $_SERVER['DB_PASSWORD'] = $adminPassword;
    $_ENV['DB_PASSWORD'] = $adminPassword;

    $testConfiguration = Configuration::load();
    if ($testConfiguration['database']['name'] !== $testDatabase) {
        throw new RuntimeException('Immutable environment precedence failed.');
    }
    echo "PASS: phpdotenv preserves supplied environment over .env\n";

    // deliberately submit an invalid port and make sure the library validation rejects it
    $_SERVER['DB_PORT'] = 'invalid';
    $_ENV['DB_PORT'] = 'invalid';
    $rejected = false;
    try {
        Configuration::load();
    } catch (Dotenv\Exception\ValidationException $exception) {
        $rejected = true;
    }
    if (!$rejected) {
        throw new RuntimeException('Invalid configuration accepted.');
    }
    echo "PASS: phpdotenv rejects invalid configuration\n";

    // restore the real server port before applying migrations to the temporary database
    $_SERVER['DB_PORT'] = (string) $originalDatabase['port'];
    $_ENV['DB_PORT'] = (string) $originalDatabase['port'];
    PhinxCommands::run('migrate');
    PhinxCommands::run('migrate'); // a second run must not recreate existing tables
    PhinxCommands::run('seed:run');
    PhinxCommands::run('seed:run'); // a second seed must not duplicate the fixture

    $pdo = connectDatabase();
    $migrationCount = (int) $pdo->query('SELECT COUNT(*) FROM phinxlog')->fetchColumn();
    $thesisCount = (int) $pdo->query('SELECT COUNT(*) FROM theses')->fetchColumn();
    $authorCount = (int) $pdo->query('SELECT COUNT(*) FROM thesis_authors')->fetchColumn();
    // nine = the four foundation migrations plus five added on 2026-10-19 (account status, failed logins, thesis fields, audit log, thesis files)
    // 13 theses / 24 authors = SyntheticFoundation's 1 thesis and 2 authors plus SyntheticCatalog's 12 theses and 22 authors
    if ($migrationCount !== 9 || $thesisCount !== 13 || $authorCount !== 24) {
        throw new RuntimeException('Clean database initialization or seed repeatability failed.');
    }
    echo "PASS: fresh Phinx migration and repeated synthetic seed\n";

    // MariaDB and MySQL print different DDL; preserve the old snapshot and check the matching engine
    // mysql_schema.json was updated on 2026-10-19 for the users and theses columns and the failed_logins and audit_logs tables;
    // legacy_schema.json still describes only the historical four-table MariaDB baseline
    $serverVersion = $pdo->query('SELECT VERSION()')->fetchColumn();
    $manifestPath = __DIR__ . '/../database/mysql_schema.json';
    if (strpos($serverVersion, 'MariaDB') !== false) {
        $manifestPath = __DIR__ . '/../database/legacy_schema.json';
    }
    $manifestText = file_get_contents($manifestPath);
    $manifest = json_decode($manifestText, true, 512, JSON_THROW_ON_ERROR);
    foreach ($manifest as $table => $expectedHash) {
        $statement = $pdo->query('SHOW CREATE TABLE ' . $table); // names come from the committed local manifest
        $row = $statement->fetch(PDO::FETCH_NUM);
        $actualHash = LegacyBaseline::schemaHash($row[1]);
        if (!hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException('Fresh schema differs from the saved database-engine baseline.');
        }
    }
    echo "PASS: fresh Phinx schema matches the saved database-engine foundation\n";
} finally {
    $_ENV = $originalEnvironment;
    $_SERVER = $originalServer;

    // only remove our own database after successful creation; never scholar_dev or any unrelated database
    if ($created && preg_match('/\Ascholar_verify_[a-f0-9]{16}\z/', $testDatabase)) {
        $admin->exec('DROP DATABASE ' . $testDatabase);
        echo "Temporary test database removed.\n";
    }
}
