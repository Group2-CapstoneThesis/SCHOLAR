<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Search;

use Scholar\Theses\Campuses;

// understands a search typed as a sentence on the public search page, without calling any AI service
// examples:
//   "research conducted by IT"                      -> program BS Information Technology, no topic (list them all)
//   "find me a research related to flood monitoring" -> topic "flood monitoring"
//   "studies about stress of BS Psychology students in 2024" -> topic "stress", program BS Psychology, year 2024
//   "latest research about inventory in Antipolo"   -> topic "inventory", campus WCC Antipolo, newest first
// a program is only taken from the sentence when the wording says so ("by IT", "IT students", "from the Computer Science
// program", or a BS-prefixed name such as "BSIT"), so "research about nursing shifts" stays a topic about nursing shifts.
// the result never contains anything the visitor did not type; the filters are the same ones the dropdowns offer.
class QueryInterpreter {
    // words that only make a sentence polite or say "a research" - they never describe the topic
    private const FILLER_WORDS = [
        'a', 'an', 'the', 'and', 'or', 'of', 'on', 'in', 'at', 'to', 'for', 'from', 'by', 'with', 'into', 'onto', 'as',
        'about', 'regarding', 'concerning', 'related', 'relating', 'relate', 'relates', 'relevant', 'pertaining', 'involving',
        'that', 'which', 'who', 'whose', 'what', 'where', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'has', 'have', 'had',
        'this', 'these', 'those', 'any', 'some', 'all', 'me', 'my', 'i', 'im', "i'm", 'we', 'us', 'our', 'you', 'your',
        'please', 'kindly', 'can', 'could', 'would', 'will', 'do', 'does', 'did',
        'find', 'finding', 'show', 'give', 'get', 'search', 'searching', 'look', 'looking', 'list', 'display', 'recommend',
        'suggest', 'want', 'need', 'interested', 'help', 'there', 'here', 'something', 'anything', 'other', 'others',
        'research', 'researches', 'researched', 'study', 'studies', 'paper', 'papers', 'thesis', 'theses', 'dissertation',
        'dissertations', 'capstone', 'capstones', 'article', 'articles', 'topic', 'topics', 'title', 'titles', 'existing',
        'conducted', 'done', 'made', 'written', 'authored', 'submitted', 'published', 'focusing', 'focused', 'focuses', 'focus',
        'deals', 'dealing', 'tackles', 'tackling', 'discusses', 'discussing', 'similar', 'like', 'year', 'campus', 'wcc',
    ];

    // words after a program name that mark it as "who did the research"
    private const PROGRAM_AFTER = '(?:students?|program|programs|department|course|courses|majors?|graduates?|class|batch)';
    // words before a program name that mark it the same way ("of" and "in" are left out on purpose:
    // "the impact of business on ..." and "research in nursing care" describe a topic, not who did the research)
    private const PROGRAM_BEFORE = '(?:by|from|under|within)';

    // extra everyday names for a program subject (used only when that program exists in the records)
    private const EXTRA_NAMES = [
        'Information Technology' => ['info tech', 'infotech'],
        'Computer Science' => ['comsci', 'comp sci', 'compsci'],
        'Business Administration' => ['business', 'business admin'],
        'Psychology' => ['psych'],
        'Nursing' => ['nurses'],
    ];

    // place words for each campus; "QC" must be written in capitals so the letters "qc" inside other words never count
    private const CAMPUS_NAMES = [
        'WCC QC' => ['quezon city'],
        'WCC Antipolo' => ['antipolo'],
        'WCC Guimba' => ['guimba'],
        'WCC North Manila' => ['north manila'],
        'WCC Binalonan' => ['binalonan'],
    ];

