<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Search;

use RuntimeException;

// thrown when Ollama or Qdrant cannot be reached or answers with an error (M14)
// pages catch it and fall back to word search, so semantic search being down never breaks the site
class SearchUnavailable extends RuntimeException {
}
