<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Theses;

use DateTime;
use Smalot\PdfParser\Parser;

// reads the first pages of a WCC thesis PDF and suggests values for the new-record form
// everything happens on this server with the smalot/pdfparser library; no text is sent anywhere,
// and nothing read here is stored or logged except the field values RDPO decides to save
//
// it follows the institution's front-matter order:
//   title page      -> title, program ("Degree of" line), authors (after "by", or right after the program when
//                      there is no "by"), year (the month/year line)
//   approval sheet  -> adviser (the line above "Adviser"), date of final defense
//   acknowledgement -> skipped (may be more than one page)
//   dedication      -> skipped
//   abstract        -> abstract (until "Keywords:"), keywords; may continue onto the next page
//   table of contents comes after the abstract and is never read as part of it
// sections are found by their headings, not by fixed page numbers, so an extra or missing page still works
class ThesisMetadataExtractor {
    // only the first pages are searched; the rest of a long manuscript is never looked at
    // 10 pages leaves room for a 2-3 page acknowledgement and an abstract that spills onto a second page
    // (the library has already read the whole file by then, so looking at a few more pages costs little)
    private const PAGES_TO_READ = 10;

    // returns ['readable' => bool, 'values' => form values as text, 'found' => names of filled fields]
    // 'readable' is false when no text could be read (for example a scanned PDF, or a damaged or locked file)
    public function extractFromFile(string $path): array {
        try {
            $parser = new Parser();
            $document = $parser->parseFile($path);
            $pages = $document->getPages();
        } catch (\Exception $exception) {
            // the library throws for encrypted, damaged or unusual files; autofill is optional, so just report it
            return ['readable' => false, 'values' => [], 'found' => []];
        }

        $pageLines = [];
        foreach (array_slice($pages, 0, self::PAGES_TO_READ) as $page) {
            $pageLines[] = $this->linesOfPage($page);
        }
        return $this->extractFromLines($pageLines, count($pages));
    }

    // the field-finding rules, working on plain lines of text so the unit test can feed made-up pages
    // $pageLines is one list of lines per page; $pageCount is the PDF's total number of pages
    public function extractFromLines(array $pageLines, int $pageCount): array {
        // drop the repeated "WORLD CITI COLLEGES" header and lone page numbers ("iii", "2") from every page
        $cleanPages = [];
        $hasText = false;
        foreach ($pageLines as $lines) {
            $cleanLines = [];
            foreach ($lines as $line) {
                $line = trim(preg_replace('/\s+/u', ' ', $line));
                if ($line === '' || $this->isRunningHeader($line) || preg_match('/\A([ivxlcdm]+|[0-9]{1,3})\z/i', $line)) {
                    continue;
                }
                $cleanLines[] = $line;
                $hasText = true;
            }
            $cleanPages[] = $cleanLines;
        }
        if (!$hasText) {
            return ['readable' => false, 'values' => [], 'found' => []];
        }

        $values = [];
        if (count($cleanPages) > 0) {
            $values = array_merge($values, $this->readTitlePage($cleanPages[0]));
        }
        $values = array_merge($values, $this->readApprovalSheet($cleanPages));
        $values = array_merge($values, $this->readAbstract($cleanPages));
        if ($pageCount > 0) {
            $values['page_count'] = (string) $pageCount;
        }

        // the approval sheet repeats the title in quotes; use it only when the title page gave nothing
        if ((!isset($values['title']) || $values['title'] === '') && isset($values['approval_title'])) {
            $values['title'] = $values['approval_title'];
        }
        unset($values['approval_title']);

        // keep only fields that actually received text, and remember their names for the "filled from PDF" notice
        $found = [];
        foreach ($values as $field => $value) {
            if ($value === '') {
                unset($values[$field]);
            } else {
                $found[] = $field;
            }
        }
        return ['readable' => true, 'values' => $values, 'found' => $found];
    }

