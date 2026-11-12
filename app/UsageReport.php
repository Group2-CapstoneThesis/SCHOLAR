<?php
// the purpose of this file is for counting website visits and staff logins per day, week or month (M12, FB-06)
declare(strict_types=1); // keep typed method arguments strict

namespace Scholar;

use DateTimeImmutable;
use PDO;

// FB-06, provisional meaning chosen by the user on 2026-11-09: count successful logins and website visits,
// shown per day, per week or per month
// visits come from site_visits (see SiteVisits); logins come from the "auth.login" entries of the activity log,
// so logins older than one year disappear when the head administrator deletes old activity entries
class UsageReport {
    // each grouping, with the default range shown when no dates are chosen and the longest range allowed
    public const GROUPINGS = [
        'daily' => ['label' => 'Daily', 'default_back' => 'P13D', 'max_periods' => 366],
        'weekly' => ['label' => 'Weekly', 'default_back' => 'P11W', 'max_periods' => 157],
        'monthly' => ['label' => 'Monthly', 'default_back' => 'P11M', 'max_periods' => 120],
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // reads ?group=weekly&from=2026-09-01&to=2026-11-09 and returns the grouping and the range actually shown:
    // ['group' => 'weekly', 'from' => first day of the first period, 'to' => last day of the last period,
    //  'typed_from' => ..., 'typed_to' => ... (as typed, for the form), 'was_shortened' => true when too long]
    // $today is passed in so the unit test can use a fixed day
    public static function filtersFromQuery(array $query, string $today): array {
        $group = 'daily';
        if (isset($query['group']) && is_string($query['group']) && isset(self::GROUPINGS[$query['group']])) {
            $group = $query['group'];
        }
        $typedFrom = '';
        $typedTo = '';
        if (isset($query['from']) && is_string($query['from']) && ActivityLog::isRealDate($query['from'])) {
            $typedFrom = $query['from'];
        }
        if (isset($query['to']) && is_string($query['to']) && ActivityLog::isRealDate($query['to'])) {
            $typedTo = $query['to'];
        }

        $to = new DateTimeImmutable($today);
        if ($typedTo !== '') {
            $to = new DateTimeImmutable($typedTo);
        }
        // no "from": go back the default amount (two weeks of days, twelve weeks, twelve months)
        $from = $to->sub(new \DateInterval(self::GROUPINGS[$group]['default_back']));
        if ($typedFrom !== '') {
            $from = new DateTimeImmutable($typedFrom);
        }
        if ($from > $to) {
            // dates typed the wrong way round are simply swapped
            $swap = $from;
            $from = $to;
            $to = $swap;
        }

        // widen the range to whole periods: weeks start on Monday, months on the 1st
        $from = self::periodStart($from, $group);
        $to = self::periodEnd($to, $group);

        // a very long range is shortened from the start, so the table stays readable
        $wasShortened = false;
        $periods = self::periodsBetween($from, $to, $group);
        $maxPeriods = self::GROUPINGS[$group]['max_periods'];
        if (count($periods) > $maxPeriods) {
            $from = new DateTimeImmutable($periods[count($periods) - $maxPeriods]['start']);
            $wasShortened = true;
        }
        return [
            'group' => $group, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'),
            'typed_from' => $typedFrom, 'typed_to' => $typedTo, 'was_shortened' => $wasShortened,
        ];
    }

    // the first day of the period that contains $day
    public static function periodStart(DateTimeImmutable $day, string $group): DateTimeImmutable {
        if ($group === 'weekly') {
            // format('N') is 1 for Monday ... 7 for Sunday
            return $day->sub(new \DateInterval('P' . ((int) $day->format('N') - 1) . 'D'));
        }
        if ($group === 'monthly') {
            return $day->modify('first day of this month');
        }
        return $day;
    }

    // the last day of the period that contains $day
    public static function periodEnd(DateTimeImmutable $day, string $group): DateTimeImmutable {
        if ($group === 'weekly') {
            return self::periodStart($day, 'weekly')->add(new \DateInterval('P6D'));
        }
        if ($group === 'monthly') {
            return $day->modify('last day of this month');
        }
        return $day;
    }

    // every period from $from to $to, oldest first: [['start' => 'Y-m-d', 'end' => 'Y-m-d', 'label' => ...], ...]
    public static function periodsBetween(DateTimeImmutable $from, DateTimeImmutable $to, string $group): array {
        $periods = [];
        $start = self::periodStart($from, $group);
        while ($start <= $to) {
            $end = self::periodEnd($start, $group);
            $periods[] = ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'label' => self::periodLabel($start, $end, $group)];
            $start = $end->add(new \DateInterval('P1D'));
        }
        return $periods;
    }

    // "Nov 9, 2026" (daily), "Nov 3 - Nov 9, 2026" (weekly), "November 2026" (monthly)
    public static function periodLabel(DateTimeImmutable $start, DateTimeImmutable $end, string $group): string {
        if ($group === 'monthly') {
            return $start->format('F Y');
        }
        if ($group === 'weekly') {
            if ($start->format('Y') === $end->format('Y')) {
                return $start->format('M j') . ' - ' . $end->format('M j, Y');
            }
            return $start->format('M j, Y') . ' - ' . $end->format('M j, Y');
        }
        return $start->format('D, M j, Y');
    }

