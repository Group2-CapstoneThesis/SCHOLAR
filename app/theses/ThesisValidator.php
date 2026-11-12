<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Theses;

// checks and cleans the thesis form before anything is saved
// the rules below are provisional development rules; RDPO's own field list (P-08) may change them
class ThesisValidator {
    // field name => label shown in error messages, like the required-field maps in the enrollment project
    private const LABELS = [
        'title' => 'Title',
        'authors' => 'Authors',
        'publication_year' => 'Year',
        'program' => 'Program or course',
        'department' => 'Department',
        'campus' => 'Campus',
        'adviser' => 'Thesis adviser',
        'abstract' => 'Abstract',
        'keywords' => 'Keywords',
        'date_submitted' => 'Date submitted',
        'final_defense_date' => 'Date of final defense',
        'page_count' => 'Number of pages',
    ];

    // returns ['values' => cleaned values ready to save, 'errors' => field name => message]
    // when 'errors' is empty the values are safe to pass to ThesisRecords
    // $includeVisibility is true on the create form only; on edit, publishing is a separate button
    public function validate(array $post, bool $includeVisibility): array {
        $errors = [];

        // ---- read every field as text first; a modified request could send an array instead ----
        $title = $this->readText($post, 'title', $errors);
        $authorRows = $this->readAuthorRows($post, $errors);
        $yearText = $this->readText($post, 'publication_year', $errors);
        $program = $this->readText($post, 'program', $errors);
        $department = $this->readText($post, 'department', $errors);
        $campus = $this->readText($post, 'campus', $errors);
        $adviser = $this->readText($post, 'adviser', $errors);
        $abstract = $this->readText($post, 'abstract', $errors);
        $keywordsText = $this->readText($post, 'keywords', $errors);
        $dateSubmitted = $this->readText($post, 'date_submitted', $errors);
        $finalDefenseDate = $this->readText($post, 'final_defense_date', $errors);
        $pageCountText = $this->readText($post, 'page_count', $errors);

        // ---- required one-line fields ----
        $this->checkLine($title, 'title', 500, true, $errors);
        $this->checkLine($program, 'program', 150, true, $errors);
        $this->checkLine($department, 'department', 150, false, $errors);

        // ---- campus: required, and only one of the five WCC campuses (user's request, 2026-11-09) ----
        if (!isset($errors['campus']) && !Campuses::isCampus($campus)) {
            $errors['campus'] = 'Choose the campus the thesis came from.';
        }
        $this->checkLine($adviser, 'adviser', 200, false, $errors);

        // ---- authors: one row per author (last name, given names, optional suffix), kept in the typed order ----
        // the name is saved in parts so the Registrar list can sort by last name (FB-03)
        // completely empty rows are skipped, so the spare rows on the form can be left blank
        $authors = [];
        if (!isset($errors['authors'])) {
            foreach ($authorRows as $row) {
                if ($row['given_names'] === '' && $row['last_name'] === '' && $row['suffix'] === '') {
                    continue;
                }
                $problem = $this->authorRowProblem($row);
                if ($problem !== '') {
                    // counted among the filled rows only, because the form shows the filled rows again without the empty ones
                    $errors['authors'] = 'Author ' . (count($authors) + 1) . ': ' . $problem;
                    break;
                }
                $suffix = $this->nullIfEmpty($row['suffix']);
                $authors[] = [
                    'given_names' => $row['given_names'],
                    'last_name' => $row['last_name'],
                    'suffix' => $suffix,
                    'full_name' => AuthorNames::fullName($row['given_names'], $row['last_name'], $suffix),
                ];
            }
        }
        if (!isset($errors['authors'])) {
            if (count($authors) === 0) {
                $errors['authors'] = 'Enter at least one author.';
            } elseif (count($authors) > 20) {
                $errors['authors'] = 'Enter at most 20 authors.';
            }
        }

        // ---- year: four digits, from 1950 up to next year ----
        $publicationYear = null;
        if (!isset($errors['publication_year'])) {
            $latestYear = (int) date('Y') + 1;
            if (!preg_match('/\A[0-9]{4}\z/', $yearText) || (int) $yearText < 1950 || (int) $yearText > $latestYear) {
                $errors['publication_year'] = 'Enter a four-digit year between 1950 and ' . $latestYear . '.';
            } else {
                $publicationYear = (int) $yearText; // cast only after the format check, so "20x4" can never become 20
            }
        }

        // ---- abstract: optional, several lines allowed ----
        // RDPO asked for this in User Review 1 (FB-01): old theses, especially scanned ones from before the 2000s,
        // often have no abstract to enter; when one is given, the length and character checks still apply
        if (!isset($errors['abstract']) && $abstract !== '') {
            if (mb_strlen($abstract) > 10000) {
                $errors['abstract'] = 'Abstract must be at most 10,000 characters.';
            } elseif ($this->hasControlCharacters($abstract, true)) {
                $errors['abstract'] = 'Abstract contains characters that cannot be saved.';
            }
        }

        // ---- keywords: optional, separated by commas; tidied into "a, b, c" ----
        $keywords = '';
        if (!isset($errors['keywords'])) {
            $keywordList = [];
            foreach (explode(',', $keywordsText) as $keyword) {
                $cleanKeyword = trim($keyword);
                if ($cleanKeyword !== '') {
                    $keywordList[] = $cleanKeyword;
                }
            }
            $keywords = implode(', ', $keywordList);
            if (mb_strlen($keywords) > 500 || $this->hasControlCharacters($keywords, false)) {
                $errors['keywords'] = 'Keywords must be one line of at most 500 characters.';
            }
        }

        // ---- optional dates in the browser's date format, YYYY-MM-DD ----
        $dateSubmittedValue = $this->checkDate($dateSubmitted, 'date_submitted', $errors);
        $finalDefenseValue = $this->checkDate($finalDefenseDate, 'final_defense_date', $errors);
        // the final defense comes before the final submission, so a later defense date is probably a typing mistake
        // comparing "YYYY-MM-DD" text works because the parts are ordered from largest to smallest
        if ($dateSubmittedValue !== null && $finalDefenseValue !== null && $finalDefenseValue > $dateSubmittedValue) {
            $errors['final_defense_date'] = 'The final defense date should be on or before the date submitted.';
        }

        // ---- optional page count ----
        $pageCount = null;
        if (!isset($errors['page_count']) && $pageCountText !== '') {
            if (!preg_match('/\A[0-9]{1,4}\z/', $pageCountText) || (int) $pageCountText < 1 || (int) $pageCountText > 2000) {
                $errors['page_count'] = 'Number of pages must be a whole number from 1 to 2000.';
            } else {
                $pageCount = (int) $pageCountText;
            }
        }

        // ---- visibility checkbox: browsers send "1" when ticked and nothing at all when not ticked ----
        $isPublic = false;
        if ($includeVisibility && isset($post['is_public']) && $post['is_public'] === '1') {
            $isPublic = true;
        }

        // optional text fields are saved as NULL when left empty, so "no adviser" and "" mean the same thing
        $values = [
            'title' => $title,
            'authors' => $authors,
            'publication_year' => $publicationYear,
            'program' => $program,
            'department' => $this->nullIfEmpty($department),
            'campus' => $campus,
            'adviser' => $this->nullIfEmpty($adviser),
            'abstract' => $this->nullIfEmpty($abstract),
            'keywords' => $this->nullIfEmpty($keywords),
            'date_submitted' => $dateSubmittedValue,
            'final_defense_date' => $finalDefenseValue,
            'page_count' => $pageCount,
            'is_public' => $isPublic,
        ];
        return ['values' => $values, 'errors' => $errors];
    }

