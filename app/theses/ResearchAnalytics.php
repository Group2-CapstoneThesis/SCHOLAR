<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Theses;

use PDO;

// research analytics (M13, FB-05): how many theses (and how many student authors) per year and per department,
// shown as tables, no charts; RDPO used to count these by hand from separate spreadsheets
class ResearchAnalytics {
    // which year a thesis is counted in; the keys are the values the page sends
    public const YEAR_BASES = [
        'thesis' => 'Thesis year',
        'defense' => 'Final defense year',
    ];
    // which records are counted; archived records are never counted
    public const SCOPES = [
        'active' => 'All records',
        'public' => 'Published records only',
    ];
    public const NO_DEPARTMENT = 'No department recorded';

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // reads ?basis=defense&scope=public&from_year=2020&to_year=2025; anything unexpected falls back to the defaults
    // from_year / to_year are '' for "no limit"; years typed the wrong way round are swapped
    public static function filtersFromQuery(array $query): array {
        $filters = ['basis' => 'thesis', 'scope' => 'active', 'from_year' => '', 'to_year' => ''];
        if (isset($query['basis']) && is_string($query['basis']) && isset(self::YEAR_BASES[$query['basis']])) {
            $filters['basis'] = $query['basis'];
        }
        if (isset($query['scope']) && is_string($query['scope']) && isset(self::SCOPES[$query['scope']])) {
            $filters['scope'] = $query['scope'];
        }
        foreach (['from_year', 'to_year'] as $field) {
            // exactly four digits, so "2025 OR 1=1" or an array is ignored
            if (isset($query[$field]) && is_string($query[$field]) && preg_match('/\A[0-9]{4}\z/', $query[$field])) {
                $filters[$field] = $query[$field];
            }
        }
        if ($filters['from_year'] !== '' && $filters['to_year'] !== '' && (int) $filters['from_year'] > (int) $filters['to_year']) {
            $swap = $filters['from_year'];
            $filters['from_year'] = $filters['to_year'];
            $filters['to_year'] = $swap;
        }
        return $filters;
    }

    // true when the filters limit the years (records without a year are then left out, since they fit no range)
    public static function hasYearRange(array $filters): bool {
        return $filters['from_year'] !== '' || $filters['to_year'] !== '';
    }

    // a download name such as "research_totals_by_defense_year_2020-2025_published_only.csv"
    public static function fileName(array $filters): string {
        $name = 'research_totals_by_' . $filters['basis'] . '_year';
        if (self::hasYearRange($filters)) {
            $from = $filters['from_year'];
            if ($from === '') {
                $from = 'start';
            }
            $to = $filters['to_year'];
            if ($to === '') {
                $to = 'latest';
            }
            $name .= '_' . $from . '-' . $to;
        }
        if ($filters['scope'] === 'public') {
            $name .= '_published_only';
        }
        return $name . '.csv';
    }

    // the year as SQL: the year written on the thesis, or the final-defense year with the thesis year as a fallback
    // (the same rule as the Registrar completion list)
    private static function yearSql(string $basis): string {
        if ($basis === 'defense') {
            return 'COALESCE(YEAR(t.final_defense_date), t.publication_year)';
        }
        return 't.publication_year';
    }

