<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Theses;

use PDO;
use Scholar\AuditLog;

// RDPO's record operations: read one record, create, edit, publish/unpublish, archive/restore
// every change is saved together with its audit entry inside one transaction
// permanent deletion is intentionally missing; archiving hides a record and can be undone (P-11 is still open)
class ThesisRecords {
    // the fields compared on edit, so the audit log can list exactly what changed
    // every editable field must be listed here, otherwise an edit to it would be missed
    private const EDITABLE_FIELDS = ['title', 'publication_year', 'program', 'department', 'campus', 'adviser','abstract', 'keywords', 'date_submitted', 'final_defense_date', 'page_count'];

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo; // receive the connection like the managers in the enrollment project
    }

    // returns every field of one record plus its authors and the usernames of who created/updated it,
    // or null when no record has that id; this is the RDPO view, not the public one
    public function findThesis(int $thesisId): ?array {
        $statement = $this->pdo->prepare("
            SELECT t.*, creator.username AS created_by_username, editor.username AS updated_by_username
            FROM theses t
            LEFT JOIN users creator ON creator.id = t.created_by
            LEFT JOIN users editor ON editor.id = t.updated_by
            WHERE t.id = :id
        ");
        $statement->execute(['id' => $thesisId]);
        $thesis = $statement->fetch();
        if (!$thesis) {
            return null;
        }
        $authorParts = $this->authorParts($thesisId);
        $thesis['author_parts'] = $authorParts; // the separate name parts, for the edit form
        $thesis['authors'] = []; // the full names in order, for the record page, e.g. ['Ana Cruz', 'Ben Reyes']
        foreach ($authorParts as $author) {
            $thesis['authors'][] = $author['full_name'];
        }
        return $thesis;
    }

    // saves a new record from ThesisValidator's cleaned values and returns the new id
    public function createThesis(array $values, int $actorUserId): int {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare("
                INSERT INTO theses (title, publication_year, program, department, campus, adviser, abstract, keywords,
                                    date_submitted, final_defense_date, page_count, is_public, created_by)
                VALUES (:title, :publication_year, :program, :department, :campus, :adviser, :abstract, :keywords,
                        :date_submitted, :final_defense_date, :page_count, :is_public, :created_by)
            ");
            $statement->execute([
                'title' => $values['title'],
                'publication_year' => $values['publication_year'],
                'program' => $values['program'],
                'department' => $values['department'],
                'campus' => $values['campus'],
                'adviser' => $values['adviser'],
                'abstract' => $values['abstract'],
                'keywords' => $values['keywords'],
                'date_submitted' => $values['date_submitted'],
                'final_defense_date' => $values['final_defense_date'],
                'page_count' => $values['page_count'],
                'is_public' => $this->booleanToInteger($values['is_public']),
                'created_by' => $actorUserId,
            ]);
            $thesisId = (int) $this->pdo->lastInsertId(); // ID of the thesis inserted immediately above
            $this->saveAuthors($thesisId, $values['authors']);

            AuditLog::record($this->pdo, $actorUserId, 'thesis.created', 'thesis', $thesisId);
            if ($values['is_public']) {
                AuditLog::record($this->pdo, $actorUserId, 'thesis.published', 'thesis', $thesisId);
            }
            $this->pdo->commit(); // the record, its authors and its history are now saved together
        } catch (\Throwable $exception) {
            $this->pdo->rollBack(); // nothing half-saved is left behind
            throw $exception;
        }
        return $thesisId;
    }

    // saves an edit; returns 'updated', 'no_changes' or 'not_found'
    public function updateThesis(int $thesisId, array $values, int $actorUserId): string {
        $this->pdo->beginTransaction();
        try {
            // FOR UPDATE locks this row until commit, so two people saving at the same moment
            // are handled one after the other instead of mixing their changes
            $statement = $this->pdo->prepare('SELECT * FROM theses WHERE id = :id FOR UPDATE');
            $statement->execute(['id' => $thesisId]);
            $current = $statement->fetch();
            if (!$current) {
                $this->pdo->rollBack();
                return 'not_found';
            }

            // compare every editable field; MySQL returns numbers as text, so compare both sides as text
            $changedFields = [];
            foreach (self::EDITABLE_FIELDS as $field) {
                if ($this->asText($current[$field]) !== $this->asText($values[$field])) {
                    $changedFields[] = $field;
                }
            }
            // the authors count as changed when any name part, the order or the number of authors differs
            $authorsChanged = $this->authorParts($thesisId) !== $values['authors'];
            if ($authorsChanged) {
                $changedFields[] = 'authors';
            }

            if (count($changedFields) === 0) {
                $this->pdo->rollBack(); // nothing to save, so do not touch updated_at or the history
                return 'no_changes';
            }

            $statement = $this->pdo->prepare("
                UPDATE theses
                SET title = :title, publication_year = :publication_year, program = :program, department = :department,
                    campus = :campus, adviser = :adviser, abstract = :abstract, keywords = :keywords,
                    date_submitted = :date_submitted, final_defense_date = :final_defense_date,
                    page_count = :page_count, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $statement->execute([
                'title' => $values['title'],
                'publication_year' => $values['publication_year'],
                'program' => $values['program'],
                'department' => $values['department'],
                'campus' => $values['campus'],
                'adviser' => $values['adviser'],
                'abstract' => $values['abstract'],
                'keywords' => $values['keywords'],
                'date_submitted' => $values['date_submitted'],
                'final_defense_date' => $values['final_defense_date'],
                'page_count' => $values['page_count'],
                'updated_by' => $actorUserId,
                'id' => $thesisId,
            ]);

            // authors are replaced as a whole list: remove the old rows, then insert the new order
            // (this deletes author rows only; the thesis record itself is never deleted)
            if ($authorsChanged) {
                $statement = $this->pdo->prepare('DELETE FROM thesis_authors WHERE thesis_id = :id');
                $statement->execute(['id' => $thesisId]);
                $this->saveAuthors($thesisId, $values['authors']);
            }

            AuditLog::record($this->pdo, $actorUserId, 'thesis.updated', 'thesis', $thesisId, $changedFields);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return 'updated';
    }

    // shows or hides a record on the public page
    // returns 'published', 'unpublished', 'unchanged', 'archived' (restore it first) or 'not_found'
    public function setVisibility(int $thesisId, bool $makePublic, int $actorUserId): string {
        $this->pdo->beginTransaction();
        try {
            $current = $this->lockStatus($thesisId);
            if ($current === null) {
                $this->pdo->rollBack();
                return 'not_found';
            }
            if ($current['archived_at'] !== null) {
                $this->pdo->rollBack();
                return 'archived'; // an archived record stays hidden until it is restored
            }
            if ((int) $current['is_public'] === $this->booleanToInteger($makePublic)) {
                $this->pdo->rollBack();
                return 'unchanged'; // e.g. a double-clicked button or a form submitted twice
            }

            $statement = $this->pdo->prepare("
                UPDATE theses
                SET is_public = :is_public, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $statement->execute(['is_public' => $this->booleanToInteger($makePublic), 'updated_by' => $actorUserId, 'id' => $thesisId]);

            $result = 'unpublished';
            if ($makePublic) {
                $result = 'published';
            }
            AuditLog::record($this->pdo, $actorUserId, 'thesis.' . $result, 'thesis', $thesisId);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return $result;
    }

    // archives (hides everywhere except RDPO's "Archived" filter) or restores a record
    // returns 'archived', 'restored', 'unchanged' or 'not_found'
    // restoring keeps the previous public/unpublished setting, so a published record comes back published
    public function setArchived(int $thesisId, bool $archive, int $actorUserId): string {
        $this->pdo->beginTransaction();
        try {
            $current = $this->lockStatus($thesisId);
            if ($current === null) {
                $this->pdo->rollBack();
                return 'not_found';
            }
            $isArchived = $current['archived_at'] !== null;
            if ($isArchived === $archive) {
                $this->pdo->rollBack();
                return 'unchanged';
            }

            if ($archive) {
                $sql = 'UPDATE theses SET archived_at = CURRENT_TIMESTAMP, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id';
                $result = 'archived';
            } else {
                $sql = 'UPDATE theses SET archived_at = NULL, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id';
                $result = 'restored';
            }
            $statement = $this->pdo->prepare($sql);
            $statement->execute(['updated_by' => $actorUserId, 'id' => $thesisId]);

            AuditLog::record($this->pdo, $actorUserId, 'thesis.' . $result, 'thesis', $thesisId);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return $result;
    }

    // the record as the edit form shows it: one author row per author, empty text for NULL
    public static function formValuesFromRecord(array $thesis): array {
        $formValues = [];
        foreach (self::EDITABLE_FIELDS as $field) {
            $formValues[$field] = '';
            if ($thesis[$field] !== null) {
                $formValues[$field] = (string) $thesis[$field];
            }
        }
        $formValues['authors'] = [];
        foreach ($thesis['author_parts'] as $author) {
            $suffix = '';
            if ($author['suffix'] !== null) {
                $suffix = $author['suffix'];
            }
            $formValues['authors'][] = ['last_name' => $author['last_name'], 'given_names' => $author['given_names'], 'suffix' => $suffix];
        }
        $formValues['is_public'] = (int) $thesis['is_public'] === 1;
        return $formValues;
    }

    // reads and locks only the two status columns used by publish/archive
    private function lockStatus(int $thesisId): ?array {
        $statement = $this->pdo->prepare('SELECT is_public, archived_at FROM theses WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $thesisId]);
        $row = $statement->fetch();
        if (!$row) {
            return null;
        }
        return $row;
    }

    // inserts the authors in the typed order as positions 1, 2, 3, ...
    // each author is saved in parts (for sorting by last name) and as the full name (for search and display)
    private function saveAuthors(int $thesisId, array $authors): void {
        $statement = $this->pdo->prepare("
            INSERT INTO thesis_authors (thesis_id, author_name, given_names, last_name, suffix, author_order)
            VALUES (:thesis_id, :name, :given_names, :last_name, :suffix, :position)
        ");
        foreach ($authors as $index => $author) {
            $position = $index + 1; // store author positions as 1, 2, ... rather than array indexes 0, 1, ...
            $statement->execute([
                'thesis_id' => $thesisId,
                'name' => $author['full_name'],
                'given_names' => $author['given_names'],
                'last_name' => $author['last_name'],
                'suffix' => $author['suffix'],
                'position' => $position,
            ]);
        }
    }

    // the authors in order, in the same shape ThesisValidator returns, so the two can be compared directly:
    // [['given_names' => 'Ana', 'last_name' => 'Cruz', 'suffix' => null, 'full_name' => 'Ana Cruz'], ...]
    private function authorParts(int $thesisId): array {
        $statement = $this->pdo->prepare("
            SELECT given_names, last_name, suffix, author_name AS full_name
            FROM thesis_authors
            WHERE thesis_id = :id
            ORDER BY author_order
        ");
        $statement->execute(['id' => $thesisId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    // NULL stays NULL; numbers and text become text, so 2024 (number) and "2024" (from MySQL) compare as equal
    private function asText($value): ?string {
        if ($value === null) {
            return null;
        }
        return (string) $value;
    }

    private function booleanToInteger(bool $value): int {
        if ($value) {
            return 1;
        }
        return 0;
    }
}
