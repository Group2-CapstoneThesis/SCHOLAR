<?php
declare(strict_types=1); // keep typed values strict

use Scholar\Search\QueryInterpreter;
use Scholar\Search\SearchIndex;
use Scholar\SiteVisits;
use Scholar\Theses\Campuses;
use Scholar\Theses\ThesisSearch;

// the public research search page, similar to SSRN's: search and filter published theses, metadata only
// no login and no session: visitors get no cookie, and this page can never show RDPO-only fields
// (ThesisSearch's public mode only selects PublicCatalog::SEARCH_COLUMNS from published, non-archived records)
require_once __DIR__ . '/../app/bootstrap.php';

// the search form uses GET, so results can be bookmarked; nothing on this page changes data
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
header('X-Content-Type-Options: nosniff'); // the browser must treat this page only as HTML

$pdo = connectDatabase();
SiteVisits::countVisit($pdo); // one visit per person per 30 minutes, not per page (M12, FB-06)
$filters = ThesisSearch::filtersFromQuery($_GET, false); // false = public mode, no status filter
// with words typed, the search is hybrid: word matches plus records close in meaning (M14);
// SearchIndex::create() is null when semantic search is switched off in .env, which leaves the word search
$search = new ThesisSearch($pdo, SearchIndex::create($pdo));
$filterOptions = $search->filterOptions(false);

// a search typed as a sentence ("research conducted by IT", "find me a research related to flood monitoring") is turned
// into its topic plus the same filters the dropdowns offer (QueryInterpreter); $filters keeps exactly what the visitor typed
// (for the search box, page links and the AI), $searchFilters is what is actually searched.
// ?exact=1 ("Search your exact words instead") skips the interpretation
$exactWords = isset($_GET['exact']) && $_GET['exact'] === '1';
$searchFilters = $filters;
$interpretation = null;
if ($filters['q'] !== '' && !$exactWords) {
    $interpretation = QueryInterpreter::interpret($filters['q'], $filterOptions['programs']);
    if ($interpretation['changed']) {
        $searchFilters['q'] = $interpretation['topic'];
        // a dropdown the visitor set themselves always wins over what the sentence says
        if ($filters['program'] === '' && $interpretation['program'] !== null) {
            $searchFilters['program'] = $interpretation['program'];
        }
        if ($filters['year'] === null && $interpretation['year'] !== null) {
            $searchFilters['year'] = $interpretation['year'];
        }
        if ($filters['campus'] === '' && $interpretation['campus'] !== null) {
            $searchFilters['campus'] = $interpretation['campus'];
        }
        if (!isset($_GET['sort']) && $interpretation['sort'] !== null) {
            $searchFilters['sort'] = $interpretation['sort'];
        }
        // nothing left to search for by words ("research conducted by IT"): list the filtered records, newest first
        if ($searchFilters['q'] === '' && $searchFilters['sort'] === 'relevance') {
            $searchFilters['sort'] = 'newest';
        }
    } else {
        $interpretation = null;
    }
}
$results = $search->search($searchFilters, false);

// "Topic: flood monitoring · Program: BS Information Technology" - shown above the results when a sentence was interpreted
$understoodParts = [];
if ($interpretation !== null) {
    if ($searchFilters['q'] !== '') {
        $understoodParts[] = ['Topic', $searchFilters['q']];
    }
    if ($searchFilters['program'] !== $filters['program']) {
        $understoodParts[] = ['Program', $searchFilters['program']];
    }
    if ($searchFilters['year'] !== $filters['year']) {
        $understoodParts[] = ['Year', (string) $searchFilters['year']];
    }
    if ($searchFilters['campus'] !== $filters['campus']) {
        $understoodParts[] = ['Campus', $searchFilters['campus']];
    }
    if ($interpretation['sort'] !== null && !isset($_GET['sort'])) {
        $understoodParts[] = ['Order', $interpretation['sort'] === 'newest' ? 'Newest first' : 'Oldest first'];
    }
}
// link back to the same search without interpreting the sentence
$exactWordsLink = 'index.php?' . ThesisSearch::queryString($filters, 1) . '&exact=1';
// page links keep "exact words" when it was chosen
$pageLinkSuffix = $exactWords ? '&exact=1' : '';

$firstShown = 0;
$lastShown = 0;
if ($results['total'] > 0) {
    $firstShown = ($results['page'] - 1) * ThesisSearch::RESULTS_PER_PAGE + 1;
    $lastShown = $firstShown + count($results['rows']) - 1;
}
// the empty value means "the default order": Best match when words are typed, otherwise newest first
// (ThesisSearch::defaultSort), so typing words and pressing Search always gives the best matches first
$sortOptions = ['' => 'Newest first', 'oldest' => 'Oldest first', 'title' => 'Title A-Z'];
if ($filters['q'] !== '') {
    $sortOptions = ['' => 'Best match', 'newest' => 'Newest first', 'oldest' => 'Oldest first', 'title' => 'Title A-Z'];
}
$selectedSort = $filters['sort'];
if ($filters['sort'] === ThesisSearch::defaultSort($filters['q'])) {
    $selectedSort = '';
}

