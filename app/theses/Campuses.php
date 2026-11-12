<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Theses;

// the WCC campuses a thesis can come from (user's request, 2026-11-09)
// one list in code, used by the record form's dropdown, the validator and the records filter,
// so a campus name can only ever be one of these five
class Campuses {
    public const NAMES = [
        'WCC QC',
        'WCC Antipolo',
        'WCC Guimba',
        'WCC North Manila',
        'WCC Binalonan',
    ];

    // records saved before the campus field existed were all given this campus
    public const DEFAULT_FOR_EXISTING = 'WCC QC';

    // true only for one of the five names, spelled exactly
    public static function isCampus(string $name): bool {
        return in_array($name, self::NAMES, true);
    }
}