    // ---- page 1: title, program, authors, year ----
    private function readTitlePage(array $lines): array {
        $titleLines = [];
        $authors = [];
        $program = '';
        $year = '';
        $programIndex = -1; // line number of the program when it sits on the line after "Degree of"
        $section = 'title'; // which part of the title page we are reading: title -> middle -> authors -> done

        foreach ($lines as $index => $line) {
            if ($section === 'title') {
                // the title ends where "A Thesis Presented to" begins
                if (preg_match('/\AA (Thesis|Capstone|Research)\b/i', $line)) {
                    $section = 'middle';
                    continue;
                }
                $titleLines[] = $line;
                continue;
            }

            // the month/year line ("August, 2024" or "July 2024") gives the year and ends the author list
            // it is checked outside the author list too, so the year is still found when no authors were recognised
            // only the first such line counts; nothing after it on the title page is read
            if ($section === 'done') {
                break;
            }
            if (preg_match('/\A[A-Za-z]+,?\s+((19|20)[0-9]{2})\z/', $line, $matches)) {
                $year = $matches[1];
                $section = 'done';
                continue;
            }

            if ($section === 'middle') {
                // "...the Degree of Bachelor of Science in ..." on one line: the program is the rest of that line,
                // and the authors come next
                if (preg_match('/Degree of\s+(.+)\z/i', $line, $matches)) {
                    $program = rtrim($matches[1], '.');
                    $section = 'authors';
                    continue;
                }
                // "...the Degree of" at the end of a line: the program is the next line
                if (preg_match('/Degree of\s*\z/i', $line) && isset($lines[$index + 1])) {
                    $program = rtrim($lines[$index + 1], '.');
                    $programIndex = $index + 1;
                    continue;
                }
                // the program line itself; the authors start right after it, whether or not a "by" line follows
                if ($index === $programIndex) {
                    $section = 'authors';
                    continue;
                }
                // a "by" line before any program line also starts the authors ("by", "By:", or "by Santos, ...")
                if ($this->isByLine($line)) {
                    $section = 'authors';
                    $namesAfterBy = $this->textAfterBy($line);
                    if ($namesAfterBy !== '') {
                        $authors[] = $namesAfterBy;
                    }
                }
                continue;
            }
            if ($section === 'authors') {
                // a "by" line between the program and the names is skipped; names printed after it on the same
                // line ("By: Santos, Maria Clara D.") are kept
                if ($this->isByLine($line)) {
                    $namesAfterBy = $this->textAfterBy($line);
                    if ($namesAfterBy !== '') {
                        $authors[] = $namesAfterBy;
                    }
                    continue;
                }
                $authors[] = $line; // kept exactly as printed, e.g. "Santos, Maria Clara D."
            }
        }

        // without the "A Thesis Presented to" marker we cannot tell where the title stops, so leave it empty
        $title = '';
        if ($section !== 'title') {
            $title = $this->joinLines($titleLines);
        }
        return [
            'title' => $title,
            'program' => $program,
            'authors' => implode("\n", $authors), // the form expects one author per line
            'publication_year' => $year,
        ];
    }

    // ---- approval sheet: adviser, final defense date, and the quoted title as a fallback ----
    private function readApprovalSheet(array $pages): array {
        $result = ['adviser' => '', 'final_defense_date' => '', 'approval_title' => ''];
        foreach ($pages as $lines) {
            if (!$this->hasHeading($lines, 'Approval Sheet')) {
                continue;
            }
            foreach ($lines as $index => $line) {
                // the adviser's name is printed on the line just above the word "Adviser" (or "Thesis Adviser")
                if ($result['adviser'] === '' && preg_match('/\A(Thesis\s+)?Adviser\z/i', $line) && $index > 0) {
                    $result['adviser'] = $lines[$index - 1];
                }
                // "Date of Final Defense: December 11, 2024" becomes 2024-12-11 for the date field
                if (preg_match('/Date of Final Defense\s*:\s*(.+)\z/i', $line, $matches)) {
                    $result['final_defense_date'] = $this->isoDate($matches[1]);
                }
            }
            // "This research entitled, “Some Title”, prepared and ..." -> Some Title (curly or straight quotes)
            $joined = $this->joinLines($lines);
            if (preg_match('/entitled,?\s*[“"](.+?)[”"]/u', $joined, $matches)) {
                $result['approval_title'] = rtrim(trim($matches[1]), ',');
            }
            break; // only the first approval sheet matters
        }
        return $result;
    }

    // ---- abstract page(s): everything after "Abstract" until "Keywords:", then the keywords ----
    private function readAbstract(array $pages): array {
        $abstractLines = [];
        $keywordLines = [];
        $section = 'searching'; // searching -> abstract -> keywords -> done

        foreach ($pages as $lines) {
            foreach ($lines as $line) {
                if ($section === 'searching') {
                    if (strcasecmp($line, 'Abstract') === 0) {
                        $section = 'abstract';
                    }
                    continue;
                }
                if ($section === 'abstract') {
                    // an abstract without a "Keywords:" line must not swallow the table of contents that follows it
                    if (strcasecmp($line, 'Table of Contents') === 0) {
                        $section = 'done';
                        break;
                    }
                    if (preg_match('/\AKey\s*words?\s*:\s*(.*)\z/i', $line, $matches)) {
                        $keywordLines[] = $matches[1];
                        $section = 'keywords';
                        continue;
                    }
                    $abstractLines[] = $line;
                    continue;
                }
                if ($section === 'keywords') {
                    $keywordLines[] = $line;
                }
            }
            // keywords never continue onto a new page in the references; stop at the end of their page
            if ($section === 'keywords') {
                $section = 'done';
            }
            if ($section === 'done') {
                break;
            }
        }

        // "a, b and c." -> "a, b, c": commas separate keywords in our form, so a final "and" becomes a comma
        $keywords = $this->joinLines($keywordLines);
        $keywords = rtrim($keywords, '. ');
        $keywords = preg_replace('/\s+and\s+(?=[^,]*\z)/i', ', ', $keywords);
        $keywordList = [];
        foreach (explode(',', $keywords) as $keyword) {
            $cleanKeyword = trim($keyword);
            if ($cleanKeyword !== '') {
                $keywordList[] = $cleanKeyword;
            }
        }
        return ['abstract' => $this->joinLines($abstractLines), 'keywords' => implode(', ', $keywordList)];
    }

