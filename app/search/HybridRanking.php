<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Search;

// the rules of hybrid search (M14), kept apart from the database and the services so the unit tests can check them:
// - what text of a thesis is turned into an embedding, and which filters travel with it
// - how word matches (the old keyword search) and meaning matches (Qdrant) are combined into one ranked list
class HybridRanking {
    // a record that contains the typed words gets this much extra, so exact matches stay near the top
    // while records that only match by meaning are still listed after them when they are similar enough
    public const WORD_MATCH_BONUS = 0.15;

    // how many meaning matches Qdrant is asked for; more than enough for the few pages people look at
    public const MEANING_CANDIDATES = 50;

    // relevance levels shown beside the similarity score on the public search page (PROVISIONAL, tuned for bge-m3:
    // on the sample records a thesis on exactly the searched topic scored 0.66-0.76 and loosely related ones 0.50-0.53);
    // the similarity itself always comes from Qdrant, these only name its range
    public const HIGH_RELEVANCE = 0.65;
    public const MODERATE_RELEVANCE = 0.55;

    // 'High', 'Moderate' or 'Low' for a Qdrant similarity; null when the record has no similarity (not indexed yet)
    public static function relevanceLevel(?float $similarity): ?string {
        if ($similarity === null) {
            return null;
        }
        if ($similarity >= self::HIGH_RELEVANCE) {
            return 'High';
        }
        if ($similarity >= self::MODERATE_RELEVANCE) {
            return 'Moderate';
        }
        return 'Low';
    }

    // the text that is embedded for one thesis: only fields that are already public (PublicCatalog), in a fixed order
    // (C-04/C-05: only approved public metadata is embedded; the adviser, department and dates are not)
    // $row needs 'title', 'keywords' and 'abstract'; empty parts are skipped
    public static function documentText(array $row): string {
        $parts = [];
        foreach (['title', 'keywords', 'abstract'] as $field) {
            if (isset($row[$field]) && is_string($row[$field]) && trim($row[$field]) !== '') {
                $parts[] = trim($row[$field]);
            }
        }
        return implode("\n", $parts);
    }

    // the filters stored with a thesis's embedding in Qdrant, read from its database row
    public static function payload(array $row): array {
        $year = null;
        if ($row['publication_year'] !== null) {
            $year = (int) $row['publication_year'];
        }
        return [
            'is_public' => (int) $row['is_public'] === 1,
            'is_archived' => $row['archived_at'] !== null,
            'publication_year' => $year,
            'program' => (string) $row['program'],
            'campus' => (string) $row['campus'],
        ];
    }

    // the Qdrant filter matching the search page's filters (ThesisSearch::filtersFromQuery)
    // the public only ever gets published, non-archived records; RDPO's status choice is followed
    // (the database checks the same rules again on every result, so a stale payload can never show a hidden record)
    public static function qdrantFilter(array $filters, bool $isAdmin): array {
        $must = [];
        if (!$isAdmin) {
            $must[] = ['key' => 'is_public', 'match' => ['value' => true]];
            $must[] = ['key' => 'is_archived', 'match' => ['value' => false]];
        } elseif ($filters['status'] === 'active') {
            $must[] = ['key' => 'is_archived', 'match' => ['value' => false]];
        } elseif ($filters['status'] === 'public') {
            $must[] = ['key' => 'is_public', 'match' => ['value' => true]];
            $must[] = ['key' => 'is_archived', 'match' => ['value' => false]];
        } elseif ($filters['status'] === 'unpublished') {
            $must[] = ['key' => 'is_public', 'match' => ['value' => false]];
            $must[] = ['key' => 'is_archived', 'match' => ['value' => false]];
        } elseif ($filters['status'] === 'archived') {
            $must[] = ['key' => 'is_archived', 'match' => ['value' => true]];
        }
        // 'all' adds no status condition
        if ($filters['year'] !== null) {
            $must[] = ['key' => 'publication_year', 'match' => ['value' => (int) $filters['year']]];
        }
        if ($filters['program'] !== '') {
            $must[] = ['key' => 'program', 'match' => ['value' => $filters['program']]];
        }
        if ($filters['campus'] !== '') {
            $must[] = ['key' => 'campus', 'match' => ['value' => $filters['campus']]];
        }
        // Qdrant's "must" = every condition has to hold; no conditions means no filter at all
        if (count($must) === 0) {
            return [];
        }
        return ['must' => $must];
    }

    // combines the two kinds of matches into one list, best first:
    // $wordMatchIds   ids of records containing every typed word (the keyword search)
    // $wordScores     [id => similarity] for those word matches (missing when a record is not indexed yet)
    // $meaningScores  [id => similarity] for the closest records by meaning, already at or above $minScore
    // returns [['id' => ..., 'score' => ..., 'match' => 'words' | 'meaning' | 'both', 'similarity' => float|null], ...]
    // 'score' only orders the list; 'similarity' is Qdrant's own cosine similarity (null for a word match not indexed yet)
    // - every word match is kept; a meaning match is kept only when its similarity reaches $minScore
    // - score = similarity, plus WORD_MATCH_BONUS for a word match; ties go to the newer record (higher id)
    public static function combine(array $wordMatchIds, array $wordScores, array $meaningScores, float $minScore): array {
        $combined = [];
        foreach ($wordMatchIds as $id) {
            $id = (int) $id;
            $similarity = 0.0;
            $qdrantSimilarity = null;
            if (isset($wordScores[$id])) {
                $similarity = (float) $wordScores[$id];
                $qdrantSimilarity = $similarity;
            }
            $match = 'words';
            if ($similarity >= $minScore) {
                $match = 'both'; // contains the words AND is close in meaning
            }
            $combined[$id] = ['id' => $id, 'score' => $similarity + self::WORD_MATCH_BONUS, 'match' => $match, 'similarity' => $qdrantSimilarity];
        }
        foreach ($meaningScores as $id => $similarity) {
            $id = (int) $id;
            if (isset($combined[$id]) || (float) $similarity < $minScore) {
                continue; // already listed as a word match, or not similar enough
            }
            $combined[$id] = ['id' => $id, 'score' => (float) $similarity, 'match' => 'meaning', 'similarity' => (float) $similarity];
        }
        $list = array_values($combined);
        usort($list, 'Scholar\Search\HybridRanking::higherScoreFirst');
        return $list;
    }

    // sort helper for combine(): higher score first, then the higher (newer) id
    public static function higherScoreFirst(array $first, array $second): int {
        if ($first['score'] !== $second['score']) {
            if ($first['score'] > $second['score']) {
                return -1;
            }
            return 1;
        }
        return $second['id'] - $first['id'];
    }
}
