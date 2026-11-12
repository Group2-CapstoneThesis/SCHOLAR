<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Theses;

// small helpers for author names, which are stored in parts so the Registrar list can sort by last name (FB-03)
// each author row keeps: given_names ("Maria Clara S."), last_name ("Dela Cruz"), suffix ("Jr.", optional)
// and author_name, the full name built from those parts, which search and the record pages show
class AuthorNames {
    // words that belong to the last name when they come right before it, as in "Dela Cruz" or "de los Santos"
    // compared in lowercase, without the trailing period ("Sta." becomes "sta")
    private const SURNAME_PARTICLES = ['de', 'del', 'dela', 'la', 'delos', 'los', 'las', 'san', 'santa', 'sta', 'sto', 'santo', 'van', 'von', 'da', 'di', 'dos', 'das', 'du', 'le', 'y'];

    // name endings kept in their own suffix field, compared in lowercase without the period
    private const SUFFIXES = ['jr', 'sr', 'ii', 'iii', 'iv', 'v'];

    // builds the full name shown on pages, e.g. ("Juan Miguel", "Dela Cruz", "Jr.") -> "Juan Miguel Dela Cruz Jr."
    public static function fullName(string $givenNames, string $lastName, ?string $suffix): string {
        $parts = [];
        if ($givenNames !== '') {
            $parts[] = $givenNames;
        }
        if ($lastName !== '') {
            $parts[] = $lastName;
        }
        if ($suffix !== null && $suffix !== '') {
            $parts[] = $suffix;
        }
        return implode(' ', $parts);
    }

    // a best guess at the parts of one typed or printed name; RDPO checks the result before saving
    // used by autofill (title pages print names like "Santos, Maria Clara D.") and to fill in old records once
    // returns ['given_names' => ..., 'last_name' => ..., 'suffix' => ... or '']
    //   "Dela Cruz, Maria Clara S."      -> given "Maria Clara S.", last "Dela Cruz"
    //   "Reyes, Jose Miguel T., Jr."     -> given "Jose Miguel T.", last "Reyes", suffix "Jr."
    //   "Juan Miguel dela Cruz Jr."      -> given "Juan Miguel", last "dela Cruz", suffix "Jr."
    //   "Carlo Ramos"                    -> given "Carlo", last "Ramos"
    public static function splitFullName(string $name): array {
        // tidy repeated spaces first, so "Ana   Cruz" splits the same way as "Ana Cruz"
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        $result = ['given_names' => '', 'last_name' => '', 'suffix' => ''];
        if ($name === '') {
            return $result;
        }

        // ---- "Last, Given" order, the way WCC title pages print the authors ----
        if (strpos($name, ',') !== false) {
            $pieces = array_map('trim', explode(',', $name));
            $lastWords = self::words($pieces[0]);
            $givenWords = [];
            if (isset($pieces[1])) {
                $givenWords = self::words($pieces[1]);
            }
            // a suffix may be its own piece ("Reyes, Jose, Jr."), stuck to the last name ("Reyes Jr., Jose")
            // or at the end of the given names ("Reyes, Jose Jr.")
            if (isset($pieces[2]) && self::isSuffix($pieces[2])) {
                $result['suffix'] = $pieces[2];
            } elseif (count($lastWords) > 1 && self::isSuffix(end($lastWords))) {
                $result['suffix'] = array_pop($lastWords);
            } elseif (count($givenWords) > 1 && self::isSuffix(end($givenWords))) {
                $result['suffix'] = array_pop($givenWords);
            }
            $result['last_name'] = implode(' ', $lastWords);
            $result['given_names'] = implode(' ', $givenWords);
            return $result;
        }

        // ---- "Given Last" order: the last word is the last name, plus any particles just before it ----
        $words = self::words($name);
        if (count($words) > 1 && self::isSuffix(end($words))) {
            $result['suffix'] = array_pop($words);
        }
        if (count($words) === 1) {
            $result['last_name'] = $words[0]; // a single word can only be a last name
            return $result;
        }
        $lastNameStart = count($words) - 1;
        // walk backwards over particles ("Dela", "de los"), but always leave at least one given name
        while ($lastNameStart > 1 && self::isParticle($words[$lastNameStart - 1])) {
            $lastNameStart--;
        }
        $result['given_names'] = implode(' ', array_slice($words, 0, $lastNameStart));
        $result['last_name'] = implode(' ', array_slice($words, $lastNameStart));
        return $result;
    }

    // turns autofill's "one printed name per line" text into rows for the form's author inputs
    // "Dela Cruz, Maria Clara S.\nReyes, Jose Miguel T." -> two rows with last_name, given_names and suffix
    public static function rowsFromLines(string $text): array {
        $rows = [];
        foreach (preg_split('/\R/', $text) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = self::splitFullName($line);
            $rows[] = ['last_name' => $parts['last_name'], 'given_names' => $parts['given_names'], 'suffix' => $parts['suffix']];
        }
        return $rows;
    }

    // splits on spaces and drops empty pieces
    private static function words(string $text): array {
        $words = [];
        foreach (explode(' ', $text) as $word) {
            if ($word !== '') {
                $words[] = $word;
            }
        }
        return $words;
    }

    // "Jr." -> true, "JR" -> true, "Juan" -> false
    private static function isSuffix(string $word): bool {
        return in_array(self::comparable($word), self::SUFFIXES, true);
    }

    private static function isParticle(string $word): bool {
        return in_array(self::comparable($word), self::SURNAME_PARTICLES, true);
    }

    // lowercase without a trailing period; mb_strtolower keeps letters like "Ñ" intact
    private static function comparable(string $word): string {
        return rtrim(mb_strtolower($word), '.');
    }
}
