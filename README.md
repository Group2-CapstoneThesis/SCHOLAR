# S.C.H.O.L.A.R. local development

Active project: `C:/laragon/www/proj scholar/scholar`. Development moved from XAMPP to Laragon on 2026-10-10. The old XAMPP checkout and database were left untouched.

Implemented: M0 baseline, M1 skeleton, M2 provisional database, M3 login (username and password login, logout, session timers, disabled accounts and failed-login limits), M4 central access check, M5 audit log, M6 RDPO thesis records, M7 private PDF upload with an RDPO-only secure viewer, M8 RDPO search, M9 public search pages (provisional fields) and M10 autofill from the thesis PDF. M11 and later are absent. The working milestone plan was revised on 2026-10-19 after the RDPO meeting; see `../for_codex/requirements.md`. See [baseline](docs/baseline.md), [schema](docs/schema.md), [verification](docs/verification.md), and [PHP reading notes](docs/php_reading_notes.md).

## Open the application

Use Laragon's existing Apache and MySQL. Open **http://scholar.localhost/** in your browser. The application virtual host serves only `scholar/public/`.

A local browser-test administrator was generated for this installation. Its username is `local_admin`, and its random password is in the private, Git-ignored `storage/local-browser-login.txt`. Open that file locally to get the password; do not include it in reports or commits. Login uses the username (accounts have no email since 2026-11-10). The test scripts create and remove separate temporary accounts; they preserve this browser-test account.

If your browser cannot resolve `scholar.localhost`, the verified folder URL is `http://127.0.0.1/proj%20scholar/scholar/public/`. PHP's test client resolves the virtual-host name internally, without a hosts-file edit. Windows command-line DNS resolution did not resolve the name during setup; browser resolution can differ. No hosts file was changed.

Bootstrap **5.3.3 CSS** loads through jsDelivr with integrity checking. Internet is needed for styling. Login remains functional if CSS cannot load; no Bootstrap JavaScript is needed.

## One PHP version

Use **PHP 8.5** for Apache, terminal commands, Composer, and eventual deployment. The inspected installation is **8.5.12**, at `C:/laragon/bin/php/php 8.5.12/php.exe`. Both HTTP and CLI checks confirmed that version. Composer requires `8.5.*`; its platform setting is `8.5.12` so dependency resolution uses the same baseline. The bootstrap refuses other PHP minor versions. Security patch updates within 8.5 remain allowed, but update the platform baseline and rerun checks when changing it.

Do not switch this project to Laragon's separate PHP 8.3 installation or XAMPP PHP. There is no need to delete installations that other projects may use. Bare `php` currently resolves to `C:/php/php.exe`, which reports 8.5.10 and is not used by this project, but the explicit Laragon path below guarantees the intended runtime and php.ini.

Start each PowerShell session with:

```powershell
Set-Location 'C:\laragon\www\proj scholar\scholar'
$phpExe = 'C:\laragon\bin\php\php 8.5.12\php.exe'
& $phpExe -v
```

## Composer and class loading

```powershell
& $phpExe C:\ProgramData\ComposerSetup\bin\composer.phar install --no-interaction --prefer-dist
& $phpExe C:\ProgramData\ComposerSetup\bin\composer.phar validate --strict
& $phpExe C:\ProgramData\ComposerSetup\bin\composer.phar check-platform-reqs
```

Commit `composer.json` and `composer.lock`; leave `vendor/` ignored. `install` reproduces the locked libraries. `update` deliberately changes dependency versions. The Laragon transition kept the same library versions, including Phinx 0.16.12 and phpdotenv 5.7.0; Composer regenerated autoload files for the new checkout. Do not edit third-party code manually.

Composer loads `Scholar` and `Scholar\Auth` classes from `app/`. Each actual page retains its request handling followed by complete HTML, following your preferred page style. Shared safeguards remain in application classes.

## Apache configuration

The reviewed [deploy/laragon-vhosts.conf](deploy/laragon-vhosts.conf) was copied, with your approval, to `C:/laragon/etc/apache2/sites-enabled/scholar.conf`. Laragon includes that directory already. Its default `00-default.conf` was preserved. Only the application host's document root is `C:/laragon/www/proj scholar/scholar/public`.

```powershell
$apacheExe = 'C:\laragon\bin\apache\httpd-2.4.68-260617-Win64-VS18\bin\httpd.exe'
& $apacheExe -t
& $apacheExe -S
```

Use Laragon's Apache Reload/Restart control after future server configuration edits. This installation runs Apache as a console process, not an installed Apache2.4 Windows service, so `httpd -k restart` alone targets a nonexistent service. Setup signalled the verified parent's native Apache graceful-restart event and confirmed the reload in Apache's log.

The repository-root `.htaccess` denies access to private code and references through the default localhost root. `public/.htaccess` allows local requests only and disables directory listing. Preserve both files. This is local development, not public deployment.

## Database and .env

Inspected Laragon services: Apache 2.4.68 on HTTP port 80, PHP 8.5.12, and **MySQL 8.4.3** on `127.0.0.1:3306`, using `C:/laragon/data/mysql-8.4/`.

The new server initially contained no scholar database. Setup created only `scholar_dev` and a randomly named account with data/migration privileges scoped to that database. The application does not connect as root. Ignored `.env` now contains the Laragon credentials; the previous copied `.env` was backed up in private `storage/backups/`. Copied session files were archived there to prevent old authenticated sessions from carrying into the new database.

phpdotenv loads `.env` outside `public/`. Existing process environment settings take precedence. `.env.example` is a non-secret template. Keep `DB_PASSWORD` present even if a manually configured local account intentionally has a blank password. The copied `config/local.php` is an unused private backup; the application does not read it.

This installation is already initialized. Do not run `init_database.php` again to reset it. That helper is only for a genuinely fresh local installation at the inspected endpoint, without existing `.env`, legacy private config, or `scholar_dev`; supply inspected administrator credentials temporarily. It refuses to overwrite these. Do not delete configuration or databases to bypass those checks.

```powershell
& $phpExe bin/console.php check-db
& $phpExe bin/console.php migration-status
& $phpExe bin/console.php migrate
& $phpExe bin/console.php seed
```

All four existing migrations were applied normally to the new MySQL database. The original SQL payloads remain unchanged. Seeding uses only fictional data, including one thesis with two authors. Existing XAMPP accounts/data were not imported or modified.

For future changes, create and review a new migration:

```powershell
& $phpExe vendor/bin/phinx create DescriptiveChange -c phinx.php
```

Never rewrite applied SQL migrations or drop populated databases. The four foundation migrations refuse destructive rollback. `adopt-legacy` remains a historical MariaDB-baseline tool, not a Laragon setup command. Do not use it to import or fake a MySQL schema.

## Provision another local administrator

The CLI never replaces an existing account. Choose a unique username: `provision-admin <username>` (it no longer takes an email). Usernames accept 3-64 letters, digits, underscores, dots, or hyphens. Passwords must be 12-72 bytes, kept untrimmed; the maximum avoids bcrypt truncation. There is no shared/default application password or account-reset screen.

```powershell
$securePassword = Read-Host 'New local administrator password' -AsSecureString
$passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
try {
    $env:SCHOLAR_ADMIN_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    & $phpExe bin/console.php provision-admin my_admin
} finally {
    Remove-Item Env:SCHOLAR_ADMIN_PASSWORD -ErrorAction SilentlyContinue
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    $securePassword = $null
}
```

The environment briefly holds the password on your trusted local machine; it is not passed on the command line or committed. The terminal command makes an ordinary RDPO administrator. Since M12, the head administrator can also create staff accounts on the **Staff accounts** page (see "Staff accounts and the head administrator" below), and `set-role` changes an account's role from the terminal.

## Run tests

