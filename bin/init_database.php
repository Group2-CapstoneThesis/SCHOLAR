<?php
declare(strict_types=1);

// this helper is only for a new local installation, not for resetting an existing database
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

$adminUser = getenv('SCHOLAR_DB_ADMIN_USER');
$adminPassword = getenv('SCHOLAR_DB_ADMIN_PASSWORD'); // inspected credentials supplied only for this command
if ($adminUser === false || $adminPassword === false) {
    fwrite(STDERR, "Supply inspected SCHOLAR_DB_ADMIN_USER and SCHOLAR_DB_ADMIN_PASSWORD temporarily.\n");
    exit(2);
}

$configPath = __DIR__ . '/../.env';
if (file_exists($configPath) || file_exists(__DIR__ . '/../config/local.php')) {
    fwrite(STDERR, "Refusing to overwrite local configuration.\n");
    exit(2); // preserve both current settings and an old private configuration backup
}

// use the inspected Laragon endpoint; this connection is for creating the separate development database
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', $adminUser, $adminPassword, $options);

$statement = $pdo->prepare("
    SELECT SCHEMA_NAME
    FROM information_schema.SCHEMATA
    WHERE SCHEMA_NAME = :name
");
$statement->execute(['name' => 'scholar_dev']);
if ($statement->fetch()) {
    fwrite(STDERR, "scholar_dev already exists; no changes made. Configure it manually after inspecting ownership.\n");
    exit(2); // never drop or replace a database that already exists
}

// generate credentials instead of saving a shared default password in source code
$databaseUser = 'scholar_' . bin2hex(random_bytes(6));
$databasePassword = bin2hex(random_bytes(24));
$account = $pdo->quote($databaseUser) . "@'127.0.0.1'"; // quote the generated account name for this DDL

$pdo->exec('CREATE DATABASE scholar_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('CREATE USER ' . $account . ' IDENTIFIED BY ' . $pdo->quote($databasePassword));
// grant privileges only on our new database, never on unrelated databases or globally
$pdo->exec('GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON scholar_dev.* TO ' . $account);

// generated values contain only letters/digits/underscore, so they need no special dotenv quoting
$configurationText = "DB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=scholar_dev\n";
$configurationText .= "DB_USERNAME=$databaseUser\nDB_PASSWORD=$databasePassword\n";
file_put_contents($configPath, $configurationText, LOCK_EX); // .env is private and ignored by Git

putenv('SCHOLAR_DB_ADMIN_USER');
putenv('SCHOLAR_DB_ADMIN_PASSWORD'); // discard temporary administrator credentials in this process
echo "Created dedicated scholar_dev and a database-scoped account; private local configuration saved.\n";
