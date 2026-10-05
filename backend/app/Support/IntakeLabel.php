<?php

namespace App\Support;

/**
 * One way to print an intake, for the two tables that show one.
 *
 * `intake_month` is a taxonomy slug — `fall`, `spring`, `summer`, `winter` —
 * because that is what the catalogue stores (program_search.season_label, see
 * IngestPrograms which lower-cases and then allow-lists against the `intake`
 * taxonomy) and what the Apply button already posts when a partner applies from
 * a shortlist. A student's intake has to be in the SAME vocabulary or the two
 * can never be compared, which is the only reason to record it.
 *
 * Both controllers built the display string by hand as
 * `trim($month.' '.$year)`, which printed the raw slug: "fall 2026". The
 * partner reads that as a bug in their own data. Capitalising matches what the
 * programme search already shows for the same value (js/portal-search.js cap()),
 * so the two screens finally agree.
 *
 * Deliberately NOT the taxonomy's full label — that reads "Fall / Autumn
 * (September)", which is right for a dropdown that has to disambiguate and far
 * too long for a table cell.
 *
 * Tolerant on purpose. Rows already in the database carry at least three
 * spellings (the apply flow writes `fall`, one test fixture writes `September`,
 * and most rows are empty), so this leaves anything it does not recognise
 * alone rather than blanking it. A value it cannot improve is still a value
 * somebody typed.
 */
final class IntakeLabel
{
    public static function for(?string $month, int|string|null $year): string
    {
        $month = trim((string) $month);
        $year = trim((string) $year);

        if ($month !== '') {
            // ucfirst only: a slug is one lower-case word, and anything else is
            // left as the person entered it.
            $month = mb_strtoupper(mb_substr($month, 0, 1)).mb_substr($month, 1);
        }

        return trim($month.' '.$year);
    }
}
