<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Theses;

use PDO;
use Scholar\Search\HybridRanking;
use Scholar\Search\SearchIndex;
use Scholar\Search\SearchUnavailable;

// the search and filters shared by the public search page and the RDPO record list
// public mode only ever selects PublicCatalog's columns and visible records; RDPO mode sees everything
// since M14 a typed search is "hybrid": records containing the words (keyword search) plus records close in meaning
// (semantic search through SearchIndex); without the search services it quietly falls back to the words alone
class ThesisSearch {
    public const RESULTS_PER_PAGE = 10;

    // sort key from the address bar => trusted ORDER BY text
    // a sort column cannot be a prepared value, so only these fixed choices are ever put into the SQL
    // the id at the end is a tie-breaker, so records with the same year never swap places between pages
    // 'relevance' ("Best match", M14) is ordered by HybridRanking; this SQL is only used when semantic search is unavailable
    private const SORT_ORDERS = [
        'relevance' => 't.publication_year DESC, t.id DESC',
        'newest' => 't.publication_year DESC, t.id DESC',
        'oldest' => 't.publication_year ASC, t.id ASC',
        'title' => 't.title ASC, t.id ASC',
        'updated' => 'COALESCE(t.updated_at, t.created_at) DESC, t.id DESC', // RDPO only: recently changed first
    ];

    // RDPO record list status filter => extra WHERE condition
    // 'active' (the default) hides archived records, the same way a trash folder stays out of the inbox
    private const STATUS_CONDITIONS = [
        'active' => 't.archived_at IS NULL',
        'public' => 't.is_public = 1 AND t.archived_at IS NULL',
        'unpublished' => 't.is_public = 0 AND t.archived_at IS NULL',
        'archived' => 't.archived_at IS NOT NULL',
        'all' => '1 = 1',
    ];

    // RDPO also needs to see visibility and recent changes in the list
    private const ADMIN_COLUMNS = ['id', 'title', 'publication_year', 'program', 'campus', 'abstract', 'keywords', 'is_public', 'archived_at', 'created_at', 'updated_at'];

    // the most word matches a hybrid search ranks; far more than anyone pages through
    private const WORD_MATCH_LIMIT = 500;

    private PDO $pdo;
    private ?SearchIndex $searchIndex;

    // $searchIndex is null for word search only (semantic search switched off, or code that does not need it)
    public function __construct(PDO $pdo, ?SearchIndex $searchIndex = null) {
        $this->pdo = $pdo;
        $this->searchIndex = $searchIndex;
    }

    // the order used when the address does not choose one: "Best match" for a typed search, otherwise newest first
    public static function defaultSort(string $query): string {
        if ($query !== '') {
            return 'relevance';
        }
        return 'newest';
    }

    // reads the search form from the address bar ($_GET) into safe, known values
    // anything unexpected falls back to a default instead of causing an error, because search pages are read-only
    // example: ?q=flood&year=2024&program=BS+IT&sort=oldest&page=2
    public static function filtersFromQuery(array $query, bool $isAdmin): array {
        $filters = ['q' => '', 'year' => null, 'program' => '', 'campus' => '', 'sort' => 'newest', 'status' => 'active', 'page' => 1];

        if (isset($query['q']) && is_string($query['q']) && mb_check_encoding($query['q'], 'UTF-8')) {
            $filters['q'] = mb_substr(trim($query['q']), 0, 200); // long pasted text is cut, not rejected
        }
        if (isset($query['year']) && is_string($query['year']) && preg_match('/\A[0-9]{4}\z/', $query['year'])) {
            $filters['year'] = (int) $query['year'];
        }
        if (isset($query['program']) && is_string($query['program']) && mb_check_encoding($query['program'], 'UTF-8')) {
            $filters['program'] = mb_substr(trim($query['program']), 0, 150);
        }
        $filters['sort'] = self::defaultSort($filters['q']);
        if (isset($query['sort']) && is_string($query['sort']) && isset(self::SORT_ORDERS[$query['sort']])) {
            // the "recently updated" order is an RDPO tool; the public page keeps the default instead
            if ($query['sort'] !== 'updated' || $isAdmin) {
                $filters['sort'] = $query['sort'];
            }
        }
        // the campus filter is on the RDPO record list and the public search (user's request, 2026-11-09);
        // only the five campus names are accepted
        if (isset($query['campus']) && is_string($query['campus']) && Campuses::isCampus($query['campus'])) {
            $filters['campus'] = $query['campus'];
        }
        if ($isAdmin && isset($query['status']) && is_string($query['status']) && isset(self::STATUS_CONDITIONS[$query['status']])) {
            $filters['status'] = $query['status'];
        }
        // "Best match" needs typed words; without them the list is simply newest first
        if ($filters['sort'] === 'relevance' && $filters['q'] === '') {
            $filters['sort'] = 'newest';
        }
        if (isset($query['page']) && is_string($query['page']) && preg_match('/\A[0-9]{1,5}\z/', $query['page']) && (int) $query['page'] >= 1) {
            $filters['page'] = (int) $query['page'];
        }
        return $filters;
    }

