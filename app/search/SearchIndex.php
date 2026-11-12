<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Search;

use PDO;
use Throwable;

// keeps Qdrant's copy of the thesis records up to date and answers meaning-based searches (M14)
// MySQL stays the source of truth: Qdrant only holds an embedding and a few filters per record, and can always be rebuilt
class SearchIndex {
    // how long a search may wait for the query's embedding and the Qdrant answer before the page falls back to word search
    private const QUERY_TIMEOUT_SECONDS = 10;
    // indexing runs after saving a record, so it may take a little longer (a long abstract on a CPU-only server)
    private const INDEX_TIMEOUT_SECONDS = 60;
    // records embedded together during a full rebuild
    private const BATCH_SIZE = 8;

    private PDO $pdo;
    private EmbeddingClient $embeddings;
    private QdrantClient $qdrant;
    private float $minScore;

    public function __construct(PDO $pdo, EmbeddingClient $embeddings, QdrantClient $qdrant, float $minScore) {
        $this->pdo = $pdo;
        $this->embeddings = $embeddings;
        $this->qdrant = $qdrant;
        $this->minScore = $minScore;
    }

    // the usual way to get the index, from the .env settings; null when semantic search is switched off
    public static function create(PDO $pdo): ?SearchIndex {
        $settings = loadConfiguration()['search'];
        if (!$settings['enabled']) {
            return null;
        }
        return new SearchIndex(
            $pdo,
            new EmbeddingClient($settings['ollama_url'], $settings['model']),
            new QdrantClient($settings['qdrant_url'], $settings['qdrant_api_key'], $settings['collection']),
            $settings['min_score']
        );
    }

    public function minScore(): float {
        return $this->minScore;
    }

    // ---------------------------------------------------------------------------------------------
    // keeping the index up to date
    // ---------------------------------------------------------------------------------------------

    // pages call this right after a record is created, edited, published, unpublished, archived or restored
    // it never stops the page: if Ollama or Qdrant is down, the record is marked "not indexed" and a note goes into
    // the private log; the word search still finds the record, and "reindex-search --missing" catches it up later
    // returns true when the record is now in the index
    public static function refreshQuietly(PDO $pdo, int $thesisId): bool {
        try {
            $index = self::create($pdo);
            if ($index === null) {
                return false; // semantic search is switched off
            }
            $index->indexThesis($thesisId);
            return true;
        } catch (Throwable $exception) {
            self::logProblem('indexing record ' . $thesisId, $exception);
            try {
                $statement = $pdo->prepare('UPDATE theses SET search_indexed_at = NULL WHERE id = :id');
                $statement->execute(['id' => $thesisId]);
            } catch (Throwable $ignored) {
                // the note in the log is enough; the record itself was already saved
            }
            return false;
        }
    }

    // puts one record into the index (or removes it when the record no longer exists)
    public function indexThesis(int $thesisId): void {
        $rows = $this->recordsForIndex('WHERE t.id = :id', ['id' => $thesisId]);
        if (count($rows) === 0) {
            $this->qdrant->deletePoints([$thesisId]);
            return;
        }
        $this->indexRows($rows);
    }

    // rebuilds the whole index from MySQL: removes the collection, then embeds every record again
    // returns how many records were indexed
    public function rebuild(): int {
        $this->qdrant->dropCollection();
        $rows = $this->recordsForIndex('', []);
        // array_chunk() splits the list into groups of BATCH_SIZE, so Ollama embeds several records per request
        foreach (array_chunk($rows, self::BATCH_SIZE) as $batch) {
            $this->indexRows($batch);
        }
        return count($rows);
    }

    // indexes only the records that are missing or changed since they were last indexed, and removes points of records
    // that no longer exist; returns ['indexed' => count, 'removed' => count]
    public function catchUp(): array {
        $rows = $this->recordsForIndex(
            'WHERE t.search_indexed_at IS NULL OR t.search_indexed_at < COALESCE(t.updated_at, t.created_at)', []
        );
        foreach (array_chunk($rows, self::BATCH_SIZE) as $batch) {
            $this->indexRows($batch);
        }
        $removed = 0;
        if ($this->qdrant->collectionExists()) {
            $existingIds = array_map('intval', $this->pdo->query('SELECT id FROM theses')->fetchAll(PDO::FETCH_COLUMN));
            // array_diff() keeps the ids in Qdrant that are not in the theses table any more
            $leftoverIds = array_values(array_diff($this->qdrant->allPointIds(), $existingIds));
            $this->qdrant->deletePoints($leftoverIds);
            $removed = count($leftoverIds);
        }
        return ['indexed' => count($rows), 'removed' => $removed];
    }