    // the SQL that turns a timestamp column into the first day of its period, for GROUP BY
    private static function periodSql(string $column, string $group): string {
        if ($group === 'weekly') {
            // WEEKDAY() is 0 for Monday, so subtracting it gives that week's Monday
            return "DATE_SUB(DATE($column), INTERVAL WEEKDAY($column) DAY)";
        }
        if ($group === 'monthly') {
            return "DATE_FORMAT($column, '%Y-%m-01')";
        }
        return "DATE($column)";
    }

    // the whole report for the filters:
    // ['periods' => [[start, end, label, visits, logins, account_logins => [account id => count]], ...] (newest first),
    //  'accounts' => [[id, username, is_active, logins], ...], 'total_visits' => ..., 'total_logins' => ...]
    public function build(array $filters): array {
        $group = $filters['group'];
        $range = ['from_time' => $filters['from'] . ' 00:00:00', 'to_day' => $filters['to']];
        $periods = [];
        foreach (self::periodsBetween(new DateTimeImmutable($filters['from']), new DateTimeImmutable($filters['to']), $group) as $period) {
            $period['visits'] = 0;
            $period['logins'] = 0;
            $period['account_logins'] = [];
            $periods[$period['start']] = $period;
        }

        // "< the day after 'to'" includes the whole last day
        $statement = $this->pdo->prepare('
            SELECT ' . self::periodSql('visited_at', $group) . ' AS period_start, COUNT(*) AS visits
            FROM site_visits
            WHERE visited_at >= :from_time AND visited_at < DATE_ADD(:to_day, INTERVAL 1 DAY)
            GROUP BY period_start
        ');
        $statement->execute($range);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($periods[$row['period_start']])) {
                $periods[$row['period_start']]['visits'] = (int) $row['visits'];
            }
        }

        $statement = $this->pdo->prepare("
            SELECT " . self::periodSql('created_at', $group) . " AS period_start, actor_user_id, COUNT(*) AS logins
            FROM audit_logs
            WHERE action_code = 'auth.login' AND actor_user_id IS NOT NULL
              AND created_at >= :from_time AND created_at < DATE_ADD(:to_day, INTERVAL 1 DAY)
            GROUP BY period_start, actor_user_id
        ");
        $statement->execute($range);
        $loginsPerAccount = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $accountId = (int) $row['actor_user_id'];
            if (isset($periods[$row['period_start']])) {
                $periods[$row['period_start']]['logins'] += (int) $row['logins'];
                $periods[$row['period_start']]['account_logins'][$accountId] = (int) $row['logins'];
            }
            if (!isset($loginsPerAccount[$accountId])) {
                $loginsPerAccount[$accountId] = 0;
            }
            $loginsPerAccount[$accountId] += (int) $row['logins'];
        }

        // every active account, plus disabled ones that logged in during the range, most logins first
        $accounts = [];
        foreach ($this->pdo->query('SELECT id, username, is_active FROM users ORDER BY username')->fetchAll(PDO::FETCH_ASSOC) as $user) {
            $accountId = (int) $user['id'];
            $logins = 0;
            if (isset($loginsPerAccount[$accountId])) {
                $logins = $loginsPerAccount[$accountId];
            }
            if ((int) $user['is_active'] === 1 || $logins > 0) {
                $accounts[] = ['id' => $accountId, 'username' => $user['username'], 'is_active' => (int) $user['is_active'] === 1, 'logins' => $logins];
            }
        }
        usort($accounts, 'Scholar\UsageReport::moreLoginsFirst');

        $totalVisits = 0;
        $totalLogins = 0;
        foreach ($periods as $period) {
            $totalVisits += $period['visits'];
            $totalLogins += $period['logins'];
        }
        return [
            'periods' => array_reverse(array_values($periods)), // newest first, like the activity log
            'accounts' => $accounts,
            'total_visits' => $totalVisits,
            'total_logins' => $totalLogins,
        ];
    }

    // sort helper: most logins first, then by username
    public static function moreLoginsFirst(array $first, array $second): int {
        if ($first['logins'] !== $second['logins']) {
            return $second['logins'] - $first['logins'];
        }
        return strcmp($first['username'], $second['username']);
    }

    // writes the report as CSV for Excel: one row per period (oldest first), then a column per account's logins
    public static function writeCsv(array $report, $handle): void {
        fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 mark so Excel reads the text correctly
        $headings = ['Period start', 'Period end', 'Website visits', 'Logins (all accounts)'];
        foreach ($report['accounts'] as $account) {
            // usernames may start with "-", which Excel would read as a formula, so they go through safeCell()
            $headings[] = \Scholar\Theses\CompletionList::safeCell('Logins: ' . $account['username']);
        }
        fputcsv($handle, $headings, ',', '"', '', "\r\n");
        foreach (array_reverse($report['periods']) as $period) {
            $row = [$period['start'], $period['end'], $period['visits'], $period['logins']];
            foreach ($report['accounts'] as $account) {
                $logins = 0;
                if (isset($period['account_logins'][$account['id']])) {
                    $logins = $period['account_logins'][$account['id']];
                }
                $row[] = $logins;
            }
            fputcsv($handle, $row, ',', '"', '', "\r\n");
        }
    }
}
