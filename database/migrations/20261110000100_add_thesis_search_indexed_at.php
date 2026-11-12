<?php
declare(strict_types=1); // keep typed values strict

use Phinx\Migration\AbstractMigration; // the migration parent class comes from Phinx

// semantic search (M14): when each record was last put into the search index (Qdrant)
// NULL means "not in the index yet, or its last indexing failed", e.g. because Ollama was not running when it was saved;
// "bin/console.php reindex-search --missing" catches those up later, so saving a record never depends on the search services
class AddThesisSearchIndexedAt extends AbstractMigration {
    public function up(): void {
        $this->execute('ALTER TABLE theses ADD COLUMN search_indexed_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at');
    }

    public function down(): void {
        // the column only describes the index, which can always be rebuilt, so removing it loses nothing
        $this->execute('ALTER TABLE theses DROP COLUMN search_indexed_at');
    }
}
