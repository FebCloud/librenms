<?php

namespace LibreNMS\Tests\Unit\Services;

use App\Services\PortTrafficBaselineService;
use LibreNMS\Tests\TestCase;

final class PortTrafficBaselineServiceTest extends TestCase
{
    private const SLOT = 1787421600; // an hour boundary

    /** Two 30 minute rows inside the slot on day $day back. */
    private static function day(int $day, string $in, string $out, ?string $in2 = null): string
    {
        $start = self::SLOT - $day * 86400;

        return sprintf("%d: %s %s\n%d: %s %s\n", $start + 1800, $in, $out, $start + 3600, $in2 ?? $in, $out);
    }

    public function testMedianOfDailyAveragesForTheSameHour(): void
    {
        $output = " INOCTETS OUTOCTETS\n\n"
            . self::day(7, '100', '10')
            . self::day(6, '300', '30')
            . self::day(5, '200', '20', '400') // average 300
            . self::day(4, '-nan', '-nan')
            . self::day(1, '1000', '100');

        $baseline = PortTrafficBaselineService::fromFetch($output, self::SLOT);

        $this->assertSame(4, $baseline['days']);
        $this->assertEqualsWithDelta(300.0, $baseline['in'], 0.001); // 100, 300, 300, 1000
        $this->assertEqualsWithDelta(25.0, $baseline['out'], 0.001); // 10, 20, 30, 100
    }

    public function testRowsOutsideTheHourAreIgnored(): void
    {
        $start = self::SLOT - 86400;
        $output = "INOCTETS OUTOCTETS\n"
            . sprintf("%d: 9e9 9e9\n", $start) // ends the previous hour
            . sprintf("%d: 9e9 9e9\n", $start + 5400) // next hour
            . self::day(1, '1', '1') . self::day(2, '2', '2') . self::day(3, '3', '3');

        $baseline = PortTrafficBaselineService::fromFetch($output, self::SLOT);

        $this->assertEqualsWithDelta(2.0, $baseline['in'], 0.001);
        $this->assertEqualsWithDelta(2.0, $baseline['out'], 0.001);
    }

    public function testTooLittleHistoryGivesNoBaseline(): void
    {
        $output = "INOCTETS OUTOCTETS\n" . self::day(1, '5', '5') . self::day(2, '6', '6');

        $this->assertSame(['in' => null, 'out' => null, 'days' => 2], PortTrafficBaselineService::fromFetch($output, self::SLOT));
        $this->assertSame(['in' => null, 'out' => null, 'days' => 0], PortTrafficBaselineService::fromFetch('', self::SLOT));
    }
}
