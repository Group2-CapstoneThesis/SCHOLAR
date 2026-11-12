<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Theses;

use PDO;

// the Registrar's completion list (M12, FB-03): every student who completed a thesis, one row per author,
// filtered by year and department and sorted by last name, ready to download as a CSV file for Excel
// the Registrar has no account (P-05); RDPO opens this list and sends the file
class CompletionList {
    // the year a student is counted in: the year of the final defense, or the thesis year when no defense date
    // was recorded (older records often have none); COALESCE() picks the first value that is not NULL
    private const YEAR_EXPRESSION = 'COALESCE(YEAR(t.final_defense_date), t.publication_year)';

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // reads the year and department from the address (?year=2025&department=...)
    // anything unexpected falls back to "all", so a strange link shows a full list instead of an error
    public static function filtersFromQuery(array $query): array {
        $filters = ['year' => '', 'department' => ''];
        if (isset($query['year']) && is_string($query['year']) && preg_match('/\A[0-9]{4}\z/', $query['year'])) {
            $filters['year'] = $query['year'];
        }
        if (isset($query['department']) && is_string($query['department']) && mb_check_encoding($query['department'], 'UTF-8')) {
            $department = trim($query['department']);
            if (mb_strlen($department) <= 150) {
                $filters['department'] = $department;
            }
        }
        return $filters;
    }

    // the students in the list, sorted by last name, then given names
    // archived records are left out; unpublished ones are included, because completion does not depend on
    // whether the record is shown on the public search page
    public function rows(array $filters): array {
        $conditions = ['t.archived_at IS NULL'];
        $values = [];
        if ($filters['year'] !== '') {
            $conditions[] = self::YEAR_EXPRESSION . ' = :year';
            $values['year'] = (int) $filters['year'];
        }
        if ($filters['department'] !== '') {
            $conditions[] = 't.department = :department';
            $values['department'] = $filters['department'];
        }
        $where = implode(' AND ', $conditions);

        // author_name is the last tie-breaker, for old rows whose name parts were never filled in
        $statement = $this->pdo->prepare("
            SELECT a.last_name, a.given_names, a.suffix, a.author_name,
                   t.id AS thesis_id, t.title, t.program, t.department, t.final_defense_date,
                   " . self::YEAR_EXPRESSION . " AS completion_year
            FROM thesis_authors a
            JOIN theses t ON t.id = a.thesis_id
            WHERE $where
            ORDER BY a.last_name, a.given_names, a.suffix, a.author_name, t.title
        ");
        $statement->execute($values);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    // the choices for the two dropdowns: years that have students (newest first) and the recorded departments
    public function filterOptions(): array {
        $years = $this->pdo->query("
            SELECT DISTINCT " . self::YEAR_EXPRESSION . " AS completion_year
            FROM theses t
            WHERE t.archived_at IS NULL AND " . self::YEAR_EXPRESSION . " IS NOT NULL
            ORDER BY completion_year DESC
        ")->fetchAll(PDO::FETCH_COLUMN);
        $departments = $this->pdo->query("
            SELECT DISTINCT t.department FROM theses t
            WHERE t.archived_at IS NULL AND t.department IS NOT NULL
            ORDER BY t.department
        ")->fetchAll(PDO::FETCH_COLUMN);
        return ['years' => $years, 'departments' => $departments];
    }

    // one student's name the way the Registrar wants it: last name, a comma, then the given names, e.g.
    //   "Dela Cruz, Maria Clara S."   or, with a suffix,   "Dela Cruz, Juan Miguel Jr."
    // an old row without name parts shows its whole name as it was saved
    public static function listName(array $row): string {
        if ($row['last_name'] === '') {
            return $row['author_name'];
        }
        $name = $row['last_name'];
        if ($row['given_names'] !== '') {
            $name .= ', ' . $row['given_names'];
        }
        if ($row['suffix'] !== null && $row['suffix'] !== '') {
            $name .= ' ' . $row['suffix'];
        }
        return $name;
    }

    // writes the list as CSV to an open file handle (the download uses php://output, the tests use php://memory)
    // the Registrar asked for names only: one name per line, all in ONE cell, with no heading row or other columns
    // (static: it only formats the rows it is given and never touches the database)
    public static function writeCsv(array $rows, $handle): void {
        // a UTF-8 "byte order mark" first, so Excel shows letters like "ñ" correctly instead of "Ã±"
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            // fputcsv() puts quotes around "Dela Cruz, Maria Clara S." because of its comma,
            // which is what keeps the whole name in one cell instead of splitting it into two;
            // "\r\n" line endings are what Excel on Windows expects
            fputcsv($handle, [self::safeCell(self::listName($row))], ',', '"', '', "\r\n");
        }
    }

    // Excel runs a cell that starts with =, +, -, @ (or a tab / carriage return) as a formula,
    // so a name typed as "=HYPERLINK(...)" could do something when the file is opened ("CSV injection");
    // a leading apostrophe makes Excel show it as plain text instead
    public static function safeCell(string $value): string {
        if ($value !== '' && strpos("=+-@\t\r", $value[0]) !== false) {
            return "'" . $value;
        }
        return $value;
    }

    // a download name such as "completion_list_2025_college_of_computer_studies.csv"
    // only letters, digits and underscores are kept, so the name is always safe in the download header
    public static function fileName(array $filters): string {
        $name = 'completion_list';
        if ($filters['year'] !== '') {
            $name .= '_' . $filters['year'];
        } else {
            $name .= '_all_years';
        }
        if ($filters['department'] !== '') {
            $department = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $filters['department']));
            $department = trim(substr($department, 0, 60), '_');
            if ($department !== '') {
                $name .= '_' . $department;
            }
        }
        return $name . '.csv';
    }
}
