<?php
/**
 * Maui Legistar date parser — standalone helper for testability.
 *
 * Required by scrape-maui-legistar.php and tests/MauiParserTest.php.
 * No DB or curl dependencies.
 */

/**
 * Parse a Legistar EventDate string into a Y-m-d date.
 *
 * Uses explicit Pacific/Honolulu timezone so the UTC Docker host cannot
 * shift meeting dates if Legistar sends a datetime with a time component
 * that crosses the UTC midnight boundary.
 *
 * @param string $event_date  Legistar EventDate value (e.g. "2026-03-04T00:00:00")
 * @return string|null        Date string "Y-m-d", or null if unparseable
 */
function parse_maui_date(string $event_date): ?string
{
    if (empty($event_date)) {
        return null;
    }

    $tz = new DateTimeZone('Pacific/Honolulu');
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $event_date, $tz);
    if ($dt === false) {
        // Legistar sometimes sends format variations — log and attempt fallback
        error_log("Maui Legistar: unexpected EventDate format: {$event_date}");
        try {
            $dt = new DateTimeImmutable($event_date, $tz);
        } catch (\Exception $e) {
            error_log("Maui Legistar: could not parse EventDate: {$event_date}");
            return null;
        }
    }

    return $dt->format('Y-m-d');
}
