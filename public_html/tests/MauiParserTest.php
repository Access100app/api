<?php
require_once __DIR__ . '/../api/cron/parse_helpers_maui.php';

use PHPUnit\Framework\TestCase;

/**
 * Tests for Maui Legistar date parser.
 *
 * Exercises parse_maui_date() from parse_helpers_maui.php via require_once.
 * Key assertion: UTC server timezone must NOT shift dates — explicit Pacific/Honolulu
 * timezone in DateTimeImmutable prevents midnight UTC → 2pm HST-previous-day shift.
 */
class MauiParserTest extends TestCase
{
    /**
     * Standard Legistar format: "YYYY-MM-DDTHH:MM:SS" at midnight on the meeting day.
     */
    public function testStandardLegistarFormat(): void
    {
        $this->assertSame('2026-03-04', parse_maui_date('2026-03-04T00:00:00'));
    }

    /**
     * KEY TEST: midnight UTC no-shift assertion.
     *
     * Without explicit Pacific/Honolulu timezone, a UTC-configured server interprets
     * "2026-03-04T00:00:00" as midnight UTC = 2:00 PM HST the day before = wrong date.
     * With explicit HST timezone, the string is treated as local HST midnight → stays 2026-03-04.
     */
    public function testMidnightUTCDoesNotShiftDate(): void
    {
        $result = parse_maui_date('2026-03-04T00:00:00');
        $this->assertSame('2026-03-04', $result,
            'Date must not shift due to UTC→HST conversion at midnight');
    }

    /**
     * Afternoon meeting (10:00 AM HST) — no risk of date shift since it's daytime.
     */
    public function testAfternoonMeetingDate(): void
    {
        $this->assertSame('2026-03-04', parse_maui_date('2026-03-04T10:00:00'));
    }

    /**
     * Empty input returns null — no crash, no false date.
     */
    public function testNullOnEmptyInput(): void
    {
        $this->assertNull(parse_maui_date(''));
    }
}
