<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Search;

// turns text into embeddings (lists of numbers that capture meaning) through Ollama (M14)
// the model is bge-m3 by default (user's choice, 2026-11-10): 1024 numbers per text, and it understands Filipino too
class EmbeddingClient {
    private string $ollamaUrl;
    private string $model;

    public function __construct(string $ollamaUrl, string $model) {
        $this->ollamaUrl = $ollamaUrl;
        $this->model = $model;
    }

    // one embedding per text, in the same order; throws SearchUnavailable when Ollama is not answering
    // a search query uses a short timeout so the page never waits long; indexing a record may take longer
    public function embed(array $texts, int $timeoutSeconds): array {
        // keep_alive keeps the model loaded for 30 minutes after use, so the next search does not wait for it to load
        $answer = JsonHttp::request('POST', $this->ollamaUrl . '/api/embed', [
            'model' => $this->model,
            'input' => array_values($texts),
            'keep_alive' => '30m',
        ], $timeoutSeconds);
        if (!isset($answer['embeddings']) || !is_array($answer['embeddings']) || count($answer['embeddings']) !== count($texts)) {
            throw new SearchUnavailable('Ollama did not return one embedding per text.');
        }
        return $answer['embeddings'];
    }
}