```powershell
Get-ChildItem app,bin,database,public,tests -Recurse -Filter *.php | ForEach-Object { & $phpExe -l $_.FullName }
& $phpExe -l phinx.php
& $phpExe tests/unit_testing.php
& $phpExe tests/verify_user.php http://scholar.localhost
& $phpExe tests/verify_attacker.php http://scholar.localhost
```

`unit_testing.php` runs five isolated cases against the application's actual `escapeHtml()` function: script text, quotation marks, ordinary text, empty text, and Unicode text. It uses fixed inputs and exact expected results, with Arrange/Act/Assert steps and separate PASS/FAIL output for UNIT-01 through UNIT-05. It loads bootstrap without calling configuration loading or opening a database connection, and makes no HTTP requests or accounts. Apache and MySQL do not need to be running for these cases. This checks the helper's returned text, not JavaScript execution in a browser. Exit code 0 means all five passed; 1 means at least one failed.

The former `verify.php` is now two independent scripts. Each prints `STEPS 1: ...`, `STEPS 2: ...`, and so on before its actions, followed by PASS/FAIL checks. PREPARATION and CLEANUP messages distinguish test fixtures from actor actions. Both simulate HTTP requests; they do not open a graphical browser or assess visual accessibility.

- `verify_user.php`: ordinary login/logout journeys using the username (an email address is refused), a mistaken-password attempt, protected-page access, session regeneration, and cookie safeguards. Backend preparation also checks migration history, the synthetic thesis's ordered authors, real CLI provisioning, and password hashing.
- `verify_attacker.php`: unauthenticated access, omitted CSRF token, malformed inputs, known/unknown credential errors, SQL injection, script-markup escaping, overlong password, GET/invalid-CSRF logout, logged-out session replay, and private/reference-file access. Legitimate synthetic logins prepare the authenticated-session attack scenarios; these are not bypasses. A separately labelled backend integrity check attempts an orphan author using the test database connection, not an HTTP attacker connection.

Run either independently, in either order. Each generates a distinct synthetic account and removes only that exact account in finally, including on failure. The permanent local browser-test account is preserved. Neither script prints passwords or raw session cookies. Exit code 0 means success and 1 means failure. Default-root privacy URLs derive from the checkout folder name. These checks cover specific development scenarios, not a comprehensive penetration test. `database_foundation.php` remains separate and unchanged by the split.

The foundation test creates and removes only a random `scholar_verify_*` database. It checks configuration validation/precedence, clean and repeated migrations/seeding, and a database-engine-specific schema snapshot. `legacy_schema.json` retains the MariaDB baseline; `mysql_schema.json` records the inspected MySQL 8.4 structure. DDL formatting can vary across engine versions; inspect differences rather than replacing a snapshot to hide a schema change.

For this inspected Laragon installation, initialization logs evidenced root with an empty password and the connection succeeded. Supply those values only temporarily for the disposable-database test; inspect credentials again if your installation changes:

```powershell
$env:SCHOLAR_DB_ADMIN_USER = 'root'
$env:SCHOLAR_DB_ADMIN_PASSWORD = ''
try { & $phpExe tests/database_foundation.php }
finally { Remove-Item Env:SCHOLAR_DB_ADMIN_USER,Env:SCHOLAR_DB_ADMIN_PASSWORD -ErrorAction SilentlyContinue }
```

Browser appearance, mobile layout, keyboard interaction, and CDN behaviour need separate manual checks.

## Git and private files

The copied `.git` retained branch `master` and its full history. No absolute worktree path or remote needed rewriting. Commit `74c5c0f` saves the user edits present before the Laragon transition. Git commands should now run from `C:/laragon/www/proj scholar`; no remote is currently configured.

Credentials, generated local login details, sessions, logs, backups, dependencies and supplied references are ignored. Check `git status` before staging. Git commits do not migrate or roll back database contents.

Unexpected failures show a generic message and reference. Private logs record only type, filename, line, and reference, not exception messages, passwords, request input, or session values. Runtime storage must be writable by your CLI and Laragon Apache user.

Account management screens, password recovery, MFA, M4 permissions, and later thesis/repository features remain deferred. Disabled accounts and failed-login limits were added on 2026-10-19 (see the last section).

## Session policies added on 2026-10-12

The user authorized inactivity expiry, maximum authenticated lifetime, periodic identifier renewal, and expiry feedback. These are interim engineering decisions, not closure of institutional policy P-12. Current local .env and .env.example settings are:

```dotenv
SESSION_IDLE_SECONDS=1800
SESSION_MAXIMUM_SECONDS=28800
SESSION_RENEWAL_SECONDS=900
```

Values are seconds: 30 minutes without an authenticated request, 8 hours since login regardless of activity, and identifier renewal after 15 minutes on the next authenticated request. Positive integer settings are bounded to seven days; inactivity cannot exceed maximum lifetime and renewal must be shorter than maximum lifetime. Missing settings use these defaults; invalid settings fail safely. Edit ignored .env to change them. Apache does not need reconfiguration or restart for these application settings.

Expiry is enforced when a request reaches the server, not by a browser countdown or background process. Typing/mouse movement does not extend the session unless a request is made. Activity updates do not change the original login time. Existing authenticated sessions without the new timestamps require login again. The login page displays a fixed, escaped expiry message. Logout and expiry remove the identity and server session and expire the browser cookie. HTTP local development retains HttpOnly/SameSite=Lax; Secure is enabled when requests use HTTPS.