// a search is "active" when the visitor typed or picked something, which changes the heading text
$isFiltered = $filters['q'] !== '' || $filters['year'] !== null || $filters['program'] !== '' || $filters['campus'] !== '';

// AI relevance explanations are asked for only when words were typed and OPENROUTER_API_KEY / OPENROUTER_MODEL are set;
// the page itself never calls OpenRouter: public/assets/search.js asks relevance.php once per result after the page shows,
// so results and their scores appear at once and simply stay without an explanation if OpenRouter is unavailable
// (a sentence with no topic left, such as "research conducted by IT", is only a filtered list, so there is nothing to explain)
$hasTopic = $searchFilters['q'] !== '';
$explainResults = $hasTopic && loadConfiguration()['openrouter']['enabled'];

// shortens a long abstract for the results list without cutting a word in half
// example: a 900-character abstract becomes its first ~280 characters followed by "..."
function shortAbstract(?string $abstract): string {
    if ($abstract === null) {
        return '';
    }
    if (mb_strlen($abstract) <= 280) {
        return $abstract;
    }
    $shortened = mb_substr($abstract, 0, 280);
    $lastSpace = mb_strrpos($shortened, ' '); // position of the last space, so the cut lands between words
    if ($lastSpace !== false) {
        $shortened = mb_substr($shortened, 0, $lastSpace);
    }
    return $shortened . '...';
}

// when the research was conducted: "March 2024" from the final defense date, otherwise just the year
function conductedDate(array $row): string {
    if (isset($row['final_defense_date']) && is_string($row['final_defense_date']) && $row['final_defense_date'] !== '') {
        $date = DateTime::createFromFormat('!Y-m-d', $row['final_defense_date']);
        if ($date !== false) {
            return $date->format('F Y');
        }
    }
    if ($row['publication_year'] !== null) {
        return (string) $row['publication_year'];
    }
    return '';
}

