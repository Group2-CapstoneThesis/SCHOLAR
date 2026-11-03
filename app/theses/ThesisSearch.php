<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Theses;

use PDO;

// the keyword search and filters shared by the public search page and the RDPO record list
// public mode only ever selects PublicCatalog's columns and visible records; RDPO mode sees everything
class ThesisSearch {
    public const RESULTS_PER_PAGE = 10;

    // sort key from the address bar => trusted ORDER BY text
    // a sort column cannot be a prepared value, so only these fixed choices are ever put into the SQL
    // the id at the end is a tie-breaker, so records with the same year never swap places between pages
    private const SORT_ORDERS = [
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
    private const ADMIN_COLUMNS = ['id', 'title', 'publication_year', 'program', 'abstract', 'keywords', 'is_public', 'archived_at', 'created_at', 'updated_at'];

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // reads the search form from the address bar ($_GET) into safe, known values
    // anything unexpected falls back to a default instead of causing an error, because search pages are read-only
    // example: ?q=flood&year=2024&program=BS+IT&sort=oldest&page=2
    public static function filtersFromQuery(array $query, bool $isAdmin): array {
        $filters = ['q' => '', 'year' => null, 'program' => '', 'sort' => 'newest', 'status' => 'active', 'page' => 1];

        if (isset($query['q']) && is_string($query['q']) && mb_check_encoding($query['q'], 'UTF-8')) {
            $filters['q'] = mb_substr(trim($query['q']), 0, 200); // long pasted text is cut, not rejected
        }
        if (isset($query['year']) && is_string($query['year']) && preg_match('/\A[0-9]{4}\z/', $query['year'])) {
            $filters['year'] = (int) $query['year'];
        }
        if (isset($query['program']) && is_string($query['program']) && mb_check_encoding($query['program'], 'UTF-8')) {
            $filters['program'] = mb_substr(trim($query['program']), 0, 150);
        }
        if (isset($query['sort']) && is_string($query['sort']) && isset(self::SORT_ORDERS[$query['sort']])) {
            // the "recently updated" order is an RDPO tool; the public page keeps the default instead
            if ($query['sort'] !== 'updated' || $isAdmin) {
                $filters['sort'] = $query['sort'];
            }
        }
        if ($isAdmin && isset($query['status']) && is_string($query['status']) && isset(self::STATUS_CONDITIONS[$query['status']])) {
            $filters['status'] = $query['status'];
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
        if ($filters['sort'] !== 'newest') {
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

    // returns ['rows' => results with an 'authors' list each, 'total' => matching count, 'page' => page shown, 'pages' => page count]
    public function search(array $filters, bool $isAdmin): array {
        $conditions = [];
        $values = [];

        // who may see which records: the public only sees published, non-archived ones
        if ($isAdmin) {
            $conditions[] = self::STATUS_CONDITIONS[$filters['status']];
            $columns = self::ADMIN_COLUMNS;
        } else {
            $conditions[] = PublicCatalog::VISIBLE_CONDITION;
            $columns = PublicCatalog::SEARCH_COLUMNS;
        }

        // every typed word must appear somewhere: title, abstract, keywords or an author name
        // example: "flood sensors" finds records containing both words, in any of those places
        $words = preg_split('/\s+/', $filters['q'], -1, PREG_SPLIT_NO_EMPTY);
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

        if ($filters['year'] !== null) {
            $conditions[] = 't.publication_year = :year';
            $values['year'] = $filters['year'];
        }
        if ($filters['program'] !== '') {
            $conditions[] = 't.program = :program';
            $values['program'] = $filters['program'];
        }

        $whereClause = implode(' AND ', $conditions);

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
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
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
        return ['years' => $years, 'programs' => $programs];
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
