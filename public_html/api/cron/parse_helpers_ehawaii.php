<?php
/**
 * eHawaii RSS item parser — standalone helper for testability.
 *
 * Required by scrape.php and tests/EHawaiiParserTest.php.
 * No DB or curl dependencies.
 */

/**
 * Parse a single eHawaii RSS item into a meeting array.
 *
 * @param SimpleXMLElement $item       RSS <item> element
 * @param int              $council_id Council ID (used in error_log)
 * @return array|null      Meeting data array, or null if required fields missing
 */
function parse_rss_item(SimpleXMLElement $item, int $council_id): ?array
{
    $link = trim((string) $item->link);
    $guid = trim((string) $item->guid);

    if (empty($link)) {
        return null;
    }

    // Extract state meeting ID from URL: /calendar/meeting/76720/details.html
    $state_id = null;
    if (preg_match('#/meeting/(\d+)/#', $link, $m)) {
        $state_id = (int) $m[1];
    }

    // Parse the description for structured fields
    $desc_html = html_entity_decode((string) $item->description, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Extract Location, Date, Time from the standard header lines
    $location = '';
    $date_str = '';
    $time_str = '';

    if (preg_match('/^Location:\s*(.+?)(?:<br|$)/mi', $desc_html, $m)) {
        $location = trim(strip_tags($m[1]));
    }

    // Primary: structured "Date: YYYY/MM/DD" label
    if (preg_match('/Date:\s*(\d{4}\/\d{2}\/\d{2})/i', $desc_html, $m)) {
        $date_str = str_replace('/', '-', $m[1]);
    }

    // Secondary: NCO-style first-line date ("April 21, 2026 - ..." or "April 21, 2026 - April 28, 2026 - ...")
    // Handles the 35 descriptions that do not use the "Date:" label format.
    if (empty($date_str)) {
        $lines = preg_split('/<br\s*\/?>/i', $desc_html);
        $lines = array_values(array_filter(array_map(fn($l) => trim(strip_tags($l)), $lines)));
        if (!empty($lines[0])) {
            $time_part = '';
            // Date range format: "April 21, 2026 - April 28, 2026 - 7:00 pm..."
            if (preg_match('/^([A-Z][a-z]+ \d{1,2}, \d{4})\s*-\s*[A-Z][a-z]+ \d{1,2}, \d{4}\s*-\s*(.+)$/i', $lines[0], $m)) {
                $dt = DateTime::createFromFormat('F j, Y', trim($m[1]));
                if ($dt) {
                    $date_str = $dt->format('Y-m-d');
                }
                $time_part = $m[2];
            // Single date format: "April 21, 2026 - 7:00 pm..."
            } elseif (preg_match('/^([A-Z][a-z]+ \d{1,2}, \d{4})\s*-\s*(.+)$/i', $lines[0], $m)) {
                $dt = DateTime::createFromFormat('F j, Y', trim($m[1]));
                if ($dt) {
                    $date_str = $dt->format('Y-m-d');
                }
                $time_part = $m[2];
            }

            // Extract time from NCO-style "6:30 pm - 8:30 pm" or single "10:00 am"
            if (!empty($time_part) && empty($time_str)) {
                if (preg_match('/(\d{1,2}:\d{2}\s*[ap]m)\s*-\s*(\d{1,2}:\d{2}\s*[ap]m)/i', $time_part, $tm)) {
                    $time_str = date('H:i:s', strtotime($tm[1]));
                } elseif (preg_match('/(\d{1,2}:\d{2}\s*[ap]m)/i', $time_part, $tm)) {
                    $time_str = date('H:i:s', strtotime($tm[1]));
                }
            }
        }
    }

    // Primary time format: "Time: H:MM AM" label
    if (empty($time_str) && preg_match('/Time:\s*(\d{1,2}:\d{2}\s*[AP]M)/i', $desc_html, $m)) {
        $time_str = date('H:i:s', strtotime($m[1]));
    }

    if (empty($date_str)) {
        // Try pubDate as fallback — fires only when both primary and secondary patterns fail
        $pub = (string) $item->pubDate;
        if (!empty($pub)) {
            $date_str = date('Y-m-d', strtotime($pub));
            error_log(sprintf(
                'eHawaii scraper: pubDate fallback used — council_id=%d, title=%s, pubDate=%s, desc_snippet=%s',
                $council_id,
                (string) $item->title,
                $pub,
                substr(strip_tags($desc_html ?? ''), 0, 120)
            ));
        }
    }

    if (empty($date_str)) {
        return null; // Can't store a meeting without a date
    }

    // Clean title — remove " - Updated on MM/DD/YYYY HH:MM AM" suffix
    $title = trim((string) $item->title);
    $title = preg_replace('/\s*-\s*Updated on \d{2}\/\d{2}\/\d{4}\s+\d{2}:\d{2}\s*[AP]M$/i', '', $title);

    // Extract zoom link from description
    $zoom_link = null;
    if (preg_match('#(https?://[^\s"<]*zoom\.us/[^\s"<]+)#i', $desc_html, $m)) {
        $zoom_link = $m[1];
    }

    // The full description text (strip HTML for storage)
    $description = trim(strip_tags(html_entity_decode($desc_html)));

    // Raw RSS data as JSON (matches existing DB constraint: json_valid)
    $raw_rss = json_encode([
        'title'       => (string) $item->title,
        'link'        => $link,
        'description' => (string) $item->description,
        'guid'        => $guid,
        'pubDate'     => (string) $item->pubDate,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return [
        'state_id'    => $state_id,
        'external_id' => $link,
        'council_id'  => $council_id,
        'title'       => $title,
        'description' => $description,
        'location'    => $location,
        'meeting_date' => $date_str,
        'meeting_time' => $time_str ?: null,
        'detail_url'  => $link,
        'zoom_link'   => $zoom_link,
        'status'      => 'active',
        'guid'        => $guid ?: $link,
        'pub_date'    => (string) $item->pubDate,
        'raw_rss'     => $raw_rss,
    ];
}
