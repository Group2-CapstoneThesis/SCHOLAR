<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

class CreateThesisAuthors extends AbstractMigration {
    public function up(): void {
        $sqlPath = __DIR__ . '/004_thesis_authors.sql';
        $sql = file_get_contents($sqlPath);
        $this->execute($sql);
    }

    public function down(): void {
        // prevent accidental rollback of core tables
        throw new RuntimeException('Destructive rollback is not supported for this provisional foundation.');
    }
}
