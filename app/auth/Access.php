<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Auth;

use PDO;

// central access control that decides which role may perform each action
// public pages (search and thesis details) never call this; every RDPO-only page and action does
class Access {
    // which actions each role may perform; anything not listed here is denied
    // only the RDPO administrator role is needed right now, so permissions are kept in a simple array
    private const ROLE_PERMISSIONS = [
        'rdpo_admin' => [
            'thesis.view_all',  // see every record, including unpublished and archived ones
            'thesis.create',
            'thesis.edit',
            'thesis.publish',   // show or hide a record on the public search page
            'thesis.archive',   // archive or restore a record (no permanent deletion)
            'thesis.upload_pdf', // attach or replace the manuscript PDF
            'thesis.view_pdf',  // open the manuscript in the secure viewer
        ],
    ];

    // returns true only when the role exists in the list AND that role has the permission
    // an unknown role or a misspelled permission returns false, so mistakes fail closed (default deny)
    public static function roleAllows(string $roleCode, string $permission): bool {
        if (!isset(self::ROLE_PERMISSIONS[$roleCode])) {
            return false;
        }
        return in_array($permission, self::ROLE_PERMISSIONS[$roleCode], true);
    }

    // protected pages call this right after Session::start()
    // 1. requireAuthentication() sends visitors who are not logged in (or are disabled) to the login page
    // 2. then the role is checked; a logged-in account without the permission gets a plain 403 page
    // it returns the logged-in account so the page knows who is acting (for created_by and the audit log)
    public static function requirePermission(PDO $pdo, string $permission): array {
        $account = Session::requireAuthentication($pdo);
        if (!self::roleAllows($account['role_code'], $permission)) {
            http_response_code(403); // forbidden: logged in, but not allowed to do this
            header('Content-Type: text/plain; charset=utf-8');
            echo 'You do not have permission to open this page.';
            exit;
        }
        return $account;
    }
}
