<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Auth;

use PDO;
use Scholar\SiteVisits;

// the one central place that decides which role may do which action (M4)
// public pages (search and thesis details) never call this; every RDPO-only page and action does
class Access {
    // what every RDPO staff member may do with thesis records and reports
    private const STAFF_PERMISSIONS = [
        'thesis.view_all',  // see every record, including unpublished and archived ones
        'thesis.create',
        'thesis.edit',
        'thesis.publish',   // show or hide a record on the public search page
        'thesis.archive',   // archive or restore a record (no permanent deletion)
        'thesis.upload_pdf', // attach or replace the manuscript PDF
        'thesis.view_pdf',  // open the manuscript in the secure viewer
        'report.completion_list', // view and download the Registrar's completion list (M12, FB-03)
        'report.analytics', // research totals by year and department, with a CSV download (M13, FB-05)
    ];

    // which actions each role may perform; anything not listed here is denied
    // the roles are still few, so a small list in code is enough instead of separate
    // permissions/role_permissions tables
    // rdpo_head (M12, FB-07) is PROVISIONAL: RDPO has not yet confirmed that a head-administrator level exists.
    // it can do everything an RDPO administrator can, plus manage the staff accounts
    // "...self::STAFF_PERMISSIONS" copies the list above into each role, so both roles stay in step
    private const ROLE_PERMISSIONS = [
        'rdpo_admin' => [...self::STAFF_PERMISSIONS],
        'rdpo_head' => [
            ...self::STAFF_PERMISSIONS,
            'account.manage', // create staff accounts, change roles and usernames, reset passwords, disable/enable
            'audit.view',     // read the whole activity log (every audit entry)
            'audit.delete_old', // delete activity entries older than one year (FB-07); the deletion itself is logged
            'backup.manage',  // make and download backups (FB-04); restoring is a terminal command only
            'report.usage',   // website visits and staff logins per day, week or month, with a CSV export (FB-06)
        ],
    ];

    // the permissions the head administrator may switch off and on for ONE account (user's request, 2026-11-09),
    // with the words shown beside each switch on the account page; the rest cannot be switched:
    // - thesis.view_all: every staff account can see the records (the records page is where everyone lands after login)
    // - account.manage, audit.view, audit.delete_old, backup.manage, report.usage: come with the head administrator role; change the role instead
    public const SWITCHABLE_PERMISSIONS = [
        'thesis.create' => ['label' => 'Add new records', 'help' => 'Open the New record form and use autofill.'],
        'thesis.edit' => ['label' => 'Edit records', 'help' => 'Change the details of an existing record.'],
        'thesis.publish' => ['label' => 'Publish and unpublish', 'help' => 'Show or hide a record on the public search page.'],
        'thesis.archive' => ['label' => 'Archive and restore', 'help' => 'Hide a record everywhere, or bring it back.'],
        'thesis.upload_pdf' => ['label' => 'Upload manuscript PDFs', 'help' => 'Attach or replace the PDF of a record.'],
        'thesis.view_pdf' => ['label' => 'Open manuscript PDFs', 'help' => 'Read a record\'s PDF in the secure viewer.'],
        'report.completion_list' => ['label' => 'Registrar completion list', 'help' => 'View and download the list of students who completed a thesis.'],
        'report.analytics' => ['label' => 'Research analytics', 'help' => 'View and download the totals of theses per year and per department.'],
    ];

    // true only when the ROLE has the permission; an unknown role or a misspelled permission returns false,
    // so mistakes fail closed (default deny)
    // pages should use allows() instead, which also respects the permissions switched off for one account
    public static function roleAllows(string $roleCode, string $permission): bool {
        if (!isset(self::ROLE_PERMISSIONS[$roleCode])) {
            return false;
        }
        return in_array($permission, self::ROLE_PERMISSIONS[$roleCode], true);
    }

    // true when this signed-in account may do it: its role must allow it, AND the head administrator must not have
    // switched it off for this account; $account is the array returned by Session::requireAuthentication()
    public static function allows(array $account, string $permission): bool {
        if (!self::roleAllows($account['role_code'], $permission)) {
            return false;
        }
        return !in_array($permission, $account['revoked_permissions'], true);
    }

    // the role codes a staff account may be given on the account pages: exactly the roles listed above,
    // so a role row in the database that this file does not know about can never be assigned
    public static function staffRoleCodes(): array {
        return array_keys(self::ROLE_PERMISSIONS);
    }

    // protected pages call this right after Session::start()
    // 1. requireAuthentication() sends visitors who are not logged in (or are disabled) to the login page
    // 2. an account that still has a temporary password is sent to choose its own first
    //    a head administrator without an authenticator app is then sent to link one
    // 3. then the permission is checked; a logged-in account without it gets a plain 403 page
    // it returns the logged-in account so the page knows who is acting (for created_by and the audit log)
    public static function requirePermission(PDO $pdo, string $permission): array {
        $account = Session::requireAuthentication($pdo);
        SiteVisits::countVisit($pdo); // staff use counts toward website visits like everyone else's (M12, FB-06)
        if ($account['must_change_password']) {
            // 303 = "see other": go to the password page instead; nothing on this page runs first
            header('Location: admin_password.php', true, 303);
            exit;
        }
        // a head administrator links an authenticator app before anything else (user's request, 2026-11-10)
        if ($account['must_set_up_two_factor']) {
            header('Location: admin_two_factor.php', true, 303);
            exit;
        }
        if (!self::allows($account, $permission)) {
            http_response_code(403); // forbidden: logged in, but not allowed to do this
            header('Content-Type: text/plain; charset=utf-8');
            echo 'You do not have permission to open this page.';
            exit;
        }
        return $account;
    }
}
