<?php
// the purpose of this file is for making, checking and restoring backups of the database and the stored thesis PDFs (M12)
declare(strict_types=1); // keep typed method arguments strict

namespace Scholar;

use PDO;
use PharData;
use RuntimeException;

// one backup = one .tar file, such as scholar_backup_2026-11-09_103000_a1b2c3.tar, holding:
//   manifest.json      when it was made, how many rows each table had, and a SHA-256 fingerprint of every part
//   database.sql.gz    the whole database as SQL statements (gzip-compressed), ONE statement per line
//   files/theses/...   a copy of every stored manuscript PDF
// .tar is used because PHP can write it with the built-in PharData class; the zip extension is not enabled in Laragon
// Windows 11 and 7-Zip can open .tar files directly
class Backup {
    public const FILE_PREFIX = 'scholar_backup_';
    // the backup file names this class makes; only names that match are listed, downloaded or restored
    public const FILE_PATTERN = '/\Ascholar_backup_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}_[a-f0-9]{6}\.tar\z/';
    // stored PDFs have 32 random hex characters as their name (see ThesisFiles)
    private const PDF_NAME_PATTERN = '/\A[a-f0-9]{32}\.pdf\z/';
    private const FORMAT_VERSION = 1;
    private const ROWS_PER_INSERT = 100; // rows written per INSERT statement, so big tables do not make one huge line

    private PDO $pdo;
    private string $backupDirectory;
    private string $thesisDirectory;

    public function __construct(PDO $pdo, string $backupDirectory, string $thesisDirectory) {
        $this->pdo = $pdo;
        $this->backupDirectory = rtrim($backupDirectory, '/\\');
        $this->thesisDirectory = rtrim($thesisDirectory, '/\\');
    }

    // the usual way pages and the terminal get this class: folders from .env and the default PDF storage
    public static function create(PDO $pdo): Backup {
        $configuration = loadConfiguration();
        return new Backup($pdo, $configuration['backup']['directory'], __DIR__ . '/../storage/theses');
    }

    public function backupDirectory(): string {
        return $this->backupDirectory;
    }

    // ---------------------------------------------------------------------------------------------
    // making a backup
    // ---------------------------------------------------------------------------------------------

    // makes a new backup file and returns ['file' => name, 'path' => full path, 'size' => bytes,
    // 'tables' => [table => row count], 'pdf_count' => number of PDFs]
    public function makeBackup(): array {
        if (!is_dir($this->backupDirectory) && !mkdir($this->backupDirectory, 0700, true)) {
            throw new RuntimeException('The backup folder could not be created.');
        }
        if (!is_writable($this->backupDirectory)) {
            throw new RuntimeException('The backup folder is not writable.');
        }

        $fileName = self::FILE_PREFIX . date('Y-m-d_His') . '_' . bin2hex(random_bytes(3)) . '.tar';
        $finalPath = $this->backupDirectory . DIRECTORY_SEPARATOR . $fileName;
        // the file is built under a temporary name and renamed at the end, so a half-made backup is never listed
        $workingPath = $this->backupDirectory . DIRECTORY_SEPARATOR . 'partial_' . bin2hex(random_bytes(6)) . '.tar';

        $dump = $this->dumpDatabase();
        $pdfs = $this->storedPdfs();
        $fileHashes = [];
        try {
            $archive = new PharData($workingPath); // PharData writes a plain .tar file (no PHP code inside)
            $archive->addFromString('database.sql.gz', gzencode($dump['sql'], 6));
            foreach ($pdfs as $pdfName) {
                $pdfPath = $this->thesisDirectory . DIRECTORY_SEPARATOR . $pdfName;
                $archive->addFile($pdfPath, 'files/theses/' . $pdfName);
                $fileHashes[$pdfName] = hash_file('sha256', $pdfPath);
            }
            $manifest = [
                'format' => self::FORMAT_VERSION,
                'application' => 'S.C.H.O.L.A.R.',
                'created_at' => date('Y-m-d H:i:s'),
                'database' => $dump['database'],
                'tables' => $dump['row_counts'],
                'database_sha256' => hash('sha256', $dump['sql']),
                'files' => $fileHashes,
            ];
            $archive->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            unset($archive); // close the archive so Windows lets the file be renamed
            if (!rename($workingPath, $finalPath)) {
                throw new RuntimeException('The finished backup could not be renamed.');
            }
        } catch (\Throwable $exception) {
            if (is_file($workingPath)) {
                unlink($workingPath); // never leave a half-made backup behind
            }
            throw $exception;
        }
        return [
            'file' => $fileName,
            'path' => $finalPath,
            'size' => filesize($finalPath),
            'tables' => $dump['row_counts'],
            'pdf_count' => count($pdfs),
        ];
    }

