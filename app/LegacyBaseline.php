<?php
// this is for handling original database schema through SHA-256 checks
declare(strict_types=1);

namespace Scholar;

use PDO;
use RuntimeException;

// only used when adopting another pre-Phinx database; normal login never calls this
class LegacyBaseline {
    public static function assertMatches(PDO $pdo): void {
        $statement = $pdo->query('SELECT version, checksum FROM schema_migrations ORDER BY version');
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $checksums = [];
        foreach ($rows as $row) {
            $checksums[$row['version']] = $row['checksum']; // filename-to-checksum lookup
        }

        $expectedFiles = ['001_roles.sql', '002_users.sql', '003_theses.sql', '004_thesis_authors.sql'];
        if (array_keys($checksums) !== $expectedFiles) {
            throw new RuntimeException('Legacy migration history does not match the saved baseline.');
        }

        foreach ($checksums as $file => $expectedChecksum) {
            $path = __DIR__ . '/../database/migrations/' . $file;
            $actualChecksum = hash_file('sha256', $path); // fingerprint the original SQL file
            if (!hash_equals($expectedChecksum, $actualChecksum)) {
                throw new RuntimeException('Legacy SQL checksum differs.');
            }
        }

        $manifestText = file_get_contents(__DIR__ . '/../database/legacy_schema.json');
        $manifest = json_decode($manifestText, true, 512, JSON_THROW_ON_ERROR); // invalid JSON stops adoption
        $allowedTables = ['roles', 'users', 'theses', 'thesis_authors'];

        foreach ($manifest as $table => $expectedHash) {
            if (!in_array($table, $allowedTables, true)) {
                throw new RuntimeException('Invalid schema manifest.'); // accept only fixed local names
            }

            $statement = $pdo->query('SHOW CREATE TABLE ' . $table); // names were checked against the fixed list
            $row = $statement->fetch(PDO::FETCH_NUM);
            $definition = $row[1]; // SHOW CREATE returns the name first, then its CREATE statement
            $actualHash = self::schemaHash($definition);
            if (!hash_equals($expectedHash, $actualHash)) {
                throw new RuntimeException('Existing schema differs; inspect before adopting.');
            }
        }
    }

    public static function schemaHash(string $definition): string {
        // inserts change the next AUTO_INCREMENT value, not the actual table structure
        $structureOnly = preg_replace('/ AUTO_INCREMENT=\d+/', '', $definition);
        return hash('sha256', $structureOnly);
    }
}