    // the text the form should show again after a failed save (never re-run through the rules)
    // authors go back as rows of ['last_name' => ..., 'given_names' => ..., 'suffix' => ...], like the form's author rows
    public function formValuesFrom(array $post): array {
        $formValues = [];
        foreach (array_keys(self::LABELS) as $field) {
            if ($field === 'authors') {
                continue; // the author rows are read separately below
            }
            $formValues[$field] = '';
            if (isset($post[$field]) && is_string($post[$field])) {
                $formValues[$field] = $post[$field];
            }
        }
        // only the filled author rows come back; the page adds its own empty spare rows below them
        $ignoredErrors = []; // a broken author list simply shows empty rows again
        $formValues['authors'] = [];
        foreach ($this->readAuthorRows($post, $ignoredErrors) as $row) {
            if ($row['last_name'] !== '' || $row['given_names'] !== '' || $row['suffix'] !== '') {
                $formValues['authors'][] = $row;
            }
        }
        $formValues['is_public'] = isset($post['is_public']) && $post['is_public'] === '1';
        return $formValues;
    }

    // reads one form field; a missing field becomes '', a non-text value is recorded as an error
    // passed by reference (&$errors) so the helper can add to the caller's error list
    private function readText(array $post, string $field, array &$errors): string {
        if (!isset($post[$field])) {
            return '';
        }
        // mb_check_encoding rejects broken text that is not valid UTF-8, which MySQL would refuse to store
        if (!is_string($post[$field]) || !mb_check_encoding($post[$field], 'UTF-8')) {
            $errors[$field] = self::LABELS[$field] . ' was sent in an unexpected format.';
            return '';
        }
        return trim($post[$field]);
    }

