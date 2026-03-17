<?php
require_once __DIR__ . '/../api/cron/parse_helpers_ehawaii.php';

use PHPUnit\Framework\TestCase;

/**
 * Tests for eHawaii RSS item parser.
 *
 * Exercises parse_rss_item() from parse_helpers_ehawaii.php via require_once.
 * Covers: primary Date: label, secondary F j, Y single/range, pubDate fallback, null.
 */
class EHawaiiParserTest extends TestCase
{
    /**
     * Build a minimal eHawaii RSS <item> element for testing.
     */
    private function makeRssItem(string $desc, string $pubDate = 'Thu, 01 Jan 2026 00:00:00 +0000'): \SimpleXMLElement
    {
        $xml = simplexml_load_string(sprintf(
            '<item>' .
                '<description><![CDATA[%s]]></description>' .
                '<pubDate>%s</pubDate>' .
                '<link>https://calendar.ehawaii.gov/calendar/meeting/12345/details.html</link>' .
                '<guid>test-guid-12345</guid>' .
                '<title>Test Board Meeting</title>' .
            '</item>',
            $desc, $pubDate
        ));
        return $xml;
    }

    /**
     * Primary regex: "Date: YYYY/MM/DD" label in description body.
     * This is the standard eHawaii format for most entries.
     */
    public function testPrimaryDateLabel(): void
    {
        $desc = "Location: Room 101<br/>Date: 2026/03/04<br/>Time: 10:00 AM<br/>Agenda notes";
        $result = parse_rss_item($this->makeRssItem($desc), 1);
        $this->assertNotNull($result);
        $this->assertSame('2026-03-04', $result['meeting_date']);
    }

    /**
     * Secondary single-date format: "April 21, 2026 - 7:00 pm - 10:00 pm <br/>venue..."
     * Covers the 35 descriptions that do not use the "Date:" label.
     */
    public function testSecondaryDateSingleFormat(): void
    {
        $desc = "April 21, 2026 - 7:00 pm - 10:00 pm <br/>Kapālama Hale Room 153 <br/>925 Dillingham Boulevard";
        $result = parse_rss_item($this->makeRssItem($desc), 1);
        $this->assertNotNull($result);
        $this->assertSame('2026-04-21', $result['meeting_date']);
    }

    /**
     * Secondary date-range format: "April 21, 2026 - April 28, 2026 - 7:00 pm - 10:00 pm <br/>venue..."
     * Should return the start date (first date in range).
     */
    public function testSecondaryDateRangeFormat(): void
    {
        $desc = "April 21, 2026 - April 28, 2026 - 7:00 pm - 10:00 pm <br/>Wahiawā District Park (Halekoa Building)";
        $result = parse_rss_item($this->makeRssItem($desc), 1);
        $this->assertNotNull($result);
        $this->assertSame('2026-04-21', $result['meeting_date']);
    }

    /**
     * pubDate fallback: no parseable date in description, but valid pubDate present.
     * Fallback must activate and produce a date from pubDate.
     */
    public function testPubDateFallbackFires(): void
    {
        $desc = "Agenda: Special session. See detail page for more information.";
        $pubDate = 'Thu, 01 Jan 2026 00:00:00 +0000';
        $result = parse_rss_item($this->makeRssItem($desc, $pubDate), 1);
        $this->assertNotNull($result);
        $this->assertSame('2026-01-01', $result['meeting_date'],
            'pubDate fallback should produce 2026-01-01 from Thu, 01 Jan 2026 00:00:00 +0000');
    }

    /**
     * Null result: no description date AND empty pubDate.
     * Must return null — cannot store a meeting without a date.
     */
    public function testNullWhenNoParseable(): void
    {
        $desc = "No date information available.";
        $result = parse_rss_item($this->makeRssItem($desc, ''), 1);
        $this->assertNull($result, 'Should return null when no date can be parsed');
    }
}
