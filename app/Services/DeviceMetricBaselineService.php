<?php

namespace App\Services;

use LibreNMS\Data\Store\Rrd;
use LibreNMS\Exceptions\RrdException;
use LibreNMS\RRD\RrdProcess;

/**
 * The usual CPU and memory usage of a device for one hour of the day: the
 * median, over the past days, of the device's average usage in the same hour
 * (in percent). Alert macros compare the current usage with it, so a busy
 * hour that is busy every day does not look like a sudden rise.
 */
class DeviceMetricBaselineService
{
    public function __construct(
        private readonly Rrd $rrd,
        private readonly RrdProcess $rrdProcess,
    ) {
    }

    /**
     * Baseline of one device for the slot starting at $slotStart.
     *
     * @param  list<array{type: string, index: string}>  $processors
     * @param  list<array{type: string, class: string, index: string}>  $mempools  the device's system memory pools
     * @return array{cpu: ?float, mem: ?float, days: int}
     */
    public function forDevice(string $hostname, array $processors, array $mempools, int $slotStart): array
    {
        $cpu = [];
        foreach ($processors as $processor) {
            $output = $this->fetch($hostname, ['processor', $processor['type'], $processor['index']], $slotStart);
            $cpu[] = self::dayAverages($output, $slotStart, fn (array $row): ?float => $row['usage'] ?? null);
        }

        $mem = [];
        foreach ($mempools as $mempool) {
            $output = $this->fetch($hostname, ['mempool', $mempool['type'], $mempool['class'], $mempool['index']], $slotStart);
            $mem[] = self::dayAverages($output, $slotStart, function (array $row): ?float {
                $used = $row['used'] ?? null;
                $free = $row['free'] ?? null;

                return $used === null || $free === null || $used + $free <= 0 ? null : $used / ($used + $free) * 100;
            });
        }

        $cpuDays = self::combine($cpu);
        $memDays = self::combine($mem);

        return [
            'cpu' => self::median($cpuDays),
            'mem' => self::median($memDays),
            'days' => max(count($cpuDays), count($memDays)),
        ];
    }

    private function fetch(string $hostname, array $name, int $slotStart): string
    {
        $command = implode(' ', [
            'fetch',
            (string) $this->rrd->name($hostname, $name),
            'AVERAGE',
            // The 30 minute RRA keeps 30 days; a coarser step would blur the hour.
            '--resolution',
            '1800',
            '--start',
            (string) ($slotStart - PortTrafficBaselineService::DAYS * 86400),
            '--end',
            (string) ($slotStart - 86400 + PortTrafficBaselineService::SLOT),
        ]);

        try {
            return $this->rrdProcess->run($command);
        } catch (RrdException) {
            return '';
        }
    }

    /**
     * The average of $value over the slot's hour on each earlier day, from
     * `rrdtool fetch` output. A row's timestamp ends the interval it covers.
     *
     * @param  callable(array<string, float>): ?float  $value  a row's value from its numeric datasets
     * @return array<int, float> average by days back (1 = yesterday)
     */
    public static function dayAverages(string $output, int $slotStart, callable $value, int $days = PortTrafficBaselineService::DAYS): array
    {
        $slot = PortTrafficBaselineService::SLOT;
        $columns = [];
        $sums = [];
        $counts = [];

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($columns === [] && ! preg_match('/^\d+:/', $line)) {
                $columns = preg_split('/\s+/', $line) ?: [];

                continue;
            }
            if (! preg_match('/^(\d+):\s+(.+)$/', $line, $matches)) {
                continue;
            }

            $timestamp = (int) $matches[1];
            $day = intdiv($slotStart + $slot - $timestamp, 86400);
            $windowStart = $slotStart - $day * 86400;
            if ($day < 1 || $day > $days || $timestamp <= $windowStart || $timestamp > $windowStart + $slot) {
                continue;
            }

            $row = [];
            foreach (preg_split('/\s+/', trim($matches[2])) ?: [] as $index => $raw) {
                if (isset($columns[$index]) && is_numeric($raw) && is_finite((float) $raw)) {
                    $row[$columns[$index]] = (float) $raw;
                }
            }
            $result = $value($row);
            if ($result === null || ! is_finite($result) || $result < 0) {
                continue;
            }
            $sums[$day] = ($sums[$day] ?? 0.0) + $result;
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }

        $averages = [];
        foreach ($sums as $day => $sum) {
            $averages[$day] = $sum / $counts[$day];
        }

        return $averages;
    }

    /**
     * The mean, for each day, of the series that have that day.
     *
     * @param  list<array<int, float>>  $series
     * @return array<int, float>
     */
    public static function combine(array $series): array
    {
        $sums = [];
        $counts = [];
        foreach ($series as $days) {
            foreach ($days as $day => $average) {
                $sums[$day] = ($sums[$day] ?? 0.0) + $average;
                $counts[$day] = ($counts[$day] ?? 0) + 1;
            }
        }

        $combined = [];
        foreach ($sums as $day => $sum) {
            $combined[$day] = $sum / $counts[$day];
        }

        return $combined;
    }

    /**
     * The median of the daily averages, or null with fewer than MIN_DAYS.
     *
     * @param  array<int, float>  $days
     */
    public static function median(array $days, int $minDays = PortTrafficBaselineService::MIN_DAYS): ?float
    {
        if (count($days) < $minDays) {
            return null;
        }
        $values = array_values($days);
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
