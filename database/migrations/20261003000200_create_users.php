<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

class CreateUsers extends AbstractMigration {
    public function up(): void {
        $sqlPath = __DIR__ . '/002_users.sql';
        $sql = file_get_contents($sqlPath);
        $this->execute($sql);
    }

    public function down(): void {
        // prevent accidental rollback of core tables
        throw new RuntimeException('Destructive rollback is not supported for this provisional foundation.');
    }
}
