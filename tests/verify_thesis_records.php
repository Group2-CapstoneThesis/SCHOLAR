<?php
// WHAT THIS FILE DOES: automatically checks RDPO record management and what the public can see.
// It sends requests to our local Apache like a browser, using made up accounts and made up thesis records.

// STEP 1: Allow terminal use only, load the application, and connect to the development database.
// STEP 2: Create a temporary fictional RDPO administrator, plus a temporary account whose made-up role has NO permissions.
// STEP 3: Without logging in, request the record pages and submit the form directly; all must be refused.
// STEP 4: Log in with the no-permission account and check that the RDPO pages answer 403 (central default deny).
// STEP 5: Log in as the RDPO administrator, submit an incomplete form, and check the field errors and retained text.
// STEP 6: Submit the form without its security token; nothing may be saved.
// STEP 7: Create an unpublished record and check its RDPO page; the public search and public page must not show it.
// STEP 8: Publish it; the public pages must show it, escape its HTML-looking title, and hide RDPO-only fields.
// STEP 9: Edit the abstract and authors, then save again without changes; check both messages and the audit entry.
// STEP 10: Send an unknown action and an action without the security token; both must be refused.
// STEP 11: Archive the record (hidden everywhere), try publishing it while archived, then restore it.
// STEP 12: Check the audit history: every change is recorded with the right account and no field contents.
// STEP 13: Save and publish a record with no abstract; it is stored as NULL and each page says so.
// STEP 14: In cleanup, remove only this script's records, audit rows, accounts and made-up role.

// Each check prints PASS; a failed check stops the remaining checks and reports failure.
// Cleanup is attempted in finally even when a check fails after preparation starts.
// The terminal exit code is 0 for success or 1 for a caught test failure.

declare(strict_types=1); // reject incorrect types passed into the test helpers

// run this script from the terminal; it is not a browser page
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

// print the action before performing it; static remembers the number between calls
function showStep(string $action): void {
    static $stepNumber = 0;
    $stepNumber++;
    echo "\nSTEPS $stepNumber: $action\n";
}

// report each expected result; throwing stops the test if a requirement fails
function check(bool $condition, string $description): void {
    if (!$condition) {
        throw new RuntimeException($description);
    }
    echo "PASS: $description\n";
}

// cURL is PHP's HTTP client; this helper sends one request to our existing Apache
// form is either an array of submitted fields or null for a normal GET request
function requestPage(CurlHandle $client, string $baseUrl, string $page, $form = null): array {
    $isPost = false;
    if ($form !== null) {
        $isPost = true;
    }
    $options = [
        CURLOPT_URL => $baseUrl . $page,
        CURLOPT_POST => $isPost,
        CURLOPT_RETURNTRANSFER => true, // return the response instead of printing page HTML
        CURLOPT_HEADER => true, // include headers so redirects/cookies can be inspected
        CURLOPT_FOLLOWLOCATION => false, // check the original redirect before following it
        CURLOPT_TIMEOUT => 10,
        CURLOPT_PROXY => '', // send local test requests directly to Apache
    ];
    curl_setopt_array($client, $options);
    if ($form !== null) {
        curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($form)); // encode fields like a browser form
    }
    $response = curl_exec($client);
    if ($response === false) {
        throw new RuntimeException('HTTP request failed.');
    }
    $headerLength = curl_getinfo($client, CURLINFO_HEADER_SIZE);
    // separate the status, headers and HTML body so each check has named values to read
    return [
        'status' => curl_getinfo($client, CURLINFO_HTTP_CODE),
        'headers' => substr($response, 0, $headerLength),
        'body' => substr($response, $headerLength),
    ];
}

// find the hidden CSRF input in the returned HTML, just as a browser form would use it
function tokenFrom(array $response): string {
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $response['body'], $matches)) {
        throw new RuntimeException('CSRF token missing.');
    }
    return $matches[1];
}