    // builds the address-bar text for a link to another results page while keeping the current filters
    // empty filters and defaults are left out, so links stay short
    // example: q "flood", year 2024, page 2 becomes "q=flood&year=2024&page=2"
    public static function queryString(array $filters, int $page): string {
        $parameters = [];
        if ($filters['q'] !== '') {
            $parameters['q'] = $filters['q'];
        }
        if ($filters['year'] !== null) {
            $parameters['year'] = $filters['year'];
        }
        if ($filters['program'] !== '') {
            $parameters['program'] = $filters['program'];
        }
        if ($filters['campus'] !== '') {
            $parameters['campus'] = $filters['campus'];
        }
        if ($filters['sort'] !== self::defaultSort($filters['q'])) {
            $parameters['sort'] = $filters['sort'];
        }
        if ($filters['status'] !== 'active') {
            $parameters['status'] = $filters['status'];
        }
        if ($page > 1) {
            $parameters['page'] = $page;
        }
        return http_build_query($parameters); // also encodes spaces and symbols safely for a URL
    }

    // returns ['rows' => results with an 'authors' list each, 'total' => matching count, 'page' => page shown,
    //          'pages' => page count, 'mode' => how the results were found]
    // mode: 'list'     no words typed: every record that passes the filters
    //       'hybrid'   words typed: records containing the words plus records close in meaning; each row has a 'match'
    //                  of 'words', 'meaning' or 'both'
    //       'words'    words typed, semantic search switched off: records containing the words only
    //       'words_fallback'  the same, because Ollama or Qdrant did not answer (the page says so)
    public function search(array $filters, bool $isAdmin): array {
        // who may see which records, and the year / program / campus filters (everything except the typed words)
        $base = $this->baseConditions($filters, $isAdmin);
        $columns = PublicCatalog::SEARCH_COLUMNS;
        if ($isAdmin) {
            $columns = self::ADMIN_COLUMNS;
        }

        $fellBack = false;
        if ($filters['q'] !== '' && $this->searchIndex !== null) {
            try {
                return $this->hybridSearch($filters, $isAdmin, $base, $columns);
            } catch (SearchUnavailable $exception) {
                // Ollama or Qdrant is down or slow: note it privately and fall back to the words, so the page still works
                SearchIndex::logProblem('searching', $exception);
                $fellBack = true;
            }
        }

        $words = $this->wordConditions($filters['q']);
        $conditions = array_merge($base['conditions'], $words['conditions']);
        $values = array_merge($base['values'], $words['values']);
        $whereClause = implode(' AND ', $conditions);
        $mode = 'list';
        if ($filters['q'] !== '' && $fellBack) {
            $mode = 'words_fallback';
        } elseif ($filters['q'] !== '') {
            $mode = 'words';
        }

        // first count every match, so the page can say "Showing 11-20 of 34" and build page links
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM theses t WHERE $whereClause");
        $statement->execute($values);
        $total = (int) $statement->fetchColumn();

        // a page number past the end (for example page 9 of 3) shows the last page instead of an empty list
        $pages = max(1, (int) ceil($total / self::RESULTS_PER_PAGE));
        $page = min($filters['page'], $pages);
        $offset = ($page - 1) * self::RESULTS_PER_PAGE;

        $columnList = 't.' . implode(', t.', $columns); // fixed names from the lists above
        $orderBy = self::SORT_ORDERS[$filters['sort']];
        $statement = $this->pdo->prepare("
            SELECT $columnList
            FROM theses t
            WHERE $whereClause
            ORDER BY $orderBy
            LIMIT :limit OFFSET :offset
        ");
        foreach ($values as $name => $value) {
            $statement->bindValue($name, $value);
        }
        // LIMIT and OFFSET need real integers, so they are bound with PDO::PARAM_INT
        $statement->bindValue('limit', self::RESULTS_PER_PAGE, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();

        $rows = $this->attachAuthors($rows);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'mode' => $mode];
    }

    // the hybrid search (M14): word matches and meaning matches, ranked together by HybridRanking
    // throws SearchUnavailable when Ollama or Qdrant does not answer, so search() can fall back to the words alone
    private function hybridSearch(array $filters, bool $isAdmin, array $base, array $columns): array {
        // 1. the records containing every typed word (the same rule as the plain word search)
        $words = $this->wordConditions($filters['q']);
        $whereClause = implode(' AND ', array_merge($base['conditions'], $words['conditions']));
        $statement = $this->pdo->prepare("SELECT t.id FROM theses t WHERE $whereClause ORDER BY t.id DESC LIMIT " . self::WORD_MATCH_LIMIT);
        $statement->execute(array_merge($base['values'], $words['values']));
        $wordMatchIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));

