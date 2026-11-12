// fills in the AI relevance explanation of each search result (public/index.php) after the page has shown
// it asks relevance.php, which sends ONLY the query and that thesis's abstract to OpenRouter; the API key never
// reaches the browser. Results without an answer simply keep their hidden box, so the page works the same without AI.
// everything from the server is placed with textContent, never as HTML.
(function () {
    'use strict';
    var results = document.getElementById('results');
    if (!results || !window.fetch) {
        return;
    }
    var query = results.getAttribute('data-query') || '';
    if (query === '') {
        return;
    }
    var cards = Array.prototype.slice.call(results.querySelectorAll('article[data-thesis-id]'));
    var waiting = cards.filter(function (card) { return card.querySelector('[data-explain]') !== null; });
    var PARALLEL = 3; // a few at a time, so OpenRouter and the rate limit are not hit with ten requests at once

    function show(card, data) {
        var box = card.querySelector('[data-explain]');
        if (!box || !data || data.status !== 'ok' || typeof data.explanation !== 'string' || data.explanation === '') {
            return; // unavailable, no abstract, or an unexpected answer: leave the box hidden
        }
        box.querySelector('[data-explain-text]').textContent = data.explanation;
        var concepts = box.querySelector('[data-explain-concepts]');
        concepts.textContent = '';
        if (Array.isArray(data.matched_concepts) && data.matched_concepts.length > 0) {
            var label = document.createElement('span');
            label.className = 'text-secondary me-1';
            label.textContent = 'Matching concepts:';
            concepts.appendChild(label);
            data.matched_concepts.forEach(function (concept) {
                if (typeof concept !== 'string') {
                    return;
                }
                var badge = document.createElement('span');
                badge.className = 'badge ai-concept me-1';
                badge.textContent = concept;
                concepts.appendChild(badge);
            });
        }
        box.hidden = false;
    }

    function next() {
        var card = waiting.shift();
        if (!card) {
            return;
        }
        var address = 'relevance.php?q=' + encodeURIComponent(query) + '&id=' + encodeURIComponent(card.getAttribute('data-thesis-id'));
        fetch(address, { headers: { 'Accept': 'application/json' }, credentials: 'omit' })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) { show(card, data); })
            .catch(function () { /* network problem: keep the result without an explanation */ })
            .then(next);
    }

    // ---- the AI overview above the results ----
    var UNAVAILABLE = 'The AI overview is not available right now. The theses below are still ranked by their relevance score.';

    function titleOf(id) {
        var link = document.querySelector('#thesis-' + id + ' .result-title a');
        return link ? link.textContent : 'Result ' + id;
    }

    // writes the overview text, turning [1], [2] ... into links to the matching result card on this page
    function writeOverview(paragraph, text, numberedIds) {
        paragraph.textContent = '';
        paragraph.classList.remove('text-secondary');
        text.split(/(\[\d{1,2}\])/).forEach(function (part) {
            var reference = /^\[(\d{1,2})\]$/.exec(part);
            var id = reference ? numberedIds[parseInt(reference[1], 10) - 1] : undefined;
            if (id === undefined) {
                paragraph.appendChild(document.createTextNode(part));
                return;
            }
            var link = document.createElement('a');
            link.className = 'ai-ref';
            link.href = '#thesis-' + id;
            link.title = titleOf(id);
            link.textContent = part;
            paragraph.appendChild(link);
        });
    }

    function loadOverview(done) {
        var panel = document.getElementById('ai-overview');
        var ids = panel ? panel.getAttribute('data-ids') : '';
        var paragraph = panel ? panel.querySelector('[data-overview-text]') : null;
        if (!panel || !ids || !paragraph || !paragraph.querySelector('.spinner-border')) {
            done();
            return;
        }
        var failed = function () {
            paragraph.textContent = UNAVAILABLE;
            paragraph.classList.add('text-secondary');
        };
        fetch('relevance.php?mode=overview&q=' + encodeURIComponent(query) + '&ids=' + encodeURIComponent(ids),
            { headers: { 'Accept': 'application/json' }, credentials: 'omit' })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) {
                if (!data || data.status !== 'ok' || typeof data.overview !== 'string' || !Array.isArray(data.numbered_ids)) {
                    failed();
                    return;
                }
                writeOverview(paragraph, data.overview, data.numbered_ids);
                // the numbered list of the theses the overview refers to, with their titles from this page
                var sources = panel.querySelector('[data-overview-sources]');
                var mostRelevant = Array.isArray(data.most_relevant) ? data.most_relevant : [];
                data.numbered_ids.forEach(function (id) {
                    var item = document.createElement('li');
                    var link = document.createElement('a');
                    link.href = '#thesis-' + id;
                    link.textContent = titleOf(id);
                    item.appendChild(link);
                    if (mostRelevant.indexOf(id) !== -1) {
                        item.className = 'most-relevant';
                        var tag = document.createElement('span');
                        tag.className = 'badge text-bg-warning ms-2';
                        tag.textContent = 'Most relevant';
                        item.appendChild(tag);
                    }
                    sources.appendChild(item);
                });
                sources.hidden = data.numbered_ids.length === 0;
            })
            .catch(failed)
            .then(done);
    }

    // the overview first (it is at the top of the page), then the explanation of each result
    loadOverview(function () {
        for (var i = 0; i < PARALLEL; i++) {
            next();
        }
    });
})();
