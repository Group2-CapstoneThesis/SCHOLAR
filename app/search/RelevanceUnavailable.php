<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Search;

use RuntimeException;

// thrown when an AI relevance explanation cannot be made (no key, OpenRouter down or slow, an unusable answer,
// or the rate limit); the search page then shows the results and their scores without an explanation
class RelevanceUnavailable extends RuntimeException {
}