        // 2. how close in meaning the query is to the closest records and to each word match
        $similarities = $this->searchIndex->similarities($filters['q'], $filters, $isAdmin, $wordMatchIds);
        $ranked = HybridRanking::combine($wordMatchIds, $similarities['words'], $similarities['meaning'], $this->searchIndex->minScore());

        // 3. MySQL checks every result again against the visibility and filters, because Qdrant's copy of the
        //    filters could be a moment out of date; a record the reader may not see is never shown
        $matchById = [];
        $similarityById = [];
        foreach ($ranked as $item) {
            $matchById[$item['id']] = $item['match'];
            $similarityById[$item['id']] = $item['similarity'];
        }
        $allowedIds = $this->idsPassing(array_keys($matchById), $base);
        $orderedIds = [];
        foreach ($ranked as $item) {
            if (isset($allowedIds[$item['id']])) {
                $orderedIds[] = $item['id'];
            }
        }
        // any other chosen order (newest, oldest, title, recently changed) is applied by MySQL to the same records
        if ($filters['sort'] !== 'relevance' && count($orderedIds) > 0) {
            $orderedIds = $this->idsInOrder($orderedIds, self::SORT_ORDERS[$filters['sort']]);
        }

        $total = count($orderedIds);
        $pages = max(1, (int) ceil($total / self::RESULTS_PER_PAGE));
        $page = min($filters['page'], $pages);
        // array_slice() keeps just this page's ids, in order
        $pageIds = array_slice($orderedIds, ($page - 1) * self::RESULTS_PER_PAGE, self::RESULTS_PER_PAGE);

