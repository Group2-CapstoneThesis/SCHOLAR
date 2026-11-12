<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Search;

// the few Qdrant calls the system needs (M14): Qdrant keeps one embedding per thesis ("point") in a "collection"
// and finds the points closest in meaning to a query's embedding
// a point's id is the thesis id; its "payload" holds the filters (published, archived, year, program, campus)
class QdrantClient {
    // the payload fields Qdrant should index, so filtering on them stays fast as the collection grows
    private const PAYLOAD_INDEXES = [
        'is_public' => 'bool',
        'is_archived' => 'bool',
        'publication_year' => 'integer',
        'program' => 'keyword',
        'campus' => 'keyword',
    ];

    private string $baseUrl;
    private string $apiKey;
    private string $collection;

    public function __construct(string $baseUrl, string $apiKey, string $collection) {
        $this->baseUrl = $baseUrl;
        $this->apiKey = $apiKey;
        $this->collection = $collection;
    }

    public function collectionName(): string {
        return $this->collection;
    }

    // true when the collection has been created
    public function collectionExists(): bool {
        $answer = $this->call('GET', '/collections/' . $this->collection . '/exists', null, 5);
        return isset($answer['result']['exists']) && $answer['result']['exists'] === true;
    }

    // creates the collection for vectors of $size numbers, compared by cosine similarity (1 = same meaning)
    public function createCollection(int $size): void {
        $this->call('PUT', '/collections/' . $this->collection, ['vectors' => ['size' => $size, 'distance' => 'Cosine']], 30);
        foreach (self::PAYLOAD_INDEXES as $field => $type) {
            $this->call('PUT', '/collections/' . $this->collection . '/index?wait=true', ['field_name' => $field, 'field_schema' => $type], 30);
        }
    }

    // removes the whole collection (used before a full rebuild); a missing collection is not an error
    public function dropCollection(): void {
        if ($this->collectionExists()) {
            $this->call('DELETE', '/collections/' . $this->collection, null, 30);
        }
    }

    // adds or replaces points: each is ['id' => thesis id, 'vector' => [...], 'payload' => [...]]
    // "wait=true" returns only once Qdrant has saved them, so a search right after sees the change
    public function upsert(array $points): void {
        $this->call('PUT', '/collections/' . $this->collection . '/points?wait=true', ['points' => $points], 30);
    }

    // removes the points of these thesis ids (ids that are not there are simply ignored)
    public function deletePoints(array $ids): void {
        if (count($ids) === 0) {
            return;
        }
        $this->call('POST', '/collections/' . $this->collection . '/points/delete?wait=true', ['points' => array_values($ids)], 30);
    }

    // the closest points to $vector that pass $filter, best first: [thesis id => similarity score]
    // $scoreThreshold leaves out weaker matches; null keeps all of them
    public function query(array $vector, array $filter, int $limit, ?float $scoreThreshold, int $timeoutSeconds): array {
        $body = ['query' => $vector, 'limit' => $limit, 'with_payload' => false, 'with_vector' => false];
        if (count($filter) > 0) {
            $body['filter'] = $filter;
        }
        if ($scoreThreshold !== null) {
            $body['score_threshold'] = $scoreThreshold;
        }
        $answer = $this->call('POST', '/collections/' . $this->collection . '/points/query', $body, $timeoutSeconds);
        $scores = [];
        if (isset($answer['result']['points']) && is_array($answer['result']['points'])) {
            foreach ($answer['result']['points'] as $point) {
                $scores[(int) $point['id']] = (float) $point['score'];
            }
        }
        return $scores;
    }

    // every point id in the collection (used to remove points of records that no longer exist)
    public function allPointIds(): array {
        $ids = [];
        $offset = null;
        do {
            $body = ['limit' => 1000, 'with_payload' => false, 'with_vector' => false];
            if ($offset !== null) {
                $body['offset'] = $offset;
            }
            $answer = $this->call('POST', '/collections/' . $this->collection . '/points/scroll', $body, 30);
            foreach ($answer['result']['points'] as $point) {
                $ids[] = (int) $point['id'];
            }
            // Qdrant gives the next starting point, or null when every point has been listed
            $offset = $answer['result']['next_page_offset'];
        } while ($offset !== null);
        return $ids;
    }

    // one request to Qdrant, with the API key when one is set
    private function call(string $method, string $path, ?array $body, int $timeoutSeconds): array {
        $headers = [];
        if ($this->apiKey !== '') {
            $headers[] = 'api-key: ' . $this->apiKey;
        }
        return JsonHttp::request($method, $this->baseUrl . $path, $body, $timeoutSeconds, $headers);
    }
}