// "IoT, flood monitoring" becomes ['IoT', 'flood monitoring'] for the keyword badges
function keywordList(?string $keywords): array {
    if ($keywords === null || $keywords === '') {
        return [];
    }
    $list = [];
    foreach (explode(',', $keywords) as $keyword) {
        $cleanKeyword = trim($keyword);
        if ($cleanKeyword !== '') {
            $list[] = $cleanKeyword;
        }
    }
    return $list;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>S.C.H.O.L.A.R. research search</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="assets/scholar.css" rel="stylesheet">
</head>

<body class="bg-light">
<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="index.php">S.C.H.O.L.A.R. research search</a>
        <!-- goes to the RDPO records page: someone still logged in goes straight in, anyone else is sent to the login page -->
        <a class="btn btn-sm btn-outline-light" href="admin.php">RDPO login</a>
    </div>
</nav>

<main class="container py-4">
    <h1 class="h3">Find World Citi Colleges theses</h1>
    <p class="text-secondary">Search approved theses by title, author, keyword or topic. Only the approved details and abstract of each published thesis are shown here; full manuscripts are kept by the RDPO and are not available online.</p>

    <!-- search box and filters; the server checks every value again, so editing the address bar cannot break the page -->
    <form method="get" action="index.php" class="card card-body mb-4" role="search">
        <div class="row g-2 align-items-end">
            <!-- two rows on wide screens: search, year, program / campus, sort, button -->
            <div class="col-12 col-lg-6">
                <label for="q" class="form-label">Search</label>
                <input id="q" name="q" type="search" class="form-control" maxlength="200"
                       placeholder="e.g. flood monitoring, or &quot;research about a certain topic&quot;" value="<?= escapeHtml($filters['q']) ?>">
            </div>
            <div class="col-6 col-lg-3">
                <label for="year" class="form-label">Year</label>
                <select id="year" name="year" class="form-select">
                    <option value="">Any year</option>
                    <?php foreach ($filterOptions['years'] as $year): ?>
                        <option value="<?= escapeHtml((string) $year) ?>" <?php if ($filters['year'] === (int) $year): ?>selected<?php endif; ?>><?= escapeHtml((string) $year) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-3">
                <label for="program" class="form-label">Program</label>
                <select id="program" name="program" class="form-select">
                    <option value="">Any program</option>
                    <?php foreach ($filterOptions['programs'] as $program): ?>
                        <option value="<?= escapeHtml($program) ?>" <?php if ($filters['program'] === $program): ?>selected<?php endif; ?>><?= escapeHtml($program) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-4">
                <label for="campus" class="form-label">Campus</label>
                <select id="campus" name="campus" class="form-select">
                    <option value="">Any campus</option>
                    <?php foreach (Campuses::NAMES as $campusName): ?>
                        <option value="<?= escapeHtml($campusName) ?>" <?php if ($filters['campus'] === $campusName): ?>selected<?php endif; ?>><?= escapeHtml($campusName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-4">
                <label for="sort" class="form-label">Sort</label>
                <select id="sort" name="sort" class="form-select">
                    <?php foreach ($sortOptions as $value => $label): ?>
                        <option value="<?= escapeHtml((string) $value) ?>" <?php if ($selectedSort === (string) $value): ?>selected<?php endif; ?>><?= escapeHtml($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-lg-4">
                <button type="submit" class="btn btn-primary w-100">Search</button>
            </div>
        </div>
        <?php if ($isFiltered): ?>
            <div class="mt-2"><a href="index.php" class="small">Clear search</a></div>
        <?php endif; ?>
    </form>


    <?php if ($results['mode'] === 'words_fallback'): ?>
        <!-- semantic search (Ollama / Qdrant) did not answer, so only records containing the typed words are listed -->
        <div class="alert alert-warning small" role="status">
            Search by meaning is unavailable right now, so these results only include records containing your exact words.
        </div>
    <?php endif; ?>

    <?php if ($interpretation !== null): ?>
        <!-- how the typed sentence was understood, with a way back to the visitor's exact words -->
        <div class="understood small mb-3" role="status" data-understood>
            <span class="understood-label">Searching for</span>
            <?php if (count($understoodParts) === 0): ?>
                <span class="understood-chip">all theses</span>
            <?php endif; ?>
            <?php foreach ($understoodParts as [$partLabel, $partValue]): ?>
                <span class="understood-chip"><span class="text-secondary"><?= escapeHtml($partLabel) ?>:</span> <?= escapeHtml($partValue) ?></span>
            <?php endforeach; ?>
            <a href="<?= escapeHtml($exactWordsLink) ?>" class="ms-1 text-nowrap">Search your exact words instead</a>
        </div>
    <?php endif; ?>

    <?php if ($results['total'] === 0): ?>
        <!-- empty state with a suggestion, instead of a blank page -->
        <div class="alert alert-secondary">No theses match your search. Try fewer or different words, or clear the year, program and campus filters.</div>
    <?php else: ?>
        <?php if ($hasTopic): ?>
            <?php
            // the top results on this page that have an abstract, best first; only their abstracts go to the overview
            $overviewIds = [];
            foreach ($results['rows'] as $overviewRow) {
                if ($overviewRow['abstract'] !== null && $overviewRow['abstract'] !== '' && count($overviewIds) < 5) {
                    $overviewIds[] = (int) $overviewRow['id'];
                }
            }
            ?>
            <!-- the AI overview, above the theses: written by search.js from relevance.php?mode=overview, which sends
                 OpenRouter only the query and these abstracts (numbered, without titles); the titles are added here -->
            <section id="ai-overview" class="ai-overview mb-3" aria-live="polite" data-ids="<?= escapeHtml(implode(',', $overviewIds)) ?>">
                <h2 class="h6 ai-label mb-2">AI overview <span class="fw-normal text-secondary small">&middot; based only on the abstracts of the top results</span></h2>
                <?php if ($explainResults && count($overviewIds) > 0): ?>
                    <p class="mb-0" data-overview-text>
                        <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                        Generating an AI overview of these results&hellip;
                    </p>
                <?php elseif (count($overviewIds) === 0): ?>
                    <p class="mb-0 text-secondary" data-overview-text>No abstracts are recorded for these results, so there is nothing for the AI to summarise.</p>
                <?php else: ?>
                    <p class="mb-0 text-secondary" data-overview-text>The AI overview is not available right now. The theses below are still ranked by their relevance score.</p>
                <?php endif; ?>
                <ol class="ai-sources small mb-0 mt-2" data-overview-sources hidden></ol>
            </section>
        <?php endif; ?>

        <!-- aria-live lets screen readers announce the new result count after a search -->
        <p class="text-secondary small" aria-live="polite">
            Showing <?= $firstShown ?>-<?= $lastShown ?> of <?= $results['total'] ?> <?php if ($results['total'] === 1): ?>thesis<?php else: ?>theses<?php endif; ?>
        </p>

        <!-- data-query carries the typed words for search.js; it is escaped like every other value on this page -->
        <div id="results" data-query="<?= escapeHtml($filters['q']) ?>">
        <?php foreach ($results['rows'] as $row): ?>
            <?php
            $hasAbstract = $row['abstract'] !== null && $row['abstract'] !== '';
            $abstractPreview = $hasAbstract ? shortAbstract($row['abstract']) : '';
            $similarity = $row['similarity'] ?? null;
            $level = $row['relevance_level'] ?? null;
            ?>
            <!-- one result: approved metadata and abstract only. There is deliberately no View / Download button and no
                 link to any file: the title opens thesis.php, which also shows metadata and the abstract only -->
            <article class="card card-body mb-3 result-card" id="thesis-<?= (int) $row['id'] ?>" data-thesis-id="<?= (int) $row['id'] ?>">
                <h2 class="h5 mb-1 result-title"><a href="thesis.php?id=<?= (int) $row['id'] ?>"><?= escapeHtml($row['title']) ?></a></h2>
                <div class="small mb-1"><span class="visually-hidden">Authors: </span><?= escapeHtml(implode(', ', $row['authors'])) ?></div>
                <?php $conducted = conductedDate($row); ?>
                <!-- data-year, data-program and data-campus let tests/verify_public_search.php read each value exactly -->
                <div class="small text-secondary mb-2" data-year="<?= escapeHtml((string) $row['publication_year']) ?>"><?php if ($conducted !== ''): ?>Conducted <?= escapeHtml($conducted) ?> &middot; <?php endif; ?><span data-program><?= escapeHtml((string) $row['program']) ?></span> &middot; <span data-campus><?= escapeHtml((string) $row['campus']) ?></span></div>

                <?php if ($similarity !== null || (isset($row['match']) && $row['match'] === 'meaning')): ?>
                    <!-- the relevance score is Qdrant's own similarity (0-1) between the query and this record;
                         the AI explanation below never changes it or the order -->
                    <div class="relevance-row small mb-2" data-relevance>
                        <?php if ($level !== null): ?>
                            <span class="badge relevance-badge relevance-<?= escapeHtml(strtolower($level)) ?>" data-relevance-level><?= escapeHtml($level) ?> relevance</span>
                            <span class="relevance-score" data-relevance-score>Relevance score <?= escapeHtml(number_format((float) $similarity, 2)) ?></span>
                        <?php endif; ?>
                        <?php if (isset($row['match']) && $row['match'] === 'meaning'): ?>
                            <!-- found by meaning (semantic search): related, but it may not contain every typed word -->
                            <span class="badge text-bg-info" data-match="meaning">Similar topic</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- records without an abstract (optional since FB-01) say so instead of leaving a gap -->
                <?php if ($hasAbstract): ?>
                    <?php if ($abstractPreview === $row['abstract']): ?>
                        <p class="mb-2"><?= nl2br(escapeHtml($row['abstract'])) ?></p>
                    <?php else: ?>
                        <!-- a <details> block: the preview is shown, the full approved abstract opens in place -->
                        <details class="abstract-toggle mb-2">
                            <summary><span class="abstract-preview"><?= escapeHtml($abstractPreview) ?> </span><span class="toggle-more text-nowrap">Read full abstract</span><span class="toggle-less">Show less</span></summary>
                            <p class="mb-0 abstract-full"><?= nl2br(escapeHtml($row['abstract'])) ?></p>
                        </details>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="mb-2 small text-secondary fst-italic">No abstract recorded.</p>
                <?php endif; ?>

                <?php if ($explainResults && $hasAbstract): ?>
                    <!-- filled in by search.js from relevance.php (OpenRouter sees only the query and this abstract);
                         hidden until an explanation arrives, so nothing is shown when OpenRouter is unavailable -->
                    <section class="ai-explanation small mb-2" data-explain hidden aria-live="polite">
                        <div class="ai-label mb-1">AI relevance explanation <span class="fw-normal text-secondary">&middot; based on the abstract only</span></div>
                        <p class="mb-1" data-explain-text>Preparing an explanation&hellip;</p>
                        <div data-explain-concepts></div>
                    </section>
                <?php endif; ?>
                <div>
                    <?php foreach (keywordList($row['keywords']) as $keyword): ?>
                        <!-- each keyword links to a search for that keyword -->
                        <a href="index.php?<?= escapeHtml(http_build_query(['q' => $keyword])) ?>" class="badge text-bg-light border text-decoration-none"><?= escapeHtml($keyword) ?></a>
                    <?php endforeach; ?>
                </div>
            </article>
        <?php endforeach; ?>
        </div>

        <?php if ($results['pages'] > 1): ?>
            <nav aria-label="Result pages">
                <ul class="pagination flex-wrap">
                    <?php for ($pageNumber = 1; $pageNumber <= $results['pages']; $pageNumber++): ?>
                        <li class="page-item <?php if ($pageNumber === $results['page']): ?>active<?php endif; ?>">
                            <a class="page-link" href="index.php?<?= escapeHtml(ThesisSearch::queryString($filters, $pageNumber) . $pageLinkSuffix) ?>"><?= $pageNumber ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</main>
<?php if ($explainResults && $results['total'] > 0): ?>
    <script src="assets/search.js" defer></script>
<?php endif; ?>
</body>

</html>
