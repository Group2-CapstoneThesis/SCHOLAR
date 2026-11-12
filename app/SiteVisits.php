<?php
// the purpose of this file is for counting visits to the website (M12, FB-06)
declare(strict_types=1); // keep typed method arguments strict

namespace Scholar;

use PDO;

// a "visit" is one person using the website in general, not each page they open (user's choice, 2026-11-09):
// the first page sets a small cookie that lasts 30 minutes from the LAST page opened; a page opened without that
// cookie starts a new visit. Only the time is stored, nothing about the visitor.
class SiteVisits {
    public const COOKIE_NAME = 'scholar_visit';
    public const VISIT_GAP_SECONDS = 1800; // 30 minutes without opening a page ends a visit

    // pages call this before any HTML; it never stops the page, even if counting fails
    public static function countVisit(PDO $pdo): void {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return; // nothing to count from the terminal, and a cookie can no longer be sent
        }
        $isNewVisit = !isset($_COOKIE[self::COOKIE_NAME]);
        if ($isNewVisit && self::looksAutomated()) {
            return; // search-engine robots and scripts are not people visiting the site
        }
        // set (or refresh) the cookie every time, so the 30 minutes start again from this page
        $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie(self::COOKIE_NAME, '1', [
            'expires' => time() + self::VISIT_GAP_SECONDS,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true, // javascript does not need it
            'samesite' => 'Lax',
        ]);
        if ($isNewVisit) {
            try {
                $pdo->exec('INSERT INTO site_visits (visited_at) VALUES (CURRENT_TIMESTAMP)');
            } catch (\Throwable $exception) {
                // a missing count must never break the page someone is trying to open
            }
        }
    }

    // true for requests that name themselves as robots or scripts, or send no browser name at all
    public static function looksAutomated(): bool {
        $agent = '';
        if (isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])) {
            $agent = $_SERVER['HTTP_USER_AGENT'];
        }
        return self::isAutomatedAgent($agent);
    }

    // the rule itself, separate so the unit test can check it with made-up browser names
    public static function isAutomatedAgent(string $agent): bool {
        if (trim($agent) === '') {
            return true;
        }
        return preg_match('/bot|crawl|spider|slurp|curl|wget|python|java\/|httpclient|libwww|monitor|preview|headless/i', $agent) === 1;
    }
}