Periodic renewal saves an anonymous retired marker in the old session file and keeps authentication only in the replacement identifier. The old identifier cannot access protected pages, cannot obtain the new identifier, and does not expire the replacement cookie during a delayed request. Form tokens stay valid across periodic renewal. Retired files are later collected by PHP housekeeping; housekeeping is not the authentication timeout mechanism. Simultaneous requests or unreliable networks can still make an old request return to login; this small local implementation does not promise seamless recovery across devices/networks. See the [PHP renewal guidance](https://www.php.net/manual/en/function.session-regenerate-id.php).

Run the added tests from the scholar folder using the same Laragon executable:

```powershell
& $phpExe tests/unit_sessions.php
& $phpExe tests/verify_sessions.php http://scholar.localhost
```

unit_sessions.php uses fixed times and no sessions/database/network. verify_sessions.php uses a fictional temporary account, adjusts only its own session timestamps through a private child PHP command, and sends requests through existing Laragon Apache. No test-only public endpoint, shortened production timer, or real account is used. Evidence is stored under ignored storage/test-results/.

This completes this session-policy slice only. M3 remains partial: disabled-account handling, account management, password recovery, MFA and other authentication features remain deferred. Remote logout/session listings, cross-device restrictions and a remember-me feature are not implemented.

## Disabled accounts and failed-login limits (added 2026-10-19)

The user authorized completing M3 on 2026-10-19. Two new migrations add `users.is_active`/`users.disabled_at` and a `failed_logins` table. Run `bin/console.php migrate` on any older checkout; both were applied to this installation's `scholar_dev`.

**Disabling an account.** Until the RDPO account-management page exists (M12), use the terminal, with the account's username:

```powershell
& $phpExe bin/console.php disable-account my_admin
& $phpExe bin/console.php enable-account my_admin
```

A disabled account is not deleted. Its login attempts get the same generic "Invalid username or password." message as a wrong password. An already open session ends on its next request, because every protected page now rereads the account's status, and the login page then shows a fixed "account is no longer active" note. Re-enabling restores login with the existing password. There is no safeguard yet against disabling the last active RDPO account; the terminal can always re-enable it.

**Failed-login limits.** Defaults, configurable in `.env`:

```dotenv
LOGIN_ACCOUNT_MAX_FAILURES=5
LOGIN_ADDRESS_MAX_FAILURES=20
LOGIN_FAILURE_WINDOW_SECONDS=900
```

After 5 failed attempts for one username, or 20 from one IP address, within 15 minutes, login answers "Too many login attempts" (HTTP 429). The password is not checked while blocked, so even the correct password is refused. Blocked attempts are not recorded, so the block lifts once the older failures are 15 minutes old. Unknown usernames are counted exactly like real ones, so the limit does not reveal which accounts exist. A successful login clears that account's counter. The address limit must be at least the account limit; invalid values stop the application with a private log reference. These are interim engineering defaults, not RDPO-approved policy (P-12).

The table stores SHA-256 fingerprints of the typed identifier and IP address, not readable text. A fingerprint is not encryption. Known trade-offs: someone who knows an RDPO username can deliberately trigger a temporary 15-minute lockout of that account, and simultaneous requests can slip a few extra attempts past the count. This is acceptable for a small local system; revisit it if the login becomes internet-reachable (P-07).

`bin/console.php migration-status` now prints the table when migrations are pending, instead of reporting a failure.

Run the two added suites with the same Laragon executable:

```powershell
& $phpExe tests/verify_account_status.php http://scholar.localhost
& $phpExe tests/verify_login_throttle.php http://scholar.localhost
```

`verify_account_status.php` disables and re-enables a fictional temporary account through the real terminal commands and checks the open-session sign-out, old-cookie replay, generic error and repeat/unknown cases. `verify_login_throttle.php` first tests the limiter class with fixed past times and reserved 192.0.2.x documentation addresses, then checks the real login page over HTTP, including a blocked correct password and an unknown username. All suites now remove the failed-login rows their deliberate wrong passwords create, so repeated runs do not lock out 127.0.0.1.

M3's acceptance criteria are met. Account management screens, password recovery and MFA remain deferred under the revised plan.

## Thesis records and public search (added 2026-10-19)

The user asked on 2026-10-19 for a simple SSRN-like public search plus RDPO record management. The supplied pre-meeting schema (`scholar database schema.sql`) was used as a reference only; workflow, notification, email-verification and permission tables were left out because the 2026-10-18 meeting removed them or one role does not need them.

**Pages** (all under `public/`, one file per page):

| Page | Who | What it does |
| --- | --- | --- |
| `index.php` | Anyone, no login | SSRN-style search: words, year, program, sort, 10 results per page. Published, non-archived records only. Sets no cookie. |
| `thesis.php?id=N` | Anyone, no login | One published record: title, authors, year, program, abstract, keywords, final defense date, page count. Hidden and missing records both answer 404. |
| `admin.php` | RDPO | Record list with the same search plus a status filter (active, published, unpublished, archived, all). |
| `admin_thesis_form.php` | RDPO | Create (no id) or edit (`?id=N`). Server-side validation, CSRF, retained input after errors. |
| `admin_thesis.php?id=N` | RDPO | All fields including adviser and date submitted, Publish/Unpublish, Archive/Restore, and the record's history. |

The site root (`index.php`) is now the public search instead of a redirect to the login page; "RDPO login" is in its top bar. After login RDPO lands on `admin.php`.

**Fields** follow the Chapter III list: title, authors (separate ordered rows), year, program/course, adviser, abstract, keywords, date submitted, date of final defense, number of pages. They are provisional until RDPO sends its Excel column headers (P-08). The public field list lives in one place, `app/theses/PublicCatalog.php`; adviser, date submitted, editor names and status columns are never selected by public pages. Which fields may be public still needs RDPO confirmation (P-03).

**No permanent deletion.** "Archive" hides a record everywhere except RDPO's Archived filter and can be undone; restoring keeps its earlier published/unpublished setting. New records start unpublished unless "Show on the public search page right away" is ticked.

**Access (M4).** `Scholar\Auth\Access` holds one role-to-permission list in code (`rdpo_admin`: `thesis.view_all`, `thesis.create`, `thesis.edit`, `thesis.publish`, `thesis.archive`). Unknown roles or permissions are denied. Every RDPO page calls `Access::requirePermission()`; each Publish/Archive action is checked against its own permission.

**Audit log (M5).** `audit_logs` records logins, logouts, account disable/enable (actor shown as "terminal") and every record create/edit/publish/unpublish/archive/restore. Edits store only the names of changed fields, never values. Each record change and its audit row are saved in one transaction.

**Search.** Every typed word must appear in the title, abstract, keywords or an author name. `%` and `_` are searched literally. Sorting uses a fixed allowlist. Search uses ordinary `LIKE`, which is fine for hundreds of records; semantic search is a later milestone.

Two migrations add the thesis columns and `audit_logs`. A new seed, `SyntheticCatalog`, adds 12 fictional records (11 published, 1 unpublished) so the search has something to show. Run on an older checkout:

```powershell
& $phpExe bin/console.php migrate
& $phpExe bin/console.php seed
```

Composer now maps `Scholar\Theses` to `app/theses/`. After pulling changes that add classes, regenerate the autoloader:

```powershell
& $phpExe C:\ProgramData\ComposerSetup\bin\composer.phar dump-autoload -o --strict-psr
```

**Tests** added:

```powershell
& $phpExe tests/unit_thesis_rules.php
& $phpExe tests/verify_thesis_records.php http://scholar.localhost
& $phpExe tests/verify_public_search.php http://scholar.localhost
```

`unit_thesis_rules.php` (no database) checks the permission list, every form rule and the search-filter fallbacks. `verify_thesis_records.php` runs the full RDPO journey over HTTP, including a made-up role with no permissions (403), unpublished/archived records hidden from the public, escaping, and the audit trail; it removes everything it created. `verify_public_search.php` only reads, comparing the public totals and filters against the database. The older suites now also remove the audit rows their temporary accounts create, and look for "RDPO thesis records" instead of the old "Login succeeded." text.

Not done yet: Excel import (M11), account management screens (M12), analytics (M13), semantic search/chatbot (M14-M15), and a local Bootstrap copy for offline use. Edits are last-save-wins with a row lock; there is no warning when two people edit the same record at once.

## Manuscript PDFs and the secure viewer (added 2026-10-19)

The user asked to include the secure PDF viewer for now and remove it later if RDPO finds it unnecessary.

**Uploading.** On a record page, the "Manuscript PDF" card has an upload form (RDPO only). The server checks that exactly one file arrived as a genuine upload, that its name ends in `.pdf`, that it is not empty or over the limit, and that its first bytes and PHP's file-type detector both say PDF. A text or program file renamed to `.pdf` is refused. Accepted files are saved in `storage/theses/` under a random 32-character name; the uploaded name is only stored as a label. Uploading again marks the previous PDF as replaced but keeps it on disk (no deletion until P-11). Each upload is written to the audit history.

**Size limit**, provisional, in `.env` (PHP's own limits on this installation are 2 GB, so they are not the bottleneck):

```dotenv
THESIS_PDF_MAX_MB=50
```

**Viewing.** "Open in secure viewer" (`admin_thesis_viewer.php`) shows the PDF inside the RDPO page using the browser's built-in PDF viewer. The bytes come only from `admin_thesis_pdf.php`, which checks the login and the `thesis.view_pdf` permission on every request, sends `Cache-Control: no-store`, forbids other sites from framing it, never reveals the storage name, and records each opening in the audit history. Apache cannot serve `storage/theses` directly through either address.

What "secure" does **not** mean: anyone allowed to open the PDF can still save, print or screenshot it from the browser. Preventing that would need DRM, which is outside this project. Public pages never link to or mention PDFs.

`thesis_files` is a new table (migration `20261019000500_create_thesis_files`). Run `bin/console.php migrate` on an older checkout.

**Tests** added:

```powershell
& $phpExe tests/unit_pdf_rules.php
& $phpExe tests/verify_thesis_files.php http://scholar.localhost
```

`unit_pdf_rules.php` checks the file rules against small generated files (real PDF, renamed text/program files, empty and oversized files, name cleaning) without a server or MySQL. `verify_thesis_files.php` covers anonymous and no-permission refusals, rejected uploads, a folder-climbing file name, exact bytes and headers from the viewer, direct-URL refusal, the public page, and replacement. It deletes its own stored files and rows.

Known limits: every load of the PDF address adds a "PDF opened" history entry, so reloading the viewer can repeat it. There is no virus scanning and no check that stored files still match their recorded fingerprint, apart from a size check before each serve.

## Autofill from the thesis PDF (added 2026-10-22)

On **New record**, the "Fill in from the thesis PDF" box reads the chosen PDF on this server and fills the form for RDPO to check. Fields filled from the PDF are tinted. The PDF then waits privately in `storage/theses/pending/` and is attached when the record is saved, unless "Attach … to this record" is unticked. Choosing another PDF replaces the waiting one; waiting files older than a day are removed automatically.

It follows the WCC page order seen in four real reference theses supplied by the user (kept privately in ignored `for_codex/reference_theses/`, never used as test data):

| Field | Where it is read |
| --- | --- |
| Title | Title page lines before "A Thesis Presented to" (fallback: the quoted title on the approval sheet) |
| Program | Title page line after "...the Degree of" (kept as printed, e.g. "Bachelor of Science in Information Technology") |
| Authors | Title page lines after "by", one per line, kept as printed ("Surname, Given M."); since 2026-11-08 the form splits each into its last-name and given-names boxes |
| Year | Title page month/year line ("August, 2024", "July 2024") |
| Adviser | Approval sheet: the line just above "Adviser" |
| Date of final defense | Approval sheet: "Date of Final Defense: December 11, 2024" |
| Abstract | From the "Abstract" heading to "Keywords:", across pages |
| Keywords | After "Keywords:", split on commas (a final "and" becomes a comma) |
| Number of pages | The PDF's total page count |

The date submitted is never guessed, because the title page only gives a month and year. The vertical "COLLEGE OF INFORMATION TECHNOLOGY" banner, the running "WORLD CITI COLLEGES" header and page numbers are removed. Only the first ten pages are read (raised from six on 2026-10-22 for the new template, where a long acknowledgement and a dedication come before the abstract). On the four references, all nine fields came out correctly in under 0.1 s each.

**Library.** Reading PDF text uses `smalot/pdfparser` 2.13 (pure PHP, MIT), added with the user's approval on 2026-10-22. Nothing is sent to any outside service, and nothing read is stored or logged except the field values RDPO saves. Scanned (image-only) PDFs have no text to read; the form says so and the PDF can still be attached. Encrypted or damaged PDFs are reported the same way.

**Tests** added:

```powershell
& $phpExe tests/unit_autofill_rules.php
& $phpExe tests/verify_autofill.php http://scholar.localhost
```

`unit_autofill_rules.php` checks every reading rule on made-up pages, then on a generated PDF that includes the right-margin banner letters, plus a text-less and a damaged PDF. `verify_autofill.php` runs the form flow over HTTP: permission refusal, fake PDF, text-less PDF, filled and tinted fields, token mismatch (nothing attached), attach and don't-attach. It removes its records and files.

Known limits: the rules fit the WCC format of the four references; a thesis with a different title-page layout may fill fewer fields, and RDPO must always check the tinted values. Large real manuscripts were not timed (the library parses the whole file even though only ten pages are read).

## Registrar completion list and author name parts (M12, added 2026-11-08)

The first part of M12. The Registrar needs the list of students who completed their thesis, by year and department, sorted by last name (FB-03). The other M12 parts (staff accounts, a provisional head-admin role, backup and restore, audit-log retention, login counts) come later.

**Completion list page.** `admin_completion_list.php` (RDPO, permission `report.completion_list`, link "Completion list" in the RDPO bar) lists one row per author with year and department filters (name, program, department, title and defense date, for RDPO to check), and "Download CSV (opens in Excel)" sends the same students as a file such as `completion_list_2025_college_of_computer_studies.csv`.
- At the user's request (2026-11-08) the file holds **names only**: one student per line, in one cell, written as last name, a comma, then the given names, e.g. `Dela Cruz, Maria Clara S.` (a suffix goes at the end: `Dela Cruz, Juan Miguel Jr.`). There is no heading row and no other column; the quotes Excel needs around a name containing a comma keep it in a single cell.

- The year is the year of the final defense, or the thesis year when no defense date was recorded.
- Unpublished records are included (completion does not depend on the public page); archived records are left out.
- The file starts with a UTF-8 mark so Excel shows letters like "ñ" correctly, and a name starting with `=`, `+`, `-` or `@` gets a leading apostrophe so Excel shows it as text instead of running it as a formula.
- Each download is written to the audit history (`report.completion_list_exported`, no names logged) and is sent with `Cache-Control: no-store`.

**Author names in parts.** Sorting by last name needs the last name stored on its own, so the record form now has one row per author with **Last name**, **First name** (middle names and initials go here; labelled "Given names" until 2026-11-10) and an optional **Suffix** (Jr., III). "Add another author" adds rows (up to 20); empty rows are ignored. Each author row in `thesis_authors` keeps `given_names`, `last_name`, `suffix` and the full name in `author_name`, which search and the record pages still use. Autofill splits printed names such as "Dela Cruz, Maria Clara S." into the boxes (`Scholar\Theses\AuthorNames`); words such as "dela", "de los" and "San" stay with the last name.

**Department.** Records have an optional **Department** (RDPO only, suggestions from existing values; the official department list is not confirmed). It is not shown on public pages until RDPO confirms the public fields (P-03). M13 analytics by department will use it too.

Migration `20261108000100_split_author_names_and_add_department` adds the columns and fills in the name parts of existing authors with the same rule autofill uses; check older records on their edit forms. The sample seeds now include name parts and made-up departments. Run on an older checkout:

```powershell
& $phpExe C:\ProgramData\ComposerSetup\bin\composer.phar dump-autoload -o --strict-psr
& $phpExe bin/console.php migrate
& $phpExe bin/console.php seed
```

**Tests** added:

```powershell
& $phpExe tests/unit_completion_rules.php
& $phpExe tests/verify_completion_list.php http://scholar.localhost
```

`unit_completion_rules.php` (no database) checks name splitting, the list filters, the file name, formula protection and the CSV bytes (one "Last name, Given names" cell per line, nothing else). `verify_completion_list.php` creates four made-up records in its own department and checks login and permission refusals, the year rule, sorting, the archived record, the download headers, the names-only lines, and the audit entry; it removes everything it created. `unit_thesis_rules.php`, `verify_thesis_records.php` and `verify_autofill.php` now send author rows; `database_foundation.php` and `verify_user.php` expected ten migrations (eleven since 2026-11-09).

Known limits: the department is free text, so "CCS" and "College of Computer Studies" count as two departments until RDPO confirms a list. A completion year can only come from the dates recorded on the thesis; records with neither a defense date nor a year are not listed.

## Staff accounts and the head administrator (M12, added 2026-11-09)

The second part of M12. RDPO staff accounts are now managed in the browser instead of only from the terminal.

**Head administrator (provisional).** A new role, `rdpo_head` ("RDPO / Head administrator"; the "(provisional)" was dropped from the shown name at the user's request, though RDPO still has to confirm the level), can do everything an RDPO administrator can, plus manage staff accounts (permission `account.manage`). RDPO has not yet confirmed that a head-administrator level exists (FB-07); if it does not, the account pages can move to `rdpo_admin` by changing one line in `Scholar\Auth\Access`. The same role reads the Activity log and deletes entries older than one year (FB-07; see "Activity log" below).

Make the first head administrator from the terminal (nobody can open the account pages before that):

```powershell
& $phpExe bin/console.php migrate
& $phpExe bin/console.php set-role local_admin rdpo_head
```

`set-role <username> rdpo_admin|rdpo_head` refuses any other role and is written to the history as a terminal action.

**Pages.**
- `admin_accounts.php` (**Staff accounts** in the RDPO bar, head administrator only): every account with its role, status (Active, Temporary password, Disabled) and last login.
- `admin_account_form.php` (**New staff account**): username and role. The system makes a temporary password such as `k7mq-x3pa-hr9t-2bwe` and shows it **once** on the next page; give it to the person directly. It is kept in the session only until that page is shown, never in the address bar, the audit log or a file.
- `admin_account.php?id=N`: the account's details and history, and forms to change its role or username, **Reset password** (a new temporary password, shown once) and **Disable** / **Enable**. Accounts are never deleted. A head administrator cannot change the role of, reset or disable their own account, so the system always keeps at least one working head administrator; another head administrator, or the terminal, must do it.
- `admin_password.php` (**My password**, every staff member): change your own password by typing the current one and the new one twice. New passwords need 12-72 bytes and must not contain the username.

**Permission switches.** At the user's request (2026-11-09), the account page also has a **Permissions** card with Bootstrap switches: Add new records, Edit records, Publish and unpublish, Archive and restore, Upload manuscript PDFs, Open manuscript PDFs, and Registrar completion list. The head administrator switches off what one person should not do and presses **Save permissions**; it applies on that person's next click. The role still sets the most an account may do: a switch can only take a permission away or give it back, never add one the role lacks. "View all records" (every staff account) and "Manage staff accounts" (comes with the head administrator role) are shown but cannot be switched. You cannot switch your own permissions. Buttons and menu links for switched-off permissions are hidden, and the pages refuse them (403) if sent anyway. Switched-off permissions are stored in `user_revoked_permissions` (one row per permission switched off) and written to the history as `account.permissions_changed` with the permission codes that moved.

**Temporary passwords.** A new or reset account must choose its own password before it can open any other RDPO page: `Access::requirePermission()` sends it to **My password** (`users.must_change_password`).

**Signing out other sessions.** Resetting a password, or changing your own, records the time in `users.password_changed_at`. On the next click, any session that logged in before that time is ended (`login.php?password_reset=1`). Your own session continues after you change your own password, because it is given a fresh login time. Disabling an account still signs it out on its next click, as before. A role change applies on the person's next click.

**History.** New audit codes: `account.created`, `account.role_changed`, `account.email_changed` (no longer written since emails were removed), `account.password_reset`, `account.password_changed`. `account.disabled` and `account.enabled` now also record who did it. Only field names are stored ("role", "username", "password"), never a password.

Migration `20261109000100_add_head_admin_role_and_password_fields` adds both staff roles if missing and the two `users` columns. Existing accounts keep their passwords (`must_change_password = 0`). Migration `20261109000200_create_user_revoked_permissions` adds the switch table; existing accounts start with every permission their role gives.

**Tests** added:

```powershell
& $phpExe tests/unit_account_rules.php
& $phpExe tests/verify_staff_accounts.php http://scholar.localhost
```

`unit_account_rules.php` (no database) checks the role permissions and per-account switches, username and password rules and temporary passwords. `verify_staff_accounts.php` creates its own accounts (including a head administrator through `set-role`) and checks the refusals for visitors and ordinary administrators, creating an account, the one-time temporary password, the forced password change, role and username changes, reset (open session ends, old password stops working), disable/enable, the permission switches (refused pages, hidden buttons, a refused "publish", made-up switches ignored, your own switches locked), the "not your own account" rule, modified requests, and the audit entries; it removes everything it created. `database_foundation.php` and `verify_user.php` now expect twelve migrations.

Known limits: there is no "forgot password" link; a head administrator resets the password and hands over the temporary one. Wrong current passwords on **My password** are not counted toward the login limits.

## Activity log and one-year retention (M12, added 2026-11-09)

The third part of M12 (FB-07): keep the audit history for one year, and let the head administrator delete older entries, with the deletion itself logged.

**Page.** `admin_activity_log.php` (**Activity log** in the RDPO bar, head administrator only; permissions `audit.view` and `audit.delete_old`, which come with the `rdpo_head` role and are not per-account switches) lists every audit entry, newest first, 50 per page (**Newer** / **Older**). Columns: when, who (an account, or "terminal"), what (a readable name for each action code), the item (a link to the record or account), and details (the names of changed fields; for permission changes, the switch names). Filters: **What** (one kind of action), **Who** (one account, or "Terminal"), **From** and **To** (whole days). Strange filter values in the address are ignored rather than shown as errors.

**Deleting old entries.** A card at the top says how many entries are older than one year. **Delete entries older than one year** (with a browser confirmation) deletes them permanently and writes one new entry, `audit.old_entries_deleted`, whose details say how many were deleted and up to which date, e.g. "120 entries before 2025-11-09". That entry is written after the deletion, so it is never removed by the same click. Pressing it when nothing is old deletes and logs nothing. Deletion is manual, not scheduled, until RDPO says otherwise.

Things that read the audit log are affected after a deletion: an account's "Last login" can become "Never" if it has not logged in for a year, and record or account histories lose their entries older than one year.

No migration was needed; `audit_logs` already had an index on `created_at`.

**Tests** added:

```powershell
& $phpExe tests/unit_activity_rules.php
& $phpExe tests/verify_activity_log.php http://scholar.localhost
```

`unit_activity_rules.php` (no database) checks who may use the log, that every action code has a readable name, and the filter rules. `verify_activity_log.php` checks the refusals for visitors and ordinary administrators, every filter, pages of 50, the old-entry count, and the deletion: only entries older than one year go, the deletion is logged once, and pressing again does nothing. It makes its own entries dated 400 days ago and tests the deletion **only when the database has no other entries older than one year**, so it never removes real history; otherwise it prints SKIP for that step. It removes everything it created.

## Backups and tested restore (M12, added 2026-11-09)

The fourth part of M12. Who runs backups is still open with RDPO (FB-04); at the user's choice there is a terminal command that IT can schedule, plus a head-administrator page. Restoring is terminal-only, because it replaces all data.

**What a backup is.** One file, `scholar_backup_YYYY-MM-DD_HHMMSS_xxxxxx.tar`, holding `manifest.json` (when it was made, row counts, and a SHA-256 fingerprint of every part), `database.sql.gz` (every table as SQL, one statement per line, read as one consistent snapshot) and `files/theses/` (every stored manuscript PDF; unfinished autofill uploads in `pending/` are skipped). It is a `.tar` because PHP's built-in `PharData` can write it; the zip extension is not enabled in Laragon. Windows 11 and 7-Zip open `.tar` files. A backup is built under a temporary name and renamed when complete, so a half-made backup is never listed.

**Where.** `storage/backups/` by default. A backup on the same drive is lost with that drive, so set `BACKUP_DIRECTORY` in `.env` to a full path on another drive or a network folder (see `.env.example`), or download each backup and keep the copy elsewhere.

**Terminal** (run from `scholar/`):

```powershell
& $phpExe bin/console.php backup
& $phpExe bin/console.php verify-backup scholar_backup_2026-11-09_103000_a1b2c3.tar
$env:SCHOLAR_DB_ADMIN_USER = 'root'
$env:SCHOLAR_DB_ADMIN_PASSWORD = ''
try { & $phpExe bin/console.php restore-backup scholar_backup_2026-11-09_103000_a1b2c3.tar --confirm }
finally { Remove-Item Env:SCHOLAR_DB_ADMIN_USER,Env:SCHOLAR_DB_ADMIN_PASSWORD -ErrorAction SilentlyContinue }
```

- `backup` makes one backup and records `backup.created` (no account, i.e. "terminal"). Windows Task Scheduler can run it daily.
- `verify-backup` checks every part against its fingerprint. It takes a file name in the backup folder, or a full path, e.g. a copy on a USB drive.
- `restore-backup` checks the file first. Without `--confirm` it only explains what would happen. With `--confirm` it makes a **safety backup of the current state first**, then replaces every table and copies the backup's PDFs back into `storage/theses` (other PDFs are left alone), and records `backup.restored`. It needs a database administrator through `SCHOLAR_DB_ADMIN_USER` / `SCHOLAR_DB_ADMIN_PASSWORD`, the same way as `database_foundation.php`, because the application's own database account is deliberately not allowed to DROP tables. A damaged or changed backup is refused before anything is touched. MySQL cannot undo table changes, so a restore that fails halfway is recovered by restoring the safety backup.

**Page.** `admin_backups.php` (**Backups** in the RDPO bar, head administrator only, permission `backup.manage`): **Make a backup now**, the list of backups with **Download**, a reminder when there is no backup or the newest is more than 7 days old (provisional), a warning while backups are in the default folder, and the restore command. Only file names the application makes can be downloaded (anything like `../.env` gets 404). Each backup and download is recorded (`backup.created`, `backup.downloaded`), because a backup holds every record and every staff account's password hash.

**Tests** added:

```powershell
& $phpExe tests/unit_backup_rules.php
$env:SCHOLAR_DB_ADMIN_USER = 'root'; $env:SCHOLAR_DB_ADMIN_PASSWORD = ''
try { & $phpExe tests/verify_backups.php http://scholar.localhost }
finally { Remove-Item Env:SCHOLAR_DB_ADMIN_USER,Env:SCHOLAR_DB_ADMIN_PASSWORD -ErrorAction SilentlyContinue }
```

`unit_backup_rules.php` (no database) builds small made-up backups and checks that only a correct one passes: a changed database part, a changed, missing or extra PDF, an unexpected or `../` entry, a missing manifest and a non-`.tar` file are all caught. `verify_backups.php` checks the page's refusals, making and downloading a backup (the bytes equal the file), refused odd names, `verify-backup` on a good and a changed copy, and that `restore-backup` refuses without `--confirm`, without the administrator, and for a changed copy without touching the database. Then it **restores the backup into a temporary database and folder** and compares: the same columns, indexes and foreign keys, the same rows byte for byte (the activity log by count, since the test adds to it), a multi-line abstract with quotes, a backslash and "ñ" exactly the same, and every PDF's fingerprint. It removes everything it created, including its backups.

## Website visits and staff logins (M12, added 2026-11-09)

The fifth part of M12 (FB-06). RDPO's meaning is still open; the user chose, provisionally, to count successful logins and website visits, per day, week or month. A visit is a visit to the website in general, not each page.

**Counting visits.** `Scholar\SiteVisits::countVisit()` runs on the public search and thesis pages, the login page and every RDPO page (through `Access::requirePermission()`). A browser's first page sets a small `scholar_visit` cookie (30 minutes, renewed on every page, HttpOnly, no personal value) and adds one row to `site_visits`; pages opened while the cookie lasts do not count again. A row stores only the time: no IP address, browser or account. Requests without a browser name, or naming themselves as robots or scripts (bot, crawler, curl, python...), are not counted, which also keeps the automated tests out of the numbers.

**Page.** `admin_usage.php` (**Visits and logins** in the RDPO bar, head administrator only, permission `report.usage`): **Daily**, **Weekly** (Monday to Sunday) or **Monthly**, with optional From/To dates (default: the last 14 days, 12 weeks or 12 months; ranges are widened to whole weeks or months, and very long ranges are shortened). It shows the totals, a table of visits and logins per period (empty periods as 0), and logins per account. **Download CSV (opens in Excel)** gives one row per period (oldest first) with visits, all logins, and a logins column per account; each download is recorded (`report.usage_exported`). Logins come from the activity log's `auth.login` entries, so they only go back one year once old activity entries are deleted.

Migration `20261109000300_create_site_visits` adds `site_visits`. Counting starts when the migration runs; there are no earlier visits.

**Tests** added:

```powershell
& $phpExe tests/unit_usage_rules.php
& $phpExe tests/verify_usage.php http://scholar.localhost
```

`unit_usage_rules.php` (no database) checks permissions, the robot rule, week and month boundaries and labels, default and odd filters, and the CSV. `verify_usage.php` checks that a browser counts one visit however many pages it opens, that another browser counts again, that robots and scripts do not count, that only the time is stored, the refusals, and, with made-up visits and logins in March-April 2001, the exact daily, weekly and monthly numbers, the range edges, logins per account and the CSV. It removes the visits and entries it made. `database_foundation.php` and `verify_user.php` now expect thirteen migrations.

## Research analytics (M13, added 2026-11-09)

FB-05: RDPO counted research output by hand. `admin_analytics.php` (**Analytics** in the RDPO menu; permission `report.analytics`, which every RDPO role has and the head administrator can switch off per account) shows, as tables with no charts:

- **Per year**: theses and student authors.
- **Per department**: theses and student authors ("No department recorded" for records without one).
- **Theses per year and department**: a table with a total for each year and each department. Its **Year** column is frozen (CSS `position: sticky`), so the years stay visible while the department columns scroll sideways.

Choices on the page: count each thesis in its **Thesis year** (default) or its **Final defense year** (the thesis year when no defense date was recorded, the same rule as the Registrar completion list), **All records** (published and unpublished) or **Published records only**, and a **From year** / **To year** range (added at the user's request; only years that have records are offered, either end may be left open, years chosen the wrong way round are swapped, and records without a year are left out while a range is chosen). The range applies to all three tables and the CSV, whose name then includes it, e.g. `research_totals_by_thesis_year_2020-2025.csv`. Archived records are never counted. "Students" counts author names per thesis, so a student on two theses counts twice. Departments are counted as typed, so two spellings make two columns until RDPO confirms an official list.

**Download CSV (opens in Excel)** gives the year-by-department table with total columns and a Total row (UTF-8 mark; a department that looks like a formula is made plain text). It holds totals only, no names, so unlike the completion list it is not written to the activity log.

**Tests** added:

```powershell
& $phpExe tests/unit_analytics_rules.php
& $phpExe tests/verify_analytics.php http://scholar.localhost
```

`unit_analytics_rules.php` (no database) checks the permission, the filters including the year range and file name, the order of years and departments, and every line of a made-up CSV. `verify_analytics.php` makes five records in two departments of its own (one archived, one published, one defended a year after its thesis year) and checks the refusals (including the per-account switch), the per-department, per-year and year-by-department numbers, the defense-year and published-only choices, the year range (one year, one open end, swapped years, the CSV for a range), the frozen Year column, and the CSV; it removes everything it created.

## RDPO menu as a sidebar (added 2026-11-09)

At the user's request the crowded top bar on RDPO pages became a menu in `templates/admin_sidebar.php` (moved to `public/assets/admin_sidebar.php` on 2026-11-10, see below), the one file every RDPO page includes (outside `public/`, so it cannot be opened directly). It is the first shared page fragment: the menu had grown to about ten permission-dependent links repeated on twelve pages.

- On wide screens (992px and up) it is a fixed sidebar on the left, grouped into **Thesis records** (Records, New record), **Reports** (Completion list, Analytics) and **Administration** (Staff accounts, Activity log, Visits and logins, Backups), with Public search, the signed-in name, **My password** and **Log out** at the bottom. The open page is highlighted.
- On phones and small windows a slim bar shows a **Menu** button that slides the same menu in (Bootstrap offcanvas; the Bootstrap 5.3.3 JavaScript bundle is now loaded from the same CDN, with its integrity hash checked against the real file).
- Each link appears only when the account may use that page; while a password is temporary only **Log out** is shown. The PDF viewer keeps its own slim "Back to record" bar, so the PDF has the whole width.
- Because the sidebar takes 240px from 992px up, the records filter row now switches to its one-line layout at 1200px instead of 992px.

## Staff role names (changed 2026-11-09)

At the user's request the two staff roles are now shown as **RDPO / Administrator** and **RDPO / Head administrator**, without "(development label)" and "(provisional)". Migration `20261109000400_rename_staff_role_labels` renames them in existing databases (the earlier migration that created them is already applied, so it is left unchanged), and the sample seed uses the new name. Only the shown names changed; the codes `rdpo_admin` and `rdpo_head` and every permission are the same. `database_foundation.php` and `verify_user.php` now expect fourteen migrations.

## Campus (added 2026-11-09)

At the user's request every thesis record now has a **Campus**: one of WCC QC, WCC Antipolo, WCC Guimba, WCC North Manila or WCC Binalonan (the list is `Scholar\Theses\Campuses::NAMES`).

- **Form:** a required dropdown on the New record and Edit forms, right after Department. It starts on "Choose a campus", so a campus is always picked on purpose; anything not on the list is refused ("Choose the campus the thesis came from."). Autofill does not fill it.
- **Shown:** on the RDPO record page, as a Campus column in the RDPO records table, and (at the user's request) on the public search results and public thesis page; `campus` is in the public field lists in `Scholar\Theses\PublicCatalog`.
- **Filter:** a Campus dropdown on the RDPO records list and on the public search (kept in the page links).
- **Existing records:** migration `20261109000500_add_thesis_campus` adds `theses.campus` (indexed) and sets every existing record to **WCC QC**, as the user asked. The column then has no default, so a record saved without a campus is an error rather than quietly becoming WCC QC. The sample seeds use WCC QC.
- Not added yet: campus filters on the completion list and analytics.

`unit_thesis_rules.php` checks the campus rules and the filter; `verify_thesis_records.php` saves a record in WCC Guimba and checks the missing-campus message, the record page, the table column, the RDPO filter, and after publishing the public page, public results and public filter. `verify_public_search.php` checks that every result of the public campus filter has that campus. `database_foundation.php` and `verify_user.php` now expect fifteen migrations.

## Semantic search (M14, added 2026-11-10)

Typed searches on the public search page and the RDPO records list are now **hybrid**: records containing the typed words (the keyword search, unchanged) plus records that are close in **meaning**, ranked together. Meaning comes from the **bge-m3** embedding model (user's choice, 2026-11-10; multilingual, 1024-number vectors) served by **Ollama**, and the vectors are stored and compared in **Qdrant**. Both run on this machine; no outside AI service is used and no answers are generated.

**What is embedded.** Only fields that are already public: title, keywords and abstract (`HybridRanking::documentText`). Each record's entry also carries its filters (published, archived, year, program, campus). MySQL stays the source of truth: every result is checked against MySQL's visibility rules and filters before it is shown, so a stale index can never reveal an unpublished or archived record.

**How results are ranked** (`Scholar\Search\HybridRanking::combine`):
- every record containing all typed words is listed (as before), with a bonus of 0.15 on top of its meaning similarity;
- records found only by meaning are listed when their cosine similarity is at least `SEARCH_MIN_SCORE` (default 0.5) and get a **"Similar topic"** label;
- **Best match** is the default order whenever words are typed; Newest, Oldest, Title (and Recently changed for RDPO) still work on the same results.

On the sample records, close paraphrases scored about 0.6 or more (e.g. "plant sickness detection with pictures" found the rice-leaf disease thesis at 0.605), while unrelated queries stayed below 0.5 ("cooking recipes": 0.464 at most). Borderline paraphrases can fall just under 0.5, and the sample abstracts are very short; tune `SEARCH_MIN_SCORE` on real abstracts (M17 measures top-5 quality against keyword search).

**When the services are down**, nothing breaks: searches fall back to word matches with a notice ("Search by meaning is unavailable right now..."), and saving a record still works. The record is marked "not included yet" on its RDPO page (`theses.search_indexed_at` is NULL) and a note goes into the private log.

**Keeping the index up to date.** The record pages refresh a record's entry after create, edit, publish, unpublish, archive and restore (`SearchIndex::refreshQuietly`). Terminal commands:

```powershell
& $phpExe bin/console.php search-status
& $phpExe bin/console.php reindex-search
& $phpExe bin/console.php reindex-search --missing
```

`reindex-search` rebuilds the whole index (needed after changing the model); `--missing` only adds records that are new, changed or failed earlier, and removes entries of records that no longer exist. Migration `20261110000100_add_thesis_search_indexed_at` adds `theses.search_indexed_at`.

**Setup.** Settings are optional `.env` values (see `.env.example`): `SEARCH_SEMANTIC_ENABLED`, `SEARCH_OLLAMA_URL` (default `http://127.0.0.1:11434`), `SEARCH_EMBEDDING_MODEL` (`bge-m3`), `SEARCH_QDRANT_URL` (`http://127.0.0.1:6333`), `SEARCH_QDRANT_API_KEY`, `SEARCH_QDRANT_COLLECTION` (`scholar_theses`), `SEARCH_MIN_SCORE` (`0.5`). Start Ollama (`ollama pull bge-m3` once) and Qdrant, then run `reindex-search`. Keep Qdrant private: publish its ports only on `127.0.0.1` (e.g. `docker run -d --name qdrant -p 127.0.0.1:6333:6333 -p 127.0.0.1:6334:6334 -v qdrant_storage:/qdrant/storage qdrant/qdrant`) or start it with an API key and set `SEARCH_QDRANT_API_KEY`. Code: `app/search/` (`JsonHttp`, `EmbeddingClient`, `QdrantClient`, `SearchIndex`, `HybridRanking`, `SearchUnavailable`), `Scholar\Theses\ThesisSearch::hybridSearch()`.

**Tests** added:

```powershell
& $phpExe tests/unit_search_rules.php
& $phpExe tests/verify_semantic_search.php http://scholar.localhost
```

`unit_search_rules.php` (no services) checks the embedded text (no private fields), the stored filters, the Qdrant filter for each status, the ranking and labels, and the settings. `verify_semantic_search.php` needs Ollama and Qdrant running (exit 2 otherwise). It saves a made-up beehive record through the form and checks that it is indexed, that "insect farm climate sensors" (no word in common) finds it as a "Similar topic" on the RDPO list, that the public search only finds it once published and not once archived, that RDPO's Archived filter does, and that with Ollama unreachable saving marks it "not included yet" and searching falls back to words. `verify_thesis_records.php` and `verify_autofill.php` now also remove their records' search entries during cleanup; `database_foundation.php` and `verify_user.php` expect sixteen migrations.

## Demo theses for presentations (added 2026-11-10)

At the user's request there is a set of 24 **made-up** but realistic-looking thesis records (`Scholar\DemoTheses`) for showing semantic search: normal-length abstracts, titles without "Sample", all five campuses and four departments, years 2023-2026, all published. Every title, abstract, author and keyword is invented; nothing comes from the reference theses. They are not part of the sample seeds (whose counts the foundation test checks), but a separate set loaded and removed by command:

```powershell
& $phpExe bin/console.php demo-data
& $phpExe bin/console.php demo-data --remove
```

Loading is safe to repeat (existing demo records are skipped) and updates the search index; `--remove` deletes only records whose `fixture_key` starts with `demo_`, with their authors and history, and removes their search entries. `unit_demo_data.php` checks every demo record is complete and believable.

Searches that work well for a demonstration (all public, on the demo set): "warning residents before floodwater rises", "crop disease detection using phone camera", "students who work while studying and cannot sleep", "fake health information on social media", "small neighborhood stores keeping track of money", the Filipino "mga ina na nagpapasuso" (finds the breastfeeding study) and "basura sa bahay" (finds the waste-segregation app), and "cooking recipes" (finds nothing).

**Load.** Each typed search makes one short embedding request to Ollama; Qdrant and MySQL answer in milliseconds. Measured on the development PC (bge-m3 on the GPU): 1 search 0.2 s; 10 at the same moment 0.45 s; 50 at once 2.3 s; 100 at once 4.6 s; 300 at once 13.8 s for the last one. A search waits at most 10 s for meaning results and then shows word matches with a notice, so heavy load slows the meaning part but never breaks the page. A search during the indexing of a long abstract took 0.35 s. CPU-only speed on the Oracle ARM server still has to be measured.

## Two-factor authentication (2FA): authenticator app, backup codes and emergency access (added 2026-11-10)

At the user's request, staff can add a second step to logging in (called "two-factor authentication (2FA)" on the pages, the name people know from other apps): after the password, a 6-digit code from an authenticator app such as 2FAS, Google Authenticator or Microsoft Authenticator. The libraries are `spomky-labs/otphp` (the codes) and `endroid/qr-code` (the QR code, drawn as SVG so no image file or GD is needed).

- **Who.** Head administrators **must** use it: until they link an app, every RDPO page sends them to "Two-factor authentication" (`admin_two_factor.php`), and the menu shows only Log out. For RDPO administrators it is optional; they can turn it on and off there (turning off asks for the password). The page is in the sidebar under "My password".
- **Linking.** The page shows a QR code and, for people who cannot scan, the same key as 32 letters. The secret waits in the session until a correct code proves the app has it; only then is it saved, encrypted. Head administrators then get **10 backup codes**, shown once.
- **Logging in.** After a correct password the person goes to `login_code.php` and has 5 minutes to type the code; no RDPO page opens before that. A code is accepted for the current 30 seconds and the 30 seconds before and after (phone clocks drift), and each code works only once. Wrong codes count toward the same failed-login limit as wrong passwords; at the limit the person must wait and type the password again.
- **Remember this device for 30 days.** A tick box on the code page. That browser then skips the code for that account (the password is still needed) until the 30 days are over, the person presses "Forget all remembered devices", the app is relinked, or an emergency reset happens. The browser keeps a long random token in an HttpOnly cookie; the database keeps only its SHA-256 hash.
- **Backup codes** (head administrators). Each works once, in place of the app code ("Use a backup code instead" on the code page). The person is told how many are left; after the last one, the page asks them to make a new set. "Make 10 new codes" asks for the password and makes the old set stop working. Codes are stored as password hashes.
- **Lost phone.** The person can link a different app themselves while logged in ("Link a different app", asks for the password). If they cannot log in, a head administrator presses "Reset 2FA" on that person's account page (not on their own); a head administrator is then asked to link an app again at the next click.
- **Backup codes cover a lost app, not a lost password.** A forgotten password is still reset by another head administrator, which gives a temporary password and keeps the app linked.
- **Emergency access, the last resort.** For a head administrator who lost the password, the app and the backup codes at once (or when no other head administrator can help), on the server:

```powershell
& $phpExe bin/console.php emergency-access local_admin --confirm
```

It prints a new temporary password once, signs the account out everywhere, and switches its 2FA off (the app, backup codes and remembered devices stop working). At the next login the person types the temporary password, chooses their own password, and is then made to link an authenticator app again. Without `--confirm` it only explains what it would do. It is logged with no actor ("terminal").

**The encryption key.** The app secrets are encrypted with a random key in `storage/two_factor.key`, made automatically the first time it is needed. It is git-ignored and **not** in backups, so a stolen database or backup file cannot make codes. Keep a copy of it with the server's other secrets: if it is lost (for example after restoring a backup on a new server), linked apps stop working, and each head administrator needs `emergency-access` (RDPO administrators can be reset from the account page).

The Activity log names the new actions: 2FA turned on, turned off, reset by a head administrator, new backup codes made, remembered devices forgotten, logged in with a backup code, and emergency access from the terminal. No code, secret or password is ever logged.

The public pages' "RDPO login" button now opens the RDPO records page (`admin.php`) instead of the login page: someone still logged in goes straight in, and anyone else is sent to the login page as before.

Tests: `unit_two_factor_rules.php` checks the code windows, reuse, the QR address, backup code format and the encryption without the database. `verify_two_factor.php` goes through the whole flow over HTTP with made-up accounts. The suites whose made-up accounts are head administrators (`verify_staff_accounts`, `verify_activity_log`, `verify_backups`, `verify_usage`) link a made-up app through `tests/two_factor_helper.php` and type its code at login.

Note for `local_admin`: it is a head administrator, so its next login asks for an app to be linked.

**Changing the password with 2FA on** (added later on 2026-11-10). The "My password" page also asks for a 2FA code: the 6-digit app code, or for head administrators one of their backup codes. Everything else on the form is checked first, so a code is not used up by a form refused for another reason. A temporary password (new account, reset, emergency access) is changed without a code.

**Usernames** (added later on 2026-11-10). Head administrators can change usernames on the account page: their own, and those of RDPO administrators, but not another head administrator's. The same rule as when creating an account applies (3-64 letters, digits, underscores, dots or hyphens; not already used, whatever the capitals). The person logs in with the new username; the change is in the history as "Username changed" (field name only).

## Emails removed (2026-11-10)

At the user's request staff accounts no longer have an email: they were not used for anything but an alternative login name. Login, the terminal commands (`provision-admin <username>`, `disable-account`, `enable-account`, `set-role`, `emergency-access`) and the account pages use the username only. The New account form, the account list and the account page no longer show or ask for an email. Migration `20261111000200_remove_user_emails` drops `users.email` and its unique index; the stored emails are deleted with it (a backup was made first on `scholar_dev`). Older Activity log entries named "Account email changed" keep their name.

## Sidebar moved to public/assets (2026-11-10)

At the user's request the RDPO sidebar moved from `templates/admin_sidebar.php` to `public/assets/admin_sidebar.php`, and every RDPO page now includes it with `require __DIR__ . '/assets/admin_sidebar.php';`. Because `public/` is what the browser can reach, the piece is guarded twice: `public/assets/.htaccess` refuses every `.php` file in that folder (403), and the file itself answers 404 if it is ever the requested page (for a server that ignores `.htaccess`, such as nginx). `verify_attacker.php` checks that it cannot be opened on its own. Styles or images added to `public/assets` later are served normally.

## AI relevance explanations (abstract-only, added 2026-11-12)

- Set `OPENROUTER_API_KEY` and `OPENROUTER_MODEL` in `.env` (see `.env.example`). Leave either empty to turn explanations off.
- `public/index.php` shows each result's title, authors, date conducted, abstract (expandable), Qdrant relevance score and level
  (High ≥ 0.65, Moderate ≥ 0.55, otherwise Low; `Scholar\Search\HybridRanking`). It never calls OpenRouter itself.
- An **AI overview** panel sits above the theses (`relevance.php?mode=overview`): it sends only the query and the abstracts of the top 5
  results, numbered [1]-[5] without titles; the page adds the titles. If AI is unavailable, the panel says so.
- `public/assets/search.js` asks `public/relevance.php` for the overview first, then for each result. That endpoint only accepts published, non-archived records
  and sends **only the search query and the abstract** to OpenRouter (`Scholar\Search\RelevanceExplainer::buildRequest`).
  Answers are cached in `storage/cache/relevance` for 30 days; uncached requests are limited to 60 per address per 10 minutes.
- If OpenRouter fails, results and scores still show; the explanation box simply stays hidden (the reason goes to the private log).
- Original PDFs live only in `storage/theses` (denied by `storage/.htaccess` and the project-root `.htaccess`) and reach RDPO only
  through `admin_thesis_pdf.php` / `admin_thesis_viewer.php`, which require login and the `thesis.view_pdf` permission.
- Tests: `php tests/unit_relevance_rules.php` (abstract-only request, answer guards) and `php tests/verify_file_protection.php`
  (unauthorized file access).

## Searching with a sentence (added 2026-11-13)

`Scholar\Search\QueryInterpreter` turns a sentence typed in the public search box into a topic plus the normal filters,
with no AI call: "research conducted by IT" → program BS Information Technology; "find me a research related to flood
monitoring" → topic "flood monitoring"; "in 2024" → year; "in Antipolo" → campus; "latest"/"oldest" → order.
A program is only taken when the wording says who did the research ("by IT", "BSCS students", "from the Computer Science
department"), so "IT security" or "nurse shift scheduling" stay topics. The page shows how it understood the sentence, with a
"Search your exact words instead" link (`&exact=1`); words in "double quotes" are never interpreted. A dropdown the visitor sets
always wins. Tests: `php tests/unit_query_rules.php`.