    // the whole report:
    // ['years' => [year or null, ...] newest first, 'departments' => [name, ...] A-Z with NO_DEPARTMENT last,
    //  'cells' => [year key][department] => theses, 'by_year' => [year key => ['theses', 'students']],
    //  'by_department' => [department => ['theses', 'students']], 'total_theses', 'total_students']
    // the year key is the year as text, or '' for records without a year
    public function build(array $filters): array {
        $yearSql = self::yearSql($filters['basis']);
        $conditions = ['t.archived_at IS NULL'];
        $values = [];
        if ($filters['scope'] === 'public') {
            $conditions[] = 't.is_public = 1';
        }
        // the year range uses the same year as the tables (thesis year or defense year)
        if ($filters['from_year'] !== '') {
            $conditions[] = $yearSql . ' >= :from_year';
            $values['from_year'] = (int) $filters['from_year'];
        }
        if ($filters['to_year'] !== '') {
            $conditions[] = $yearSql . ' <= :to_year';
            $values['to_year'] = (int) $filters['to_year'];
        }
        // one row per year and department; COUNT(DISTINCT t.id) counts theses, COUNT(a.id) counts their authors
        // (LEFT JOIN keeps a thesis that has no author rows, counted with 0 students)
        $statement = $this->pdo->prepare('
            SELECT ' . $yearSql . ' AS year, t.department,
                   COUNT(DISTINCT t.id) AS theses, COUNT(a.id) AS students
            FROM theses t
            LEFT JOIN thesis_authors a ON a.thesis_id = t.id
            WHERE ' . implode(' AND ', $conditions) . '
            GROUP BY year, t.department
        ');
        $statement->execute($values);

        $cells = [];
        $byYear = [];
        $byDepartment = [];
        $totalTheses = 0;
        $totalStudents = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $yearKey = '';
            if ($row['year'] !== null) {
                $yearKey = (string) $row['year'];
            }
            // an empty or missing department is counted under one "No department recorded" heading
            $department = self::NO_DEPARTMENT;
            if ($row['department'] !== null && trim($row['department']) !== '') {
                $department = $row['department'];
            }
            $theses = (int) $row['theses'];
            $students = (int) $row['students'];

            if (!isset($cells[$yearKey][$department])) {
                $cells[$yearKey][$department] = 0;
            }
            $cells[$yearKey][$department] += $theses;
            if (!isset($byYear[$yearKey])) {
                $byYear[$yearKey] = ['theses' => 0, 'students' => 0];
            }
            $byYear[$yearKey]['theses'] += $theses;
            $byYear[$yearKey]['students'] += $students;
            if (!isset($byDepartment[$department])) {
                $byDepartment[$department] = ['theses' => 0, 'students' => 0];
            }
            $byDepartment[$department]['theses'] += $theses;
            $byDepartment[$department]['students'] += $students;
            $totalTheses += $theses;
            $totalStudents += $students;
        }

        // years newest first, records without a year at the end
        // array_keys() turns keys such as "2025" into numbers, so strval() makes them text again
        $years = array_map('strval', array_keys($byYear));
        usort($years, 'Scholar\Theses\ResearchAnalytics::newerYearFirst');
        // departments A-Z, "No department recorded" at the end
        $departments = array_map('strval', array_keys($byDepartment));
        usort($departments, 'Scholar\Theses\ResearchAnalytics::departmentOrder');

        return [
            'years' => $years, 'departments' => $departments, 'cells' => $cells,
            'by_year' => $byYear, 'by_department' => $byDepartment,
            'total_theses' => $totalTheses, 'total_students' => $totalStudents,
        ];
    }

    // the years that have records, newest first, for the From/To year dropdowns
    // (counted with the chosen year basis, ignoring the year range itself so every year stays selectable)
    public function availableYears(string $basis): array {
        $yearSql = self::yearSql($basis);
        $years = $this->pdo->query("
            SELECT DISTINCT $yearSql AS year FROM theses t
            WHERE t.archived_at IS NULL AND $yearSql IS NOT NULL
            ORDER BY year DESC
        ")->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $years);
    }

    // sort helper: '2026' before '2025', and '' (no year) last
    public static function newerYearFirst($first, $second): int {
        $first = (string) $first;
        $second = (string) $second;
        if ($first === '') {
            return 1;
        }
        if ($second === '') {
            return -1;
        }
        return (int) $second - (int) $first;
    }

    // sort helper: A-Z ignoring upper/lower case, with "No department recorded" last
    public static function departmentOrder($first, $second): int {
        $first = (string) $first;
        $second = (string) $second;
        if ($first === self::NO_DEPARTMENT) {
            return 1;
        }
        if ($second === self::NO_DEPARTMENT) {
            return -1;
        }
        return strcasecmp($first, $second);
    }

    // the number of theses in one year and department (0 when there are none)
    public static function cell(array $report, string $yearKey, string $department): int {
        if (isset($report['cells'][$yearKey][$department])) {
            return $report['cells'][$yearKey][$department];
        }
        return 0;
    }

    // "2025", or "No year recorded" for records without a year
    public static function yearLabel(string $yearKey): string {
        if ($yearKey === '') {
            return 'No year recorded';
        }
        return $yearKey;
    }

    // writes the year-by-department table as CSV for Excel:
    // a heading row (Year, each department, Total), one row per year, then a Total row
    public static function writeCsv(array $report, $handle): void {
        fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 mark so Excel shows letters like "ñ" correctly
        $headings = ['Year'];
        foreach ($report['departments'] as $department) {
            $headings[] = CompletionList::safeCell($department); // a department typed as "=..." stays plain text
        }
        $headings[] = 'Total theses';
        $headings[] = 'Total students';
        fputcsv($handle, $headings, ',', '"', '', "\r\n");
        foreach ($report['years'] as $yearKey) {
            $yearKey = (string) $yearKey;
            $row = [self::yearLabel($yearKey)];
            foreach ($report['departments'] as $department) {
                $row[] = self::cell($report, $yearKey, $department);
            }
            $row[] = $report['by_year'][$yearKey]['theses'];
            $row[] = $report['by_year'][$yearKey]['students'];
            fputcsv($handle, $row, ',', '"', '', "\r\n");
        }
        $totalRow = ['Total'];
        foreach ($report['departments'] as $department) {
            $totalRow[] = $report['by_department'][$department]['theses'];
        }
        $totalRow[] = $report['total_theses'];
        $totalRow[] = $report['total_students'];
        fputcsv($handle, $totalRow, ',', '"', '', "\r\n");
    }
}
