<?php
declare(strict_types=1);

// Phinx reads this file for our database settings and migration/seed locations
require_once __DIR__ . '/app/bootstrap.php';
$configuration = loadConfiguration();
$database = $configuration['database']; // comes from the private .env, not hardcoded credentials

return [
    'paths' => [
        'migrations' => __DIR__ . '/database/migrations',
        'seeds' => __DIR__ . '/database/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog', // Phinx records applied versions here
        'default_environment' => 'development', // only local development is configured
        'development' => [
            'adapter' => 'mysql',
            'host' => $database['host'],
            'port' => $database['port'],
            'name' => $database['name'],
            'user' => $database['user'],
            'pass' => $database['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
    ],
    'version_order' => 'creation', // run the timestamp-named migrations in their creation order
];