    // ---- helpers ----

    // rebuilds the lines of one PDF page from the library's positioned text pieces
    // each piece has a position (x from the left, y from the bottom); pieces at about the same height form one line
    private function linesOfPage($page): array {
        $width = 612.0; // US Letter width in points, used when the page does not say its size
        $details = $page->getDetails();
        if (isset($details['MediaBox']) && is_array($details['MediaBox']) && count($details['MediaBox']) === 4) {
            $width = (float) $details['MediaBox'][2] - (float) $details['MediaBox'][0];
        }

        $pieces = [];
        foreach ($page->getDataTm() as $item) {
            $x = (float) $item[0][4];
            $y = (float) $item[0][5];
            $text = (string) $item[1];
            // the vertical "COLLEGE OF INFORMATION TECHNOLOGY" banner is printed one capital letter at a time in the
            // right margin; drop single capitals and blanks there, but keep real words that merely end near the edge
            if ($x > $width * 0.85 && preg_match('/\A\s*[A-Z]?\s*\z/u', $text)) {
                continue;
            }
            $pieces[] = ['x' => $x, 'y' => $y, 'text' => $text];
        }

        // top of the page first (larger y), then left to right
        usort($pieces, [self::class, 'comparePieces']);

        $lines = [];
        $currentLine = '';
        $currentY = null;
        foreach ($pieces as $piece) {
            // a piece more than 3 points above or below the current line starts a new line
            if ($currentY === null || abs($piece['y'] - $currentY) > 3) {
                if ($currentLine !== '') {
                    $lines[] = $currentLine;
                }
                $currentLine = '';
                $currentY = $piece['y'];
            }
            $currentLine .= $piece['text'];
        }
        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }

        // some PDFs store text without positions; fall back to the library's plain text in that case
        if (count($lines) === 0) {
            $lines = preg_split('/\R/u', $page->getText());
        }
        return $lines;
    }

    // sort order for text pieces: higher on the page first, then further left first
    private static function comparePieces(array $first, array $second): int {
        if (abs($first['y'] - $second['y']) > 3) {
            return $second['y'] <=> $first['y'];
        }
        return $first['x'] <=> $second['x'];
    }

    // joins wrapped lines into one paragraph; a line ending in "-" joins without a space ("Real-" + "Time")
    private function joinLines(array $lines): string {
        $text = '';
        foreach ($lines as $line) {
            if ($text === '') {
                $text = $line;
            } elseif (str_ends_with($text, '-')) {
                $text .= $line;
            } else {
                $text .= ' ' . $line;
            }
        }
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    // true for the repeated page header, including its letter-spaced form "W O R L D  C I T I  C O L L E G E S"
    // and the form with the page number on the same line ("WORLD CITI COLLEGES iii", "... 1")
    private function isRunningHeader(string $line): bool {
        $squeezed = strtoupper(preg_replace('/\s+/u', '', $line));
        return preg_match('/\AWORLDCITICOLLEGES([IVXLCDM]+|[0-9]{1,3})?\z/', $squeezed) === 1;
    }

    // true for "by", "By:" and "by Santos, Maria" lines; \b stops surnames such as "Byrne" from matching
    private function isByLine(string $line): bool {
        return preg_match('/\Aby\b/i', $line) === 1;
    }

    // the names printed after "by" on the same line ("By: Santos, Maria" -> "Santos, Maria"), or '' when none
    private function textAfterBy(string $line): string {
        return trim(preg_replace('/\Aby\b\s*:?\s*/i', '', $line));
    }

    private function hasHeading(array $lines, string $heading): bool {
        foreach ($lines as $line) {
            if (strcasecmp($line, $heading) === 0) {
                return true;
            }
        }
        return false;
    }

    // "December 11, 2024" or "Dec 11 2024" -> "2024-12-11"; anything unreadable -> '' (the field stays empty)
    private function isoDate(string $text): string {
        $text = trim(rtrim(trim($text), '.'));
        foreach (['F j, Y', 'F j Y', 'M j, Y', 'M j Y', 'F d, Y'] as $format) {
            $date = DateTime::createFromFormat('!' . $format, $text);
            if ($date !== false && $date->format($format) === $text) {
                return $date->format('Y-m-d');
            }
        }
        return '';
    }
}
