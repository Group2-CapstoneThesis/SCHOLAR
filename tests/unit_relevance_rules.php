<?php
// WHAT THIS FILE DOES: checks the abstract-only rules of the AI relevance explanations (Scholar\Search\RelevanceExplainer)
// without calling OpenRouter: what the request contains, and how the model's answer is cleaned before it is shown.
// These are unit tests.

// STEP 1: Allow terminal use only and load the application.
// STEP 2: Build the OpenRouter request for a query and an abstract; it must hold ONLY those two texts (plus fixed instructions).
// STEP 3: Feed made-up model answers to parseAnswer() and check the guards (invented concepts, "not enough" notice, odd formats).
// STEP 4: Print the number of passed/failed cases and return an exit code (0 = all passed).

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

use Scholar\Search\RelevanceExplainer;
use Scholar\Search\RelevanceUnavailable;

$abstract = 'Water level sensors send flood readings to a web dashboard and text alerts to residents.';
$request = RelevanceExplainer::buildRequest('openai/gpt-4o-mini', 'flood monitoring', $abstract);
$userContent = json_decode($request['messages'][1]['content'], true);
$wholeRequest = json_encode($request);

$fenced = RelevanceExplainer::parseAnswer("```json\n{\"explanation\":\"It matches.\",\"matched_concepts\":[\"flood readings\",\"machine learning\",\"Web Dashboard\"],\"sufficient\":true}\n```", $abstract);
$insufficient = RelevanceExplainer::parseAnswer('{"explanation":"The abstract is very short.","matched_concepts":[],"sufficient":false}', $abstract);
$plainText = RelevanceExplainer::parseAnswer('This thesis is about flood sensors.', $abstract);
$htmlAnswer = RelevanceExplainer::parseAnswer('{"explanation":"<script>alert(1)</script>Relevant."}', $abstract);
$emptyThrows = false;
try {
    RelevanceExplainer::parseAnswer('{"explanation":""}', $abstract);
} catch (RelevanceUnavailable $exception) {
    $emptyThrows = true;
}

// the AI overview above the results: numbered abstracts only
$overviewRequest = RelevanceExplainer::buildOverviewRequest('openai/gpt-4o-mini', 'flood monitoring', [$abstract, 'Second abstract.', 'Third.', 'Fourth.', 'Fifth.', 'Sixth is never sent.']);
$overviewData = json_decode($overviewRequest['messages'][1]['content'], true);
$overview = RelevanceExplainer::parseOverview('{"overview":"Result [1] matches; [7] does not exist.","most_relevant":[1,7,1,"2"],"sufficient":true}', 5);
$overviewInsufficient = RelevanceExplainer::parseOverview('{"overview":"Too little to go on.","most_relevant":[],"sufficient":false}', 2);

$cases = [
    // ---- the overview ----
    ['Overview data holds exactly the query and the numbered abstracts', array_keys($overviewData) === ['search_query', 'abstracts']
        && array_keys($overviewData['abstracts'][0]) === ['number', 'abstract']],
    ['At most 5 abstracts are sent, numbered 1-5 in result order', count($overviewData['abstracts']) === 5
        && $overviewData['abstracts'][0] === ['number' => 1, 'abstract' => $abstract] && $overviewData['abstracts'][4]['number'] === 5],
    ['No title, author or file field is in the overview request', !preg_match('/"(title|author|authors|filename|storage_name)"|\.pdf/i', json_encode($overviewRequest))],
    ['A reference to a thesis that was not sent ([7]) is removed from the overview', $overview['overview'] === 'Result [1] matches; does not exist.'],
    ['Only valid, distinct numbers stay in most_relevant', $overview['most_relevant'] === [1]],
    ['"sufficient": false adds the overview notice', !$overviewInsufficient['sufficient'] && str_contains($overviewInsufficient['overview'], RelevanceExplainer::OVERVIEW_INSUFFICIENT_NOTICE)],
    // ---- what is sent ----
    ['The request has only model, messages, temperature and max_tokens', array_keys($request) === ['model', 'messages', 'temperature', 'max_tokens']],
    ['Exactly two messages: fixed instructions, then the data', count($request['messages']) === 2 && $request['messages'][0]['role'] === 'system' && $request['messages'][1]['role'] === 'user'],
    ['The data message holds exactly the search query and the abstract', is_array($userContent) && array_keys($userContent) === ['search_query', 'abstract']],
    ['The query and abstract are sent unchanged', $userContent['search_query'] === 'flood monitoring' && $userContent['abstract'] === $abstract],
    ['Nothing about files is in the request (no .pdf, storage or file name)', stripos($wholeRequest, '.pdf') === false && stripos($wholeRequest, 'storage') === false && stripos($wholeRequest, 'filename') === false],
    ['The instructions forbid inventing findings and require the "not enough" notice',
        str_contains($request['messages'][0]['content'], 'Do not invent') && str_contains($request['messages'][0]['content'], RelevanceExplainer::INSUFFICIENT_NOTICE)],
    ['A very long abstract is cut before sending', mb_strlen(json_decode(RelevanceExplainer::buildRequest('a/b', 'q', str_repeat('word ', 3000))['messages'][1]['content'], true)['abstract']) <= 6000],
    // ---- how the answer is cleaned ----
    ['JSON inside ```json fences is read', $fenced['explanation'] === 'It matches.' && $fenced['sufficient'] === true],
    ['A concept not found in the abstract is dropped; real ones are kept (any letter case)', $fenced['matched_concepts'] === ['flood readings', 'Web Dashboard']],
    ['"sufficient": false always adds the fixed notice', $insufficient['sufficient'] === false && str_contains($insufficient['explanation'], RelevanceExplainer::INSUFFICIENT_NOTICE)],
    ['A plain-text answer becomes the explanation', $plainText['explanation'] === 'This thesis is about flood sensors.' && $plainText['matched_concepts'] === []],
    ['HTML tags in the answer are removed', !str_contains($htmlAnswer['explanation'], '<script>')],
    ['An empty explanation is refused (the page then shows no explanation)', $emptyThrows],
];

$passed = 0;
$failed = 0;
foreach ($cases as $number => [$description, $result]) {
    echo 'STEPS ' . ($number + 1) . ": $description\n";
    if ($result === true) {
        echo "PASS: $description\n";
        $passed++;
    } else {
        echo "FAIL: $description\n";
        $failed++;
    }
}
echo "RESULT: $passed passed; $failed failed.\n";
exit($failed === 0 ? 0 : 1);
