<?php

namespace LibreNMS\Tests\Unit\Services;

use App\Services\DeviceMetricBaselineService;
use LibreNMS\Tests\TestCase;

final class DeviceMetricBaselineServiceTest extends TestCase
{
    private const SLOT = 1787421600; // an hour boundary

    /** Two 30 minute rows inside the slot on day $day back. */
    private static function day(int $day, string $first, ?string $second = null): string
    {
        $start = self::SLOT - $day * 86400;

        return sprintf("%d: %s\n%d: %s\n", $start + 1800, $first, $start + 3600, $second ?? $first);
    }

    public function testCpuIsTheMedianOfTheProcessorsDailyAverages(): void
    {
        $usage = fn (array $row): ?float => $row['usage'] ?? null;
        $first = DeviceMetricBaselineService::dayAverages(
            " usage\n\n" . self::day(1, '10') . self::day(2, '20', '40') . self::day(3, '-nan') . self::day(4, '50'),
            self::SLOT,
            $usage,
        );
        $second = DeviceMetricBaselineService::dayAverages(
            'usage' . "\n" . self::day(1, '30') . self::day(3, '60') . self::day(4, '70'),
            self::SLOT,
            $usage,
        );

        $this->assertEqualsWithDelta([1 => 10.0, 2 => 30.0, 4 => 50.0], $first, 0.001);
        $days = DeviceMetricBaselineService::combine([$first, $second]);
        // day 1: (10+30)/2, day 2: 30, day 3: 60, day 4: (50+70)/2
        $this->assertEqualsWithDelta([1 => 20.0, 2 => 30.0, 3 => 60.0, 4 => 60.0], $days, 0.001);
        $this->assertEqualsWithDelta(45.0, DeviceMetricBaselineService::median($days), 0.001);
    }

    public function testMemoryPercentFromUsedAndFree(): void
    {
        $perc = fn (array $row): ?float => isset($row['used'], $row['free']) && $row['used'] + $row['free'] > 0
            ? $row['used'] / ($row['used'] + $row['free']) * 100 : null;
        $days = DeviceMetricBaselineService::dayAverages(
            "used free\n" . self::day(1, '25 75') . self::day(2, '50 50') . self::day(3, '0 0'),
            self::SLOT,
            $perc,
        );

        $this->assertEqualsWithDelta([1 => 25.0, 2 => 50.0], $days, 0.001);
        $this->assertNull(DeviceMetricBaselineService::median($days));
    }
}