    // reads the three author lists the form sends: author_last_name[], author_given_names[] and author_suffix[]
    // the same position in each list is one author, e.g. author_last_name[1] and author_given_names[1] belong together
    // returns a list of rows ['last_name' => ..., 'given_names' => ..., 'suffix' => ...], trimmed
    private function readAuthorRows(array $post, array &$errors): array {
        $lists = [];
        foreach (['last_name', 'given_names', 'suffix'] as $part) {
            $field = 'author_' . $part;
            $lists[$part] = [];
            if (!isset($post[$field])) {
                continue;
            }
            // each list must be a plain list of text; a modified request could send nested arrays or far too many rows
            if (!is_array($post[$field]) || count($post[$field]) > 50) {
                $errors['authors'] = 'Authors were sent in an unexpected format.';
                return [];
            }
            // array_values() renumbers the list 0, 1, 2, ... in case a modified request sent its own keys
            foreach (array_values($post[$field]) as $value) {
                if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                    $errors['authors'] = 'Authors were sent in an unexpected format.';
                    return [];
                }
                $lists[$part][] = trim($value);
            }
        }

        // the longest list decides how many rows there are; a missing entry counts as empty
        $rowCount = max(count($lists['last_name']), count($lists['given_names']), count($lists['suffix']));
        $rows = [];
        for ($index = 0; $index < $rowCount; $index++) {
            $row = [];
            foreach (['last_name', 'given_names', 'suffix'] as $part) {
                $row[$part] = '';
                if (isset($lists[$part][$index])) {
                    $row[$part] = $lists[$part][$index];
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    // returns '' when one author's row can be saved, or the reason it cannot
    private function authorRowProblem(array $row): string {
        if ($row['last_name'] === '') {
            return 'enter the last name.';
        }
        if ($row['given_names'] === '') {
            return 'enter the first name.';
        }
        foreach ($row as $part) {
            if ($this->hasControlCharacters($part, false)) {
                return 'each name part must be a single line of ordinary text.';
            }
        }
        if (mb_strlen($row['suffix']) > 20) {
            return 'the suffix must be at most 20 characters (for example Jr. or III).';
        }
        // the full name is stored too (author_name holds at most 200 characters), so the parts together must fit
        $fullName = AuthorNames::fullName($row['given_names'], $row['last_name'], $row['suffix']);
        if (mb_strlen($fullName) > 200) {
            return 'the full name must be at most 200 characters.';
        }
        return '';
    }

    // shared checks for one-line text: required or optional, maximum length, no hidden control characters
    private function checkLine(string $value, string $field, int $maximumLength, bool $required, array &$errors): void {
        if (isset($errors[$field])) {
            return; // already failed the shape check in readText()
        }
        if ($value === '') {
            if ($required) {
                $errors[$field] = self::LABELS[$field] . ' is required.';
            }
            return;
        }
        // mb_strlen counts characters, so "ñ" counts as one instead of the two bytes it uses
        if (mb_strlen($value) > $maximumLength) {
            $errors[$field] = self::LABELS[$field] . ' must be at most ' . $maximumLength . ' characters.';
        } elseif ($this->hasControlCharacters($value, false)) {
            $errors[$field] = self::LABELS[$field] . ' must be a single line of ordinary text.';
        }
    }

    // returns the date as "YYYY-MM-DD", or null when the field was left empty
    private function checkDate(string $value, string $field, array &$errors): ?string {
        if (isset($errors[$field]) || $value === '') {
            return null;
        }
        // the pattern checks the shape; checkdate() then rejects impossible dates such as 2025-02-30
        if (!preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $parts)
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[1] < 1950) {
            $errors[$field] = self::LABELS[$field] . ' must be a real date (YYYY-MM-DD) from 1950 onward.';
            return null;
        }
        return $value;
    }

    // control characters are invisible bytes such as NUL or ESC that do not belong in thesis text
    // multi-line fields may still contain tabs and line breaks
    private function hasControlCharacters(string $value, bool $allowLineBreaks): bool {
        if ($allowLineBreaks) {
            return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1;
        }
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    private function nullIfEmpty(string $value): ?string {
        if ($value === '') {
            return null;
        }
        return $value;
    }
}
