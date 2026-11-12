<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Theses;

use PDO;

// the ONE place that decides which thesis fields the public may see
// the public search page, the public thesis page, and later the export/embedding step should all read these lists,
// so a private field (adviser, date submitted, who edited it, PDF location) can never leak through just one of them
// the lists follow the Chapter III chatbot field list; RDPO still has to confirm the public fields (P-03/P-08)
class PublicCatalog {
    // columns shown in the public search results list
    // campus was made public at the user's request on 2026-11-09
    // final_defense_date (already public on the thesis page) was added on 2026-11-12 so results show when the research was completed
    public const SEARCH_COLUMNS = ['id', 'title', 'publication_year', 'program', 'campus', 'abstract', 'keywords', 'final_defense_date'];

    // columns shown on the public thesis page; authors come from thesis_authors and are added separately
    public const DETAIL_COLUMNS = ['id', 'title', 'publication_year', 'program', 'campus', 'abstract', 'keywords', 'final_defense_date', 'page_count'];

    // a record is visible to the public only when RDPO published it AND it is not archived
    // this condition is shared with ThesisSearch so both pages hide exactly the same records
    public const VISIBLE_CONDITION = 't.is_public = 1 AND t.archived_at IS NULL';

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // returns the public fields and author names of one visible thesis, or null when it is hidden or missing
    // hidden and missing records both return null, so the public page cannot reveal that a hidden record exists
    public function findPublicThesis(int $thesisId): ?array {
        // turn ['id', 'title', ...] into "t.id, t.title, ..." for the SELECT list (fixed names, never request text)
        $columnList = 't.' . implode(', t.', self::DETAIL_COLUMNS);
        $statement = $this->pdo->prepare("
            SELECT $columnList
            FROM theses t
            WHERE t.id = :id AND " . self::VISIBLE_CONDITION . "
        ");
        $statement->execute(['id' => $thesisId]);
        $thesis = $statement->fetch();
        if (!$thesis) {
            return null;
        }

        $statement = $this->pdo->prepare('SELECT author_name FROM thesis_authors WHERE thesis_id = :id ORDER BY author_order');
        $statement->execute(['id' => $thesisId]);
        $thesis['authors'] = $statement->fetchAll(PDO::FETCH_COLUMN); // a plain list of names in order
        return $thesis;
    }
}
