<?php
// tests/HnlBoardsParserTest.php
// Inline copy of parse logic from scrape-honolulu-boards.php lines ~430-463
// No require of scraper file — avoids DB connections and side effects

use PHPUnit\Framework\TestCase;

class HnlBoardsParserTest extends TestCase
{
    /**
     * Inline copy of date parse logic from scrape-honolulu-boards.php.
     * Logic is identical to scrape-nco.php — both scrapers must stay in sync.
     */
    private function parseHnlBoardsDate(string $desc_raw): ?string
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

        // Date range: "March 3, 2026 - April 7, 2026 - 4:30 pm - 6:00 pm"
        if (preg_match('/^([A-Z][a-z]+ \d{1,2}, \d{4})\s*-\s*[A-Z][a-z]+ \d{1,2}, \d{4}\s*-\s*(.+)$/i', $date_time_line, $m)) {
            $date_part = $m[1];
        // Single date: "March 10, 2026 - 5:30 pm - 7:30 pm"
        } elseif (preg_match('/^([A-Z][a-z]+ \d{1,2}, \d{4})\s*-\s*(.+)$/i', $date_time_line, $m)) {
            $date_part = $m[1];
        } else {
            $date_part = $date_time_line;
        }

        $dt = DateTime::createFromFormat('F j, Y', trim($date_part));
        return $dt ? $dt->format('Y-m-d') : null;
    }

    /**
     * Single date format: "March 10, 2026 - 5:30 pm - 7:30 pm <br/>venue..."
     * Fixture uses a Honolulu city government building (Mission Memorial Auditorium)
     */
    public function testSingleDateFormat(): void
    {
        $desc = 'March 10, 2026 - 5:30 pm - 7:30 pm <br/>Mission Memorial Auditorium <br/>550 South King Street <br/>Honolulu';
        $this->assertSame('2026-03-10', $this->parseHnlBoardsDate($desc));
    }

    /**
     * Date range format: "March 3, 2026 - April 7, 2026 - 4:30 pm - 6:00 pm <br/>venue..."
     * Uses first (start) date, discards end date
     */
    public function testDateRangeFormat(): void
    {
        $desc = 'March 3, 2026 - April 7, 2026 - 4:30 pm - 6:00 pm <br/>City Hall Auditorium <br/>530 South King Street <br/>Honolulu';
        $this->assertSame('2026-03-03', $this->parseHnlBoardsDate($desc));
    }

    /**
     * HTML entity decoding: entities in venue text must not break date parse
     */
    public function testHtmlEntityDecoding(): void
    {
        $desc = 'March 10, 2026 - 5:30 pm - 7:30 pm <br/>Manoa Valley District Park &amp; Recreation Center <br/>2721 Ka&#8216;ipu Pl';
        $this->assertSame('2026-03-10', $this->parseHnlBoardsDate($desc));
    }

    /**
     * Empty description returns null — no crash, no false date
     */
    public function testNullOnEmptyDescription(): void
    {
        $this->assertNull($this->parseHnlBoardsDate(''));
    }

    /**
     * Description with no parseable date returns null
     */
    public function testNullOnNoDatePresent(): void
    {
        $this->assertNull($this->parseHnlBoardsDate('Agenda item text only'));
    }
}