    // the whole database as SQL text, one statement per line, plus the row count of every table
    // "consistent snapshot" means every table is read as it was at the same moment, even if someone saves meanwhile
    public function dumpDatabase(): array {
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            $lines = [];
            $lines[] = '-- S.C.H.O.L.A.R. database backup of ' . $database . ', made ' . date('Y-m-d H:i:s');
            $lines[] = 'SET NAMES utf8mb4;';
            $lines[] = 'SET FOREIGN_KEY_CHECKS = 0;'; // tables are restored one by one, so links are checked only at the end
            $rowCounts = [];
            foreach ($this->tableNames() as $table) {
                $quotedTable = '`' . $table . '`'; // table names come from SHOW FULL TABLES and are checked in tableNames()
                $create = $this->pdo->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_NUM);
                $lines[] = '-- table ' . $table;
                $lines[] = 'DROP TABLE IF EXISTS ' . $quotedTable . ';';
                // SHOW CREATE TABLE prints several lines; join them so the statement stays on one line
                $lines[] = str_replace(["\r\n", "\n", "\r"], ' ', $create[1]) . ';';

                $rowCounts[$table] = 0;
                $columns = null;
                $batch = [];
                $statement = $this->pdo->query('SELECT * FROM ' . $quotedTable);
                while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                    if ($columns === null) {
                        $columns = '(`' . implode('`, `', array_keys($row)) . '`)';
                    }
                    $batch[] = $this->valuesList($row);
                    $rowCounts[$table]++;
                    if (count($batch) === self::ROWS_PER_INSERT) {
                        $lines[] = 'INSERT INTO ' . $quotedTable . ' ' . $columns . ' VALUES ' . implode(', ', $batch) . ';';
                        $batch = [];
                    }
                }
                if (count($batch) > 0) {
                    $lines[] = 'INSERT INTO ' . $quotedTable . ' ' . $columns . ' VALUES ' . implode(', ', $batch) . ';';
                }
            }
            $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $exception) {
            $this->pdo->exec('ROLLBACK');
            throw $exception;
        }
        return ['database' => $database, 'sql' => implode("\n", $lines) . "\n", 'row_counts' => $rowCounts];
    }

    // one row as "(1, 'text', NULL)"; PDO::quote() escapes quotes, backslashes AND line breaks (\n),
    // so a value with several lines (an abstract) still fits on one line of the backup
    private function valuesList(array $row): string {
        $values = [];
        foreach ($row as $value) {
            if ($value === null) {
                $values[] = 'NULL';
            } else {
                $values[] = $this->pdo->quote((string) $value);
            }
        }
        return '(' . implode(', ', $values) . ')';
    }

    // the base tables of the connected database (views are not used by this application)
    private function tableNames(): array {
        $tables = [];
        foreach ($this->pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
            if ($row[1] !== 'BASE TABLE') {
                continue;
            }
            if (!preg_match('/\A[A-Za-z0-9_]+\z/', $row[0])) {
                throw new RuntimeException('Unexpected table name; backup stopped.');
            }
            $tables[] = $row[0];
        }
        return $tables;
    }

    // the stored PDF names in storage/theses (the "pending" folder holds unfinished autofill uploads and is skipped)
    private function storedPdfs(): array {
        $names = [];
        if (!is_dir($this->thesisDirectory)) {
            return $names;
        }
        foreach (scandir($this->thesisDirectory) as $name) {
            if (preg_match(self::PDF_NAME_PATTERN, $name) && is_file($this->thesisDirectory . DIRECTORY_SEPARATOR . $name)) {
                $names[] = $name;
            }
        }
        sort($names);
        return $names;
    }

    // ---------------------------------------------------------------------------------------------
    // listing
    // ---------------------------------------------------------------------------------------------

    // the backups in the backup folder, newest first: ['file' => name, 'size' => bytes, 'made_at' => unix time]
    public function listBackups(): array {
        $backups = [];
        if (!is_dir($this->backupDirectory)) {
            return $backups;
        }
        foreach (scandir($this->backupDirectory) as $name) {
            $path = $this->backupDirectory . DIRECTORY_SEPARATOR . $name;
            if (preg_match(self::FILE_PATTERN, $name) && is_file($path)) {
                $backups[] = ['file' => $name, 'size' => filesize($path), 'made_at' => filemtime($path)];
            }
        }
        // the name starts with the date and time, so sorting the names backwards puts the newest first
        usort($backups, 'Scholar\Backup::newerFirst');
        return $backups;
    }

    // sort helper for listBackups(): a negative result puts $first before $second
    public static function newerFirst(array $first, array $second): int {
        return strcmp($second['file'], $first['file']);
    }

    // the full path of a backup in the backup folder, or null when the name is not one of our backup files
    // (this also stops names such as "../.env" from reaching any other file)
    public function pathOf(string $fileName): ?string {
        if (!preg_match(self::FILE_PATTERN, $fileName)) {
            return null;
        }
        $path = $this->backupDirectory . DIRECTORY_SEPARATOR . $fileName;
        if (!is_file($path)) {
            return null;
        }
        return $path;
    }

    // ---------------------------------------------------------------------------------------------
    // checking a backup
    // ---------------------------------------------------------------------------------------------

    // opens a backup and checks every part against the fingerprints in its manifest
    // returns ['manifest' => array or null, 'sql' => SQL text or '', 'problems' => list of messages]
    // an empty problems list means the backup is complete and unchanged since it was made
    public static function check(string $path): array {
        $result = ['manifest' => null, 'sql' => '', 'problems' => []];
        if (!is_file($path) || !str_ends_with(strtolower($path), '.tar')) {
            $result['problems'][] = 'The file is not a .tar backup.';
            return $result;
        }
        try {
            $archive = new PharData($path);
        } catch (\Throwable $exception) {
            $result['problems'][] = 'The file cannot be opened as a backup.';
            return $result;
        }

        // every entry must be one of the three expected kinds; anything else (for example "../x") is refused
        $entries = [];
        $iterator = new \RecursiveIteratorIterator($archive);
        foreach ($iterator as $entry) {
            // the path inside the archive, e.g. "files/theses/abc....pdf": everything after the LAST ".tar/"
            // in "phar://C:/.../scholar_backup_....tar/files/theses/abc....pdf" (Windows may mix / and \ in the first part)
            $fullName = str_replace('\\', '/', $entry->getPathname());
            $inside = substr($fullName, strrpos($fullName, '.tar/') + 5);
            $entries[$inside] = $entry;
        }
        foreach (array_keys($entries) as $inside) {
            $isKnown = $inside === 'manifest.json' || $inside === 'database.sql.gz'
                || (str_starts_with($inside, 'files/theses/') && preg_match(self::PDF_NAME_PATTERN, substr($inside, 13)));
            if (!$isKnown) {
                $result['problems'][] = 'Unexpected item in the backup: ' . $inside;
            }
        }
        if (!isset($entries['manifest.json']) || !isset($entries['database.sql.gz'])) {
            $result['problems'][] = 'The backup is missing its manifest or its database.';
            return $result;
        }

        $manifest = json_decode((string) file_get_contents($entries['manifest.json']->getPathname()), true);
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT_VERSION
            || !isset($manifest['tables'], $manifest['files'], $manifest['database_sha256'])
            || !is_array($manifest['tables']) || !is_array($manifest['files'])) {
            $result['problems'][] = 'The backup manifest is not readable.';
            return $result;
        }
        $result['manifest'] = $manifest;

        $sql = gzdecode((string) file_get_contents($entries['database.sql.gz']->getPathname()));
        if ($sql === false || !hash_equals((string) $manifest['database_sha256'], hash('sha256', $sql))) {
            $result['problems'][] = 'The database part does not match its fingerprint (damaged or changed).';
        } else {
            $result['sql'] = $sql;
        }
        foreach ($manifest['files'] as $pdfName => $expectedHash) {
            $inside = 'files/theses/' . $pdfName;
            if (!is_string($pdfName) || !preg_match(self::PDF_NAME_PATTERN, $pdfName) || !isset($entries[$inside])) {
                $result['problems'][] = 'A PDF listed in the manifest is missing: ' . $pdfName;
                continue;
            }
            if (!hash_equals((string) $expectedHash, hash_file('sha256', $entries[$inside]->getPathname()))) {
                $result['problems'][] = 'A PDF does not match its fingerprint: ' . $pdfName;
            }
        }
        foreach (array_keys($entries) as $inside) {
            if (str_starts_with($inside, 'files/theses/') && !isset($manifest['files'][substr($inside, 13)])) {
                $result['problems'][] = 'A PDF in the backup is not listed in its manifest: ' . substr($inside, 13);
            }
        }
        return $result;
    }

    // ---------------------------------------------------------------------------------------------
    // restoring
    // ---------------------------------------------------------------------------------------------

    // REPLACES every table of the database that $targetPdo is connected to with the backup's tables,
    // and copies the backup's PDFs into $pdfDirectory (existing PDFs with other names are left alone)
    // nothing is changed when check() finds a problem
    // returns ['tables' => count, 'pdf_count' => count]
    // MySQL cannot undo CREATE/DROP TABLE, so a restore cannot be rolled back halfway: the terminal command makes
    // a fresh backup of the current state first
    public static function restore(PDO $targetPdo, string $path, string $pdfDirectory): array {
        $checked = self::check($path);
        if (count($checked['problems']) > 0) {
            throw new RuntimeException('The backup did not pass its check: ' . $checked['problems'][0]);
        }

        // 1. the database: drop the current tables, then run the backup's statements one line at a time
        $targetPdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($targetPdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
                if ($row[1] === 'BASE TABLE' && preg_match('/\A[A-Za-z0-9_]+\z/', $row[0])) {
                    $targetPdo->exec('DROP TABLE `' . $row[0] . '`');
                }
            }
            foreach (explode("\n", $checked['sql']) as $line) {
                // skip empty lines and "-- comment" lines; every other line is one complete statement
                if ($line === '' || str_starts_with($line, '--')) {
                    continue;
                }
                $targetPdo->exec($line);
            }
        } finally {
            $targetPdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        // 2. the PDFs: copied in after the database, each one checked again after writing
        if (!is_dir($pdfDirectory) && !mkdir($pdfDirectory, 0700, true)) {
            throw new RuntimeException('The PDF folder could not be created.');
        }
        $archive = new PharData($path);
        foreach ($checked['manifest']['files'] as $pdfName => $expectedHash) {
            $target = rtrim($pdfDirectory, '/\\') . DIRECTORY_SEPARATOR . $pdfName;
            if (!copy($archive['files/theses/' . $pdfName]->getPathname(), $target)
                || !hash_equals((string) $expectedHash, hash_file('sha256', $target))) {
                throw new RuntimeException('A PDF could not be restored: ' . $pdfName);
            }
        }
        return ['tables' => count($checked['manifest']['tables']), 'pdf_count' => count($checked['manifest']['files'])];
    }
}