// open a fresh login form and submit the given credentials with its CSRF token
function submitLogin(CurlHandle $client, string $baseUrl, string $identifier, string $password): array {
    $login = requestPage($client, $baseUrl, 'login.php');
    return requestPage($client, $baseUrl, 'login.php', [
        'identifier' => $identifier,
        'password' => $password,
        'csrf_token' => tokenFrom($login),
    ]);
}

// a new HTTP client with its own empty cookie jar, like a separate browser
function newClient(): CurlHandle {
    $client = curl_init();
    curl_setopt($client, CURLOPT_COOKIEFILE, ''); // remember cookies like a browser
    curl_setopt($client, CURLOPT_RESOLVE, ['scholar.localhost:80:127.0.0.1']);
    return $client;
}

// count records with this exact title, straight from the database
function countTitle(PDO $pdo, string $title): int {
    $statement = $pdo->prepare('SELECT COUNT(*) FROM theses WHERE title = :title');
    $statement->execute(['title' => $title]);
    return (int) $statement->fetchColumn();
}

// use our existing Laragon Apache; an alternative local URL can be the first argument
$baseUrl = 'http://scholar.localhost';
if (isset($argv[1])) {
    $baseUrl = $argv[1];
}
$baseUrl = rtrim($baseUrl, '/') . '/';
$pdo = connectDatabase();

