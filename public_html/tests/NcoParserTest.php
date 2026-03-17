<?php
// tests/NcoParserTest.php
// Inline copy of parse logic from scrape-nco.php lines ~380-412
// No require of scraper file — avoids DB connections and side effects

use PHPUnit\Framework\TestCase;

class NcoParserTest extends TestCase
{
    /**
     * Inline copy of date parse logic from scrape-nco.php.
     * Must stay in sync with the production implementation.
     */
    private function parseNcoDate(string $desc_raw): ?string
    {
        $desc_html  = html_entity_decode($desc_raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $desc_lines = preg_split('/<br\s*\/?>/i', $desc_html);
        $desc_lines = array_map('trim', $desc_lines);
        $desc_lines = array_map('strip_tags', $desc_lines);
        $desc_lines = array_values(array_filter($desc_lines, fn($l) => $l !== ''));

        if (empty($desc_lines[0])) {
            return null;
        }

        $date_time_line = $desc_lines[0];

        // Date range: "February 18, 2026 - March 18, 2026 - 6:30 pm - 8:30 pm"
        if (preg_match('/^([A-Z][a-z]+ \d{1,2}, \d{4})\s*-\s*[A-Z][a-z]+ \d{1,2}, \d{4}\s*-\s*(.+)$/i', $date_time_line, $m)) {
            $date_part = $m[1];
        // Single date: "March 4, 2026 - 6:30 pm - 8:30 pm"
        } elseif (preg_match('/^([A-Z][a-z]+ \d{1,2}, \d{4})\s*-\s*(.+)$/i', $date_time_line, $m)) {
            $date_part = $m[1];
        } else {
            $date_part = $date_time_line;
        }

        $dt = DateTime::createFromFormat('F j, Y', trim($date_part));
        return $dt ? $dt->format('Y-m-d') : null;
    }

    /**
     * Single date format: "March 4, 2026 - 6:30 pm - 8:30 pm <br/>venue..."
     * Source: live DB fixture from meeting_id=243
     */
    public function testSingleDateFormat(): void
    {
        $desc = 'March 4, 2026 - 6:30 pm - 8:30 pm <br/>Library <br/>123 Street <br/>Honolulu';
        $this->assertSame('2026-03-04', $this->parseNcoDate($desc));
    }

    /**
     * Date range format: "February 18, 2026 - March 18, 2026 - 6:30 pm - 8:30 pm <br/>venue..."
     * Source: live DB fixture from meeting_id=238 — uses first (start) date
     */
    public function testDateRangeFormat(): void
    {
        $desc = 'February 18, 2026 - March 18, 2026 - 6:30 pm - 8:30 pm <br/>Coffee Bean and Tea Leaf <br/>2754 Woodlawn Drive <br/>Honolulu';
        $this->assertSame('2026-02-18', $this->parseNcoDate($desc));
    }

    /**
     * HTML entity decoding: "&amp;" and numeric entities in venue text must not break date parse
     */
    public function testHtmlEntityDecoding(): void
    {
        $desc = 'March 4, 2026 - 6:30 pm &amp; 8:30 pm <br/>W&#257;n&#257;nalo';
        $this->assertSame('2026-03-04', $this->parseNcoDate($desc));
    }

    /**
     * Empty description returns null — no crash, no false date
     */
    public function testNullOnEmptyDescription(): void
    {
        $this->assertNull($this->parseNcoDate(''));
    }

    /**
     * Description with no parseable date returns null
     */
    public function testNullOnNoDatePresent(): void
    {
        $this->assertNull($this->parseNcoDate('No date here, just some text'));
    }
}