    // $query is the trimmed search text; $programs is the list of program names that exist (ThesisSearch::filterOptions)
    // returns ['topic' => words left to search for, 'program' => ?string, 'year' => ?int, 'campus' => ?string,
    //          'sort' => ?string ('newest' or 'oldest'), 'changed' => true when the sentence was interpreted at all]
    public static function interpret(string $query, array $programs): array {
        $result = ['topic' => $query, 'program' => null, 'year' => null, 'campus' => null, 'sort' => null, 'changed' => false];
        $text = ' ' . $query . ' ';

        // "quoted words" mean exactly these words: nothing is interpreted
        if (preg_match('/\A\s*"[^"]+"\s*\z/u', $query)) {
            $result['topic'] = trim($query, " \"");
            $result['changed'] = $result['topic'] !== $query;
            return $result;
        }

        // ---- the program: "by IT", "IT students", "from the Computer Science department", "BSIT" ----
        foreach ($programs as $program) {
            if (!is_string($program) || $program === '') {
                continue;
            }
            foreach (self::programPatterns($program) as $pattern) {
                if (preg_match($pattern, $text, $match)) {
                    $result['program'] = $program;
                    $text = str_replace($match[0], ' ', $text);
                    break 2;
                }
            }
        }

        // ---- the campus: "in Antipolo", "at WCC QC", "Quezon City campus" ----
        foreach (self::CAMPUS_NAMES as $campus => $places) {
            $patterns = ['/(?:\b(?:in|at|from)\s+)?\b' . preg_quote($campus, '/') . '\b(?:\s+campus)?/iu'];
            foreach ($places as $place) {
                $patterns[] = '/(?:\b(?:in|at|from)\s+)?(?:\bwcc\s+)?\b' . preg_quote($place, '/') . '\b(?:\s+campus)?/iu';
            }
            if ($campus === 'WCC QC') {
                $patterns[] = '/(?:\b(?:in|at|from)\s+)?(?:\b[Ww][Cc][Cc]\s+)?\bQC\b(?:\s+campus)?/u';
            }
            foreach ($patterns as $pattern) {
                if (Campuses::isCampus($campus) && preg_match($pattern, $text, $match)) {
                    $result['campus'] = $campus;
                    $text = str_replace($match[0], ' ', $text);
                    break 2;
                }
            }
        }

        // ---- the year: one four-digit year such as "in 2024" or "conducted 2023" ----
        if (preg_match_all('/\b(19[5-9][0-9]|20[0-9]{2})\b/', $text, $years) && count(array_unique($years[1])) === 1) {
            $result['year'] = (int) $years[1][0];
            $text = preg_replace('/(?:\b(?:in|from|during|of|year)\s+)?\b' . $years[1][0] . '\b/i', ' ', $text) ?? $text;
        }

        // ---- the order: "latest", "newest", "most recent" / "oldest", "earliest" ----
        if (preg_match('/\b(?:the\s+)?(?:latest|newest|most\s+recent|recent)\b/i', $text, $match)) {
            $result['sort'] = 'newest';
            $text = str_replace($match[0], ' ', $text);
        } elseif (preg_match('/\b(?:the\s+)?(?:oldest|earliest)\b/i', $text, $match)) {
            $result['sort'] = 'oldest';
            $text = str_replace($match[0], ' ', $text);
        }

        // ---- the topic: what is left, without the filler words ----
        $words = preg_split('/[\s,;:!?.()]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $kept = [];
        foreach ($words as $word) {
            $plain = mb_strtolower(trim($word, "'\"-"));
            if ($plain === '' || in_array($plain, self::FILLER_WORDS, true)) {
                continue;
            }
            $kept[] = trim($word, "'\"");
        }
        $result['topic'] = implode(' ', $kept);
        $result['changed'] = $result['topic'] !== $query || $result['program'] !== null || $result['year'] !== null
            || $result['campus'] !== null || $result['sort'] !== null;
        return $result;
    }

    // the patterns that mean "this program did the research", for example for "BS Information Technology":
    // "BSIT" or "BS Information Technology" anywhere; "Information Technology" or "IT" only with "by"/"from"/... before it
    // or "students"/"program"/... after it. Short capital-letter names (IT, CS, BA) must be written in capitals.
    private static function programPatterns(string $program): array {
        $subject = trim(preg_replace('/\A(?:BS|B\.S\.|AB|BA|Bachelor\s+of\s+(?:Science|Arts)\s+in)\s+/i', '', $program) ?? $program);
        $initials = '';
        foreach (preg_split('/\s+/', $subject, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            if (!in_array(strtolower($word), ['of', 'and', 'in', 'the'], true)) {
                $initials .= mb_strtoupper(mb_substr($word, 0, 1));
            }
        }

        $patterns = [];
        // anywhere: the full program name, "BS" + initials (BSIT, BSCS, BSBA), and "BS" + subject
        $before = '(?:(?:\b(?:conducted|done|made|written|submitted)\s+)?\b' . self::PROGRAM_BEFORE . '\s+(?:the\s+)?)?';
        $after = '(?:\s+' . self::PROGRAM_AFTER . ')?';
        $patterns[] = '/' . $before . '\b' . preg_quote($program, '/') . '\b' . $after . '/iu';
        if (mb_strlen($initials) >= 2) {
            $patterns[] = '/' . $before . '\bBS\s?' . preg_quote($initials, '/') . '\b' . $after . '/iu';
        }

        // with wording around it: the subject and its everyday names (case does not matter)
        $names = [$subject];
        foreach (self::EXTRA_NAMES[$subject] ?? [] as $extra) {
            $names[] = $extra;
        }
        foreach ($names as $name) {
            $quoted = preg_quote($name, '/');
            $patterns[] = '/(?:\b(?:conducted|done|made|written|submitted)\s+)?\b' . self::PROGRAM_BEFORE . '\s+(?:the\s+)?\b' . $quoted . '\b' . $after . '/iu';
            $patterns[] = '/\b' . $quoted . '\s+' . self::PROGRAM_AFTER . '\b/iu';
        }

        // the short initials (IT, CS, BA), capitals only, with wording around them
        if (mb_strlen($initials) >= 2) {
            $quoted = preg_quote($initials, '/');
            $patterns[] = '/(?:\b(?i:conducted|done|made|written|submitted)\s+)?\b(?i:' . substr(self::PROGRAM_BEFORE, 3, -1) . ')\s+(?:(?i:the)\s+)?\b' . $quoted . '\b(?:\s+(?i:' . substr(self::PROGRAM_AFTER, 3, -1) . '))?/u';
            $patterns[] = '/\b' . $quoted . '\s+(?i:' . substr(self::PROGRAM_AFTER, 3, -1) . ')\b/u';
        }
        return $patterns;
    }
}