// separate synthetic names so this script never touches another suite's data
$suffix = bin2hex(random_bytes(6));
$username = 'verify_records_' . $suffix;
$email = $username . '@example.invalid';
$password = bin2hex(random_bytes(20)); // never print or commit the temporary password
$noRoleUsername = 'verify_norole_' . $suffix;
$noRoleCode = 'verify_norole_' . $suffix;
$marker = 'zqmarker' . $suffix; // an unusual word that only this script's record contains, for searching
// the title deliberately looks like HTML, to prove pages print it as text instead of running it
$title = 'Synthetic Verification Record ' . $marker . ' <b>bold?</b>';
$adviserText = 'Private Adviser ' . $suffix; // RDPO-only field that must never reach the public pages
$createdThesisId = 0;
$client = newClient();
$failed = false;
try {
    echo "PREPARATION: Create this script's temporary accounts.\n";
    // invoke the real provisioning command, passing the password through the environment
    putenv('SCHOLAR_ADMIN_PASSWORD=' . $password);
    // proc_open starts a child PHP command; the three pipes are its input/output/error streams
    $process = proc_open([PHP_BINARY, __DIR__ . '/../bin/console.php', 'provision-admin', $username, $email],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]); // no keyboard input is needed for this command
    stream_get_contents($pipes[1]); // consume normal output without exposing it
    stream_get_contents($pipes[2]); // also consume error output without printing credentials
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0, 'CLI provisions temporary RDPO administrator');
    putenv('SCHOLAR_ADMIN_PASSWORD');
    $lookup = $pdo->prepare('SELECT id FROM users WHERE username = :username');
    $lookup->execute(['username' => $username]);
    $adminId = (int) $lookup->fetchColumn();

    // a made-up role that Access::ROLE_PERMISSIONS does not list; the CLI only creates RDPO accounts,
    // so this fixture account is inserted directly with a normal password hash
    $statement = $pdo->prepare('INSERT INTO roles (code, label) VALUES (:code, :label)');
    $statement->execute(['code' => $noRoleCode, 'label' => 'Temporary test role without permissions']);
    $noRoleId = (int) $pdo->lastInsertId();
    $statement = $pdo->prepare('INSERT INTO users (role_id, username, email, password_hash) VALUES (:role_id, :username, :email, :password_hash)');
    $statement->execute([
        'role_id' => $noRoleId,
        'username' => $noRoleUsername,
        'email' => $noRoleUsername . '@example.invalid',
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    check($adminId > 0 && $noRoleId > 0, 'Temporary accounts prepared');

    showStep('Request the record pages and submit the record form without logging in.');
    $anonymous = newClient();
    try {
        check(requestPage($anonymous, $baseUrl, 'admin_thesis_form.php')['status'] === 302, 'New-record form redirects visitors to login');
        check(requestPage($anonymous, $baseUrl, 'admin_thesis.php?id=1')['status'] === 302, 'Record page redirects visitors to login');
        $response = requestPage($anonymous, $baseUrl, 'admin_thesis_form.php', ['title' => $title, 'csrf_token' => 'x']);
        check($response['status'] === 302 && countTitle($pdo, $title) === 0, 'Direct form submission without login saves nothing');
    } finally {
        unset($anonymous); // PHP releases the handle object without deprecated curl_close
    }

    showStep('Log in with the account whose role has no permissions, then open the RDPO pages.');
    $noRoleClient = newClient();
    try {
        check(submitLogin($noRoleClient, $baseUrl, $noRoleUsername, $password)['status'] === 303, 'No-permission account can log in');
        $response = requestPage($noRoleClient, $baseUrl, 'admin.php');
        check($response['status'] === 403 && strpos($response['body'], 'RDPO thesis records') === false, 'Record list refused with 403');
        $response = requestPage($noRoleClient, $baseUrl, 'admin_thesis_form.php');
        check($response['status'] === 403, 'New-record form refused with 403');
    } finally {
        unset($noRoleClient);
    }

    showStep('Log in as RDPO and submit an incomplete new-record form.');
    check(submitLogin($client, $baseUrl, $username, $password)['status'] === 303, 'RDPO administrator logs in');
    $form = requestPage($client, $baseUrl, 'admin_thesis_form.php');
    check($form['status'] === 200 && strpos($form['body'], 'New thesis record') !== false, 'New-record form opens');
    $response = requestPage($client, $baseUrl, 'admin_thesis_form.php', [
        'csrf_token' => tokenFrom($form),
        'title' => $title,
        'authors' => '',
        'publication_year' => '20x5',
        'program' => '',
        'abstract' => '',
    ]);
    check($response['status'] === 422, 'Incomplete form answers 422');
    check(strpos($response['body'], 'Enter at least one author') !== false
        && strpos($response['body'], 'Enter a four-digit year') !== false, 'Each problem field shows its own message');
    check(strpos($response['body'], 'Abstract is required') === false, 'An empty abstract is not reported (optional)');
    check(strpos($response['body'], '&lt;b&gt;bold?&lt;/b&gt;') !== false && strpos($response['body'], '<b>bold?</b>') === false, 'Typed title is kept and escaped');
    check(countTitle($pdo, $title) === 0, 'Invalid form saved nothing');

    // the complete form used by the next steps
    $validFields = [
        'title' => $title,
        'authors' => "Synthetic Verifier One\nSynthetic Verifier Two",
        'publication_year' => '2025',
        'program' => 'BS Information Technology',
        'adviser' => $adviserText,
        'abstract' => 'Fictional abstract about ' . $marker . ' used only by the verification script.',
        'keywords' => 'verification, fictional',
        'date_submitted' => '2025-05-20',
        'final_defense_date' => '2025-04-10',
        'page_count' => '77',
    ];

    showStep('Submit the complete form without its security token.');
    $response = requestPage($client, $baseUrl, 'admin_thesis_form.php', $validFields);
    check($response['status'] === 403 && countTitle($pdo, $title) === 0, 'Missing token refused and nothing saved');

    showStep('Create the record unpublished and check where it appears.');
    $form = requestPage($client, $baseUrl, 'admin_thesis_form.php');
    $response = requestPage($client, $baseUrl, 'admin_thesis_form.php', array_merge($validFields, ['csrf_token' => tokenFrom($form)]));
    check($response['status'] === 303 && preg_match('/Location: admin_thesis\.php\?id=(\d+)/', $response['headers'], $matches) === 1, 'Valid form redirects to the new record');
    $createdThesisId = (int) $matches[1];
    check(countTitle($pdo, $title) === 1, 'Exactly one record saved');
    $recordPage = requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $createdThesisId);
    check($recordPage['status'] === 200 && strpos($recordPage['body'], 'Record created.') !== false
        && strpos($recordPage['body'], 'Unpublished') !== false && strpos($recordPage['body'], $adviserText) !== false, 'RDPO page shows the message, status and adviser');
    check(strpos(requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $createdThesisId)['body'], 'Record created.') === false, 'Saved message appears only once');
    check(strpos(requestPage($client, $baseUrl, 'admin.php?q=' . $marker)['body'], $marker) !== false, 'RDPO list search finds the unpublished record');
    $public = newClient(); // a separate visitor with no login at all
    check(strpos(requestPage($public, $baseUrl, 'index.php?q=' . $marker)['body'], 'No theses match') !== false, 'Public search does not show the unpublished record');
    check(requestPage($public, $baseUrl, 'thesis.php?id=' . $createdThesisId)['status'] === 404, 'Public page answers 404 for the unpublished record');

    showStep('Publish the record and check the public pages.');
    $response = requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $createdThesisId, ['csrf_token' => tokenFrom($recordPage), 'action' => 'publish']);
    check($response['status'] === 303, 'Publish redirects back to the record');
    $publicSearch = requestPage($public, $baseUrl, 'index.php?q=' . $marker);
    check(strpos($publicSearch['body'], 'Showing 1-1 of 1 thesis') !== false, 'Public search finds the published record');
    check(strpos(requestPage($public, $baseUrl, 'index.php?q=Synthetic+Verifier+Two+' . $marker)['body'], 'Showing 1-1 of 1 thesis') !== false, 'Public search also matches author names');
    $publicPage = requestPage($public, $baseUrl, 'thesis.php?id=' . $createdThesisId);
    check($publicPage['status'] === 200 && strpos($publicPage['body'], '&lt;b&gt;bold?&lt;/b&gt;') !== false
        && strpos($publicPage['body'], '<b>bold?</b>') === false, 'Public page shows the HTML-looking title as plain text');
    check(strpos($publicPage['body'], $adviserText) === false && strpos($publicPage['body'], '2025-05-20') === false
        && strpos($publicPage['body'], 'May 20, 2025') === false, 'Public page hides the adviser and date submitted');
    check(strpos($publicPage['body'], 'April 10, 2025') !== false && strpos($publicPage['body'], 'Synthetic Verifier Two') !== false, 'Public page shows the defense date and authors');
    check(strpos($publicPage['headers'], 'scholar_session') === false, 'Public pages set no session cookie');

    showStep('Edit the abstract and authors, then save the same values again.');
    $form = requestPage($client, $baseUrl, 'admin_thesis_form.php?id=' . $createdThesisId);
    check($form['status'] === 200 && strpos($form['body'], 'Synthetic Verifier One' . "\n" . 'Synthetic Verifier Two') !== false, 'Edit form shows authors one per line');
    $editedFields = array_merge($validFields, [
        'abstract' => 'Corrected fictional abstract about ' . $marker . '.',
        'authors' => "Synthetic Verifier Two\nSynthetic Verifier One\nSynthetic Verifier Three",
    ]);
    $response = requestPage($client, $baseUrl, 'admin_thesis_form.php?id=' . $createdThesisId, array_merge($editedFields, ['csrf_token' => tokenFrom($form)]));
    check($response['status'] === 303, 'Edit saves and redirects');
    check(strpos(requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $createdThesisId)['body'], 'Record saved.') !== false, 'Edit shows the saved message');
    $authorRows = $pdo->prepare('SELECT author_name FROM thesis_authors WHERE thesis_id = :id ORDER BY author_order');
    $authorRows->execute(['id' => $createdThesisId]);
    check($authorRows->fetchAll(PDO::FETCH_COLUMN) === ['Synthetic Verifier Two', 'Synthetic Verifier One', 'Synthetic Verifier Three'], 'Authors saved in the new order');
    $audit = $pdo->prepare("SELECT changed_fields FROM audit_logs WHERE entity_type = 'thesis' AND entity_id = :id AND action_code = 'thesis.updated'");
    $audit->execute(['id' => $createdThesisId]);
    check($audit->fetchColumn() === 'abstract, authors', 'Audit entry lists exactly the changed field names');
    $form = requestPage($client, $baseUrl, 'admin_thesis_form.php?id=' . $createdThesisId);
    requestPage($client, $baseUrl, 'admin_thesis_form.php?id=' . $createdThesisId, array_merge($editedFields, ['csrf_token' => tokenFrom($form)]));
    check(strpos(requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $createdThesisId)['body'], 'No changes were made.') !== false, 'Saving without changes says so');

    showStep('Send an unknown action, then an action without the security token.');
    $recordPage = requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $createdThesisId);
    check(requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $createdThesisId, ['csrf_token' => tokenFrom($recordPage), 'action' => 'delete'])['status'] === 422, 'Unknown action refused');
    check(requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $createdThesisId, ['action' => 'archive'])['status'] === 403, 'Action without token refused');
    check(countTitle($pdo, $title) === 1, 'The record still exists');

    showStep('Archive the record, try publishing it while archived, then restore it.');
    requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $createdThesisId, ['csrf_token' => tokenFrom($recordPage), 'action' => 'archive']);
    check(strpos(requestPage($public, $baseUrl, 'index.php?q=' . $marker)['body'], 'No theses match') !== false, 'Archived record leaves the public search');
    check(requestPage($public, $baseUrl, 'thesis.php?id=' . $createdThesisId)['status'] === 404, 'Archived record public page answers 404');
    check(strpos(requestPage($client, $baseUrl, 'admin.php?q=' . $marker)['body'], 'No records match') !== false, 'Archived record leaves the default RDPO list');
    check(strpos(requestPage($client, $baseUrl, 'admin.php?status=archived&q=' . $marker)['body'], $marker) !== false, 'Archived filter still finds it');
    requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $createdThesisId, ['csrf_token' => tokenFrom($recordPage), 'action' => 'publish']);
    check(strpos(requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $createdThesisId)['body'], 'Restore this record before') !== false, 'Publishing an archived record is explained, not done');
    requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $createdThesisId, ['csrf_token' => tokenFrom($recordPage), 'action' => 'restore']);
    check(requestPage($public, $baseUrl, 'thesis.php?id=' . $createdThesisId)['status'] === 200, 'Restored record is public again because it was published before');
    unset($public);

    showStep('Check the audit history of the record.');
    $audit = $pdo->prepare("SELECT action_code, actor_user_id FROM audit_logs WHERE entity_type = 'thesis' AND entity_id = :id ORDER BY id");
    $audit->execute(['id' => $createdThesisId]);
    $events = $audit->fetchAll();
    $actionCodes = [];
    $allByAdmin = true;
    foreach ($events as $event) {
        $actionCodes[] = $event['action_code'];
        if ((int) $event['actor_user_id'] !== $adminId) {
            $allByAdmin = false;
        }
    }
    check($actionCodes === ['thesis.created', 'thesis.published', 'thesis.updated', 'thesis.archived', 'thesis.restored'], 'History lists created, published, updated, archived, restored in order');
    check($allByAdmin, 'Every event names the RDPO account that did it');
    check(strpos(requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $createdThesisId)['body'], 'Changed: abstract, authors') !== false, 'RDPO page shows the history');

    showStep('Save and publish a record with no abstract and check how each page shows it.');
    $noAbstractTitle = 'Fictional record without an abstract ' . $marker; // the marker lets cleanup remove it
    $form = requestPage($client, $baseUrl, 'admin_thesis_form.php');
    check(strpos($form['body'], 'Abstract <span class="text-secondary small">(optional)</span>') !== false, 'Form labels the abstract as optional');
    $response = requestPage($client, $baseUrl, 'admin_thesis_form.php', array_merge($validFields, [
        'csrf_token' => tokenFrom($form),
        'title' => $noAbstractTitle,
        'abstract' => '',
        'is_public' => '1',
    ]));
    check($response['status'] === 303 && preg_match('/Location: admin_thesis\.php\?id=(\d+)/', $response['headers'], $matches) === 1, 'Record with no abstract is saved');
    $noAbstractId = (int) $matches[1];
    $savedAbstract = $pdo->prepare('SELECT abstract FROM theses WHERE id = :id');
    $savedAbstract->execute(['id' => $noAbstractId]);
    check($savedAbstract->fetchColumn() === null, 'The empty abstract is stored as NULL');
    check(strpos(requestPage($client, $baseUrl, 'admin_thesis.php?id=' . $noAbstractId)['body'], 'No abstract recorded.') !== false, 'RDPO page says no abstract is recorded');
    $public = newClient();
    check(strpos(requestPage($public, $baseUrl, 'thesis.php?id=' . $noAbstractId)['body'], 'No abstract recorded.') !== false, 'Public page says no abstract is recorded');
    check(strpos(requestPage($public, $baseUrl, 'index.php?q=' . urlencode('without an abstract ' . $marker))['body'], 'Showing 1-1 of 1 thesis') !== false, 'Public search still finds the record');
    unset($public);

    $response = requestPage($client, $baseUrl, 'logout.php', ['csrf_token' => tokenFrom($recordPage)]);
    check($response['status'] === 303, 'Logout succeeds');
} catch (Throwable $exception) {
    $failed = true;
    if ($exception instanceof PDOException) {
        reportFailure($exception); // keep unexpected database details in the private error handler
    } else {
        fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    }
} finally {
    // remove only what this script created, in the order the foreign keys require:
    // audit rows -> authors -> records -> accounts -> made-up role
    echo "\nCLEANUP: Remove this script's records, audit rows, accounts and made-up role.\n";
    putenv('SCHOLAR_ADMIN_PASSWORD');
    $userIds = $pdo->prepare('SELECT id FROM users WHERE username IN (:admin_username, :norole_username)');
    $userIds->execute(['admin_username' => $username, 'norole_username' => $noRoleUsername]);
    foreach ($userIds->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        // records made by this account (the title marker makes sure no other record can match)
        $thesisIds = $pdo->prepare('SELECT id FROM theses WHERE created_by = :user_id AND title LIKE :marker');
        $thesisIds->execute(['user_id' => $userId, 'marker' => '%' . $marker . '%']);
        foreach ($thesisIds->fetchAll(PDO::FETCH_COLUMN) as $thesisId) {
            $pdo->prepare("DELETE FROM audit_logs WHERE entity_type = 'thesis' AND entity_id = :id")->execute(['id' => $thesisId]);
            $pdo->prepare('DELETE FROM thesis_authors WHERE thesis_id = :id')->execute(['id' => $thesisId]);
            $pdo->prepare('DELETE FROM theses WHERE id = :id')->execute(['id' => $thesisId]);
        }
        $pdo->prepare("DELETE FROM audit_logs WHERE actor_user_id = :actor_id OR (entity_type = 'user' AND entity_id = :entity_id)")
            ->execute(['actor_id' => $userId, 'entity_id' => $userId]);
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
    }
    $pdo->prepare('DELETE FROM roles WHERE code = :code')->execute(['code' => $noRoleCode]);
    unset($client); // PHP releases CurlHandle objects when no references remain
}
if ($failed) {
    exit(1); // terminal exit code 1 means the suite failed
}
echo "\nRESULT: Thesis record tests passed.\n";
exit(0);
