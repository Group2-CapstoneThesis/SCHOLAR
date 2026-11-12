<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration; // the migration parent class comes from Phinx

// this class is our code; extending the library class lets Phinx run and record it
class CreateTheses extends AbstractMigration {
    public function up(): void {
        // create provisional thesis metadata using the unchanged original SQL definition
        $sqlPath = __DIR__ . '/003_theses.sql';
        $sql = file_get_contents($sqlPath);
        $this->execute($sql); // Phinx applies the SQL and records this migration version
    }

    public function down(): void {
        // these foundation tables must not be deleted as part of this development slice
        throw new RuntimeException('Destructive rollback is not supported for this provisional foundation.');
    }
}
