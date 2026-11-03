<?php
// keep typed arguments strict so PHP does not silently convert the wrong input type
declare(strict_types=1);

// prepare the settings used by both browser pages and command-line scripts
ini_set('display_errors', '0'); // errors go into a private log instead of being displayed on the browser when something wrong happens
error_reporting(E_ALL); // still report all error types to our error handler
date_default_timezone_set('Asia/Singapore');

// this handles unexpected failures without printing passwords, SQL details, or submitted inputs
function reportFailure(Throwable $exception): void {
    $reference = bin2hex(random_bytes(6)); // a random reference we can find in the log
    $errorType = get_class($exception);
    $fileName = basename($exception->getFile());
    $lineNumber = $exception->getLine();

    // save only the error location and reference, not the potentially sensitive exception message
    $entry = date(DATE_ATOM) . ' reference=' . $reference;
    $entry .= ' type=' . $errorType . ' file=' . $fileName . ' line=' . $lineNumber . "\n";
    $logPath = __DIR__ . '/../storage/logs/application.log';
    file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX); // append instead of overwriting earlier entries

    if (PHP_SAPI === 'cli') { // cli means this script was run from the terminal
        // php://stderr is the terminal error stream, including when PHP code is piped through stdin
        file_put_contents('php://stderr', "Operation failed. Check the private log (reference $reference).\n");
    } else {
        http_response_code(503); // tell the browser that the service is temporarily unavailable
        header('Content-Type: text/plain; charset=utf-8');
        echo "Service temporarily unavailable. Ask the local developer to check reference $reference.";
    }
}

// Throwable covers both PHP errors and exceptions that were not caught earlier
function handleException(Throwable $exception): void {
    reportFailure($exception);
    exit(1); // stop this failed request instead of continuing with incomplete data
}

// turn reported warnings into exceptions so they use the same private error handling
function handleError(int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) { // respect errors that PHP has explicitly suppressed
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
}

// register the named functions above as PHP's shared error handlers
set_exception_handler('handleException');
set_error_handler('handleError');

// use the same PHP minor version in Apache, terminal commands and deployment
if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 5) {
    throw new RuntimeException('This project requires PHP 8.5.');
}

// Composer finds our classes and the installed libraries when they are used
require_once __DIR__ . '/../vendor/autoload.php';

// return settings loaded by phpdotenv; this does not open a database connection yet
function loadConfiguration(): array {
    return Scholar\Configuration::load();
}

// open a PDO connection when a page or command actually needs the database
function connectDatabase(): PDO {
    $configuration = loadConfiguration();
    $database = $configuration['database']; // Configuration has already checked these settings

    $connectionString = 'mysql:host=' . $database['host'];
    $connectionString .= ';port=' . $database['port'];
    $connectionString .= ';dbname=' . $database['name'];
    $connectionString .= ';charset=utf8mb4'; // support Unicode text consistently

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // database failures become exceptions
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // results use named columns
        PDO::ATTR_EMULATE_PREPARES => false, // let MySQL handle prepared statements
    ];

    $pdo = new PDO($connectionString, $database['user'], $database['password'], $options);
    return $pdo;
}

// convert special characters into safe HTML text before showing user-controlled values
function escapeHtml(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
