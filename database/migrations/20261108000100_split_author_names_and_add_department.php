<?php
declare(strict_types=1); // keep typed values strict

use Phinx\Migration\AbstractMigration; // the migration parent class comes from Phinx
use Scholar\Theses\AuthorNames; // phinx.php loads the app's autoloader, so app classes can be used here

// M12 (2026-11-08): the Registrar's completion list is sorted by last name and grouped by department (FB-03)
// 1. each author row gets its name in parts: given names, last name and an optional suffix
// 2. each thesis gets an optional department, because the list (and FB-05 analytics later) is per department
// author_name stays as the full name, so search and the record pages keep working unchanged
class SplitAuthorNamesAndAddDepartment extends AbstractMigration {
    public function up(): void {
        // given_names / last_name default to '' only so the old rows can be added first and filled in below;
        // the record form always saves both
        // the index serves the Registrar list, which sorts by last name, then given names
        $this->execute("
            ALTER TABLE thesis_authors
                ADD COLUMN given_names VARCHAR(200) NOT NULL DEFAULT '' AFTER author_name,
                ADD COLUMN last_name VARCHAR(200) NOT NULL DEFAULT '' AFTER given_names,
                ADD COLUMN suffix VARCHAR(20) NULL AFTER last_name,
                ADD INDEX idx_thesis_authors_last_name (last_name, given_names)
        ");

        // the department is optional: the official department list has not been confirmed yet,
        // and older records can be completed by RDPO later
        $this->execute("
            ALTER TABLE theses
                ADD COLUMN department VARCHAR(150) NULL AFTER program,
                ADD INDEX idx_theses_department (department)
        ");

        // fill in the parts of every author that already exists, using the same guess as autofill
        // ("Dela Cruz, Maria Clara S." and "Maria Clara S. Dela Cruz" both split correctly);
        // RDPO can correct any record on its edit form, where the parts are shown separately
        $pdo = $this->getAdapter()->getConnection(); // use the connection Phinx has already opened
        $update = $pdo->prepare('UPDATE thesis_authors SET given_names = :given_names, last_name = :last_name, suffix = :suffix WHERE id = :id');
        foreach ($pdo->query('SELECT id, author_name FROM thesis_authors')->fetchAll() as $row) {
            $parts = AuthorNames::splitFullName($row['author_name']);
            $suffix = null;
            if ($parts['suffix'] !== '') {
                $suffix = $parts['suffix'];
            }
            $update->execute([
                'given_names' => $parts['given_names'],
                'last_name' => $parts['last_name'],
                'suffix' => $suffix,
                'id' => $row['id'],
            ]);
        }
    }

    public function down(): void {
        // dropping these columns would erase name parts and departments that RDPO typed in, so rollback is refused
        throw new RuntimeException('Rollback would erase author name parts and departments and is not supported.');
    }
}