    // removes one record's point; used when a test record is deleted during cleanup
    public function removeThesis(int $thesisId): void {
        if ($this->qdrant->collectionExists()) {
            $this->qdrant->deletePoints([$thesisId]);
        }
    }

    // numbers for "bin/console.php search-status": records, records needing indexing, points in Qdrant
    public function status(): array {
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM theses')->fetchColumn();
        $waiting = (int) $this->pdo->query('
            SELECT COUNT(*) FROM theses t
            WHERE t.search_indexed_at IS NULL OR t.search_indexed_at < COALESCE(t.updated_at, t.created_at)
        ')->fetchColumn();
        $points = 0;
        if ($this->qdrant->collectionExists()) {
            $points = count($this->qdrant->allPointIds());
        }
        return ['records' => $total, 'waiting' => $waiting, 'points' => $points, 'collection' => $this->qdrant->collectionName()];
    }

    // ---------------------------------------------------------------------------------------------
    // searching
    // ---------------------------------------------------------------------------------------------

    // for one typed search: how close in meaning each record is to the query
    // returns ['meaning' => [id => similarity] for the closest records at or above the minimum score,
    //          'words' => [id => similarity] for the word-match records in $wordMatchIds]
    // throws SearchUnavailable when Ollama or Qdrant does not answer in time
    public function similarities(string $queryText, array $filters, bool $isAdmin, array $wordMatchIds): array {
        if (!$this->qdrant->collectionExists()) {
            throw new SearchUnavailable('The search index has not been built yet.');
        }
        $vectors = $this->embeddings->embed([$queryText], self::QUERY_TIMEOUT_SECONDS);
        $vector = $vectors[0];
        $filter = HybridRanking::qdrantFilter($filters, $isAdmin);
        $meaning = $this->qdrant->query($vector, $filter, HybridRanking::MEANING_CANDIDATES, $this->minScore, self::QUERY_TIMEOUT_SECONDS);

        // the similarity of every word match too, so they can be ranked among themselves
        // ("has_id" limits the question to those records)
        $words = [];
        if (count($wordMatchIds) > 0) {
            $wordFilter = $filter;
            if (!isset($wordFilter['must'])) {
                $wordFilter['must'] = [];
            }
            $wordFilter['must'][] = ['has_id' => array_map('intval', $wordMatchIds)];
            $words = $this->qdrant->query($vector, $wordFilter, count($wordMatchIds), null, self::QUERY_TIMEOUT_SECONDS);
        }
        return ['meaning' => $meaning, 'words' => $words];
    }

    // ---------------------------------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------------------------------

    // the columns the index needs, for the records matching $where (written by this class only)
    private function recordsForIndex(string $where, array $values): array {
        $statement = $this->pdo->prepare("
            SELECT t.id, t.title, t.keywords, t.abstract, t.is_public, t.archived_at, t.publication_year, t.program, t.campus
            FROM theses t
            $where
            ORDER BY t.id
        ");
        $statement->execute($values);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    // embeds these records, saves them in Qdrant, and marks them as indexed in MySQL
    private function indexRows(array $rows): void {
        if (count($rows) === 0) {
            return;
        }
        $texts = [];
        foreach ($rows as $row) {
            $texts[] = HybridRanking::documentText($row);
        }
        $vectors = $this->embeddings->embed($texts, self::INDEX_TIMEOUT_SECONDS);
        if (!$this->qdrant->collectionExists()) {
            // the collection's size comes from the model itself (bge-m3: 1024), so another model needs no code change
            $this->qdrant->createCollection(count($vectors[0]));
        }
        $points = [];
        foreach ($rows as $position => $row) {
            $points[] = ['id' => (int) $row['id'], 'vector' => $vectors[$position], 'payload' => HybridRanking::payload($row)];
        }
        $this->qdrant->upsert($points);

        $ids = array_map('intval', array_column($rows, 'id'));
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        // only the index time changes; updated_at is left alone, so it still shows when RDPO last edited the record
        $statement = $this->pdo->prepare("UPDATE theses SET search_indexed_at = CURRENT_TIMESTAMP WHERE id IN ($placeholders)");
        $statement->execute($ids);
    }

    // a private log line like reportFailure() writes, without the exception's message (it could hold record text)
    public static function logProblem(string $whatFailed, Throwable $exception): void {
        $entry = date(DATE_ATOM) . ' search problem while ' . $whatFailed . ': type=' . get_class($exception)
            . ' file=' . basename($exception->getFile()) . ' line=' . $exception->getLine() . "\n";
        file_put_contents(__DIR__ . '/../../storage/logs/application.log', $entry, FILE_APPEND | LOCK_EX);
    }
}