        $rows = [];
        if (count($pageIds) > 0) {
            $columnList = 't.' . implode(', t.', $columns);
            $placeholders = implode(', ', array_fill(0, count($pageIds), '?'));
            $statement = $this->pdo->prepare("SELECT $columnList FROM theses t WHERE t.id IN ($placeholders)");
            $statement->execute($pageIds);
            $rowsById = [];
            foreach ($statement->fetchAll() as $row) {
                $rowsById[(int) $row['id']] = $row;
            }
            // put the rows back in the ranked order and say how each one matched, with Qdrant's similarity
            // ('similarity' 0-1, or null when not indexed yet) and its level (High / Moderate / Low)
            foreach ($pageIds as $id) {
                $row = $rowsById[$id];
                $row['match'] = $matchById[$id];
                $row['similarity'] = $similarityById[$id];
                $row['relevance_level'] = HybridRanking::relevanceLevel($similarityById[$id]);
                $rows[] = $row;
            }
        }
        $rows = $this->attachAuthors($rows);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'mode' => 'hybrid'];
    }

    // the WHERE parts for who may see what and the year / program / campus filters, with their values
    private function baseConditions(array $filters, bool $isAdmin): array {
        $conditions = [];
        $values = [];
        // who may see which records: the public only sees published, non-archived ones
        if ($isAdmin) {
            $conditions[] = self::STATUS_CONDITIONS[$filters['status']];
        } else {
            $conditions[] = PublicCatalog::VISIBLE_CONDITION;
        }
        if ($filters['year'] !== null) {
            $conditions[] = 't.publication_year = :year';
            $values['year'] = $filters['year'];
        }
        if ($filters['program'] !== '') {
            $conditions[] = 't.program = :program';
            $values['program'] = $filters['program'];
        }
        if ($filters['campus'] !== '') {
            $conditions[] = 't.campus = :campus';
            $values['campus'] = $filters['campus'];
        }
        return ['conditions' => $conditions, 'values' => $values];
    }

    // the WHERE parts for the typed words: every word must appear somewhere in the title, abstract, keywords or
    // an author name; example: "flood sensors" finds records containing both words, in any of those places
    private function wordConditions(string $query): array {
        $conditions = [];
        $values = [];
        $words = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
        $words = array_slice($words, 0, 8); // a few words are plenty; this also keeps the query small
        foreach ($words as $index => $word) {
            // % and _ are LIKE wildcards; escape them so searching "100%" looks for the real "%" character
            $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word) . '%';
            // MySQL's real prepared statements cannot reuse one placeholder name, so each place gets its own
            $conditions[] = "(t.title LIKE :title$index
                OR t.abstract LIKE :abstract$index
                OR t.keywords LIKE :keywords$index
                OR EXISTS (SELECT 1 FROM thesis_authors a WHERE a.thesis_id = t.id AND a.author_name LIKE :author$index))";
            $values["title$index"] = $pattern;
            $values["abstract$index"] = $pattern;
            $values["keywords$index"] = $pattern;
            $values["author$index"] = $pattern;
        }
        return ['conditions' => $conditions, 'values' => $values];
    }

    // which of $ids pass the visibility and filters, as [id => true]
    private function idsPassing(array $ids, array $base): array {
        if (count($ids) === 0) {
            return [];
        }
        // named placeholders, since the base conditions already use named ones (:year, :program, :campus)
        $values = $base['values'];
        $names = [];
        foreach (array_values($ids) as $position => $id) {
            $names[] = ':id' . $position;
            $values['id' . $position] = (int) $id;
        }
        $whereClause = implode(' AND ', $base['conditions']);
        $statement = $this->pdo->prepare('SELECT t.id FROM theses t WHERE ' . $whereClause . ' AND t.id IN (' . implode(', ', $names) . ')');
        $statement->execute($values);
        $allowed = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $allowed[(int) $id] = true;
        }
        return $allowed;
    }

    // $ids put in one of the fixed SORT_ORDERS by MySQL
    private function idsInOrder(array $ids, string $orderBy): array {
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare("SELECT t.id FROM theses t WHERE t.id IN ($placeholders) ORDER BY $orderBy");
        $statement->execute(array_values($ids));
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    // years and programs that actually have records, for the filter dropdowns
    // public mode only lists values from visible records, so a hidden record's program never shows up
    public function filterOptions(bool $isAdmin): array {
        $visibility = 't.archived_at IS NULL';
        if (!$isAdmin) {
            $visibility = PublicCatalog::VISIBLE_CONDITION;
        }
        $years = $this->pdo->query("
            SELECT DISTINCT t.publication_year FROM theses t
            WHERE $visibility AND t.publication_year IS NOT NULL
            ORDER BY t.publication_year DESC
        ")->fetchAll(PDO::FETCH_COLUMN);
        $programs = $this->pdo->query("
            SELECT DISTINCT t.program FROM theses t
            WHERE $visibility AND t.program IS NOT NULL
            ORDER BY t.program
        ")->fetchAll(PDO::FETCH_COLUMN);
        // departments are only offered to RDPO (form suggestions and the Registrar list);
        // the public pages do not show the department until RDPO confirms the public fields (P-03)
        $departments = [];
        if ($isAdmin) {
            $departments = $this->pdo->query("
                SELECT DISTINCT t.department FROM theses t
                WHERE $visibility AND t.department IS NOT NULL
                ORDER BY t.department
            ")->fetchAll(PDO::FETCH_COLUMN);
        }
        return ['years' => $years, 'programs' => $programs, 'departments' => $departments];
    }

    // loads the authors of all listed theses with ONE query instead of one query per thesis
    // example: rows for theses 4 and 9 get ['authors' => ['Ana', 'Ben']] and ['authors' => ['Cara']]
    private function attachAuthors(array $rows): array {
        if (count($rows) === 0) {
            return $rows;
        }
        $thesisIds = [];
        foreach ($rows as $row) {
            $thesisIds[] = (int) $row['id'];
        }
        // one "?" per id, for example "?, ?, ?" for three theses
        $placeholders = implode(', ', array_fill(0, count($thesisIds), '?'));
        $statement = $this->pdo->prepare("
            SELECT thesis_id, author_name
            FROM thesis_authors
            WHERE thesis_id IN ($placeholders)
            ORDER BY thesis_id, author_order
        ");
        $statement->execute($thesisIds);

        $authorsByThesis = [];
        foreach ($statement->fetchAll() as $authorRow) {
            $authorsByThesis[(int) $authorRow['thesis_id']][] = $authorRow['author_name'];
        }
        foreach ($rows as $index => $row) {
            $rows[$index]['authors'] = [];
            if (isset($authorsByThesis[(int) $row['id']])) {
                $rows[$index]['authors'] = $authorsByThesis[(int) $row['id']];
            }
        }
        return $rows;
    }
}
