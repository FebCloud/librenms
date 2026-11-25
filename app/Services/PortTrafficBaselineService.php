<?php

namespace App\Services;

use LibreNMS\Data\Store\Rrd;
use LibreNMS\Exceptions\RrdException;
use LibreNMS\RRD\RrdProcess;

/**
 * The usual traffic of a port for one hour of the day: the median, over the
 * past days, of the port's average rate in the same hour. Alert macros compare
 * the current rate with it, so a daily rise and fall does not look like a drop.
 */
class PortTrafficBaselineService
{
    /** Days of history the median is taken over. */
    public const DAYS = 7;

    /** Fewer days with data than this gives no baseline. */
    public const MIN_DAYS = 3;

    /** Length of one baseline slot in seconds. */
    public const SLOT = 3600;

    public function __construct(
        private readonly Rrd $rrd,
        private readonly RrdProcess $rrdProcess,
    ) {
    }

    /**
     * Baseline of one port for the slot starting at $slotStart.
     *
     * @return array{in: ?float, out: ?float, days: int}
     */
    public function forPort(string $hostname, int $portId, int $slotStart): array
    {
        $filename = (string) $this->rrd->name($hostname, $this->rrd->portName($portId));
        $command = implode(' ', $this->rrd->buildCommand('fetch', $filename, [
            'AVERAGE',
            // The 30 minute RRA keeps 30 days; a coarser step would blur the hour.
            '--resolution',
            '1800',
            '--start',
            (string) ($slotStart - self::DAYS * 86400),
            '--end',
            (string) ($slotStart - 86400 + self::SLOT),
        ]));

        try {
            $output = $this->rrdProcess->run($command);
        } catch (RrdException) {
            return ['in' => null, 'out' => null, 'days' => 0];
        }

        return self::fromFetch($output, $slotStart);
    }

    /**
     * Baseline from `rrdtool fetch` output of a port RRD (INOCTETS/OUTOCTETS,
     * bytes per second). A row's timestamp ends the interval it covers.
     *
     * @return array{in: ?float, out: ?float, days: int}
     */
    public static function fromFetch(string $output, int $slotStart, int $days = self::DAYS, int $minDays = self::MIN_DAYS): array
    {
        $columns = [];
        $sums = ['INOCTETS' => [], 'OUTOCTETS' => []];
        $counts = $sums;

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
            // Whole days back from the slot; the row must fall inside that day's slot.
            $day = intdiv($slotStart + self::SLOT - $timestamp, 86400);
            $windowStart = $slotStart - $day * 86400;
            if ($day < 1 || $day > $days || $timestamp <= $windowStart || $timestamp > $windowStart + self::SLOT) {
                continue;
            }

            $values = preg_split('/\s+/', trim($matches[2])) ?: [];
            foreach (array_keys($sums) as $dataset) {
                $index = array_search($dataset, $columns, true);
                $value = $index === false ? null : ($values[$index] ?? null);
                if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
                    continue;
                }
                $sums[$dataset][$day] = ($sums[$dataset][$day] ?? 0.0) + (float) $value;
                $counts[$dataset][$day] = ($counts[$dataset][$day] ?? 0) + 1;
            }
        }

        $median = function (string $dataset) use ($sums, $counts, $minDays): ?float {
            $averages = [];
            foreach ($sums[$dataset] as $day => $sum) {
                $averages[] = $sum / $counts[$dataset][$day];
            }
            if (count($averages) < $minDays) {
                return null;
            }
            sort($averages);
            $middle = intdiv(count($averages), 2);

            return count($averages) % 2 ? $averages[$middle] : ($averages[$middle - 1] + $averages[$middle]) / 2;
        };

        return [
            'in' => $median('INOCTETS'),
            'out' => $median('OUTOCTETS'),
            'days' => max(count($sums['INOCTETS']), count($sums['OUTOCTETS'])),
        ];
    }
}
