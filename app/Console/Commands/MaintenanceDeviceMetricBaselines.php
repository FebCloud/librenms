<?php

namespace App\Console\Commands;

use App\Console\LnmsCommand;
use App\Services\DeviceMetricBaselineService;
use App\Services\PortTrafficBaselineService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\InputOption;

/**
 * Refreshes device_metric_baselines (usual CPU and memory usage) for the
 * current hour, read by the device_*_baseline and device_*_rise alert macros.
 */
class MaintenanceDeviceMetricBaselines extends LnmsCommand
{
    protected $name = 'maintenance:device-metric-baselines';

    public function __construct()
    {
        parent::__construct();
        $this->addOption('device', 'd', InputOption::VALUE_REQUIRED);
    }

    public function handle(DeviceMetricBaselineService $baselines): int
    {
        $slot = intdiv(time(), PortTrafficBaselineService::SLOT) * PortTrafficBaselineService::SLOT;
        // A little past the hour, so a late run does not leave a gap.
        $validUntil = $slot + PortTrafficBaselineService::SLOT + 600;

        $devices = DB::table('devices')
            ->where('disabled', 0)
            ->where('ignore', 0)
            ->select(['device_id', 'hostname'])
            ->orderBy('device_id');

        $device = $this->option('device');
        if ($device !== null) {
            $devices->where(fn ($query) => $query->where('hostname', $device)->orWhere('device_id', $device));
        }

        $saved = 0;
        $devices->chunk(100, function ($chunk) use ($baselines, $slot, $validUntil, &$saved): void {
            $ids = $chunk->pluck('device_id')->all();
            $processors = DB::table('processors')
                ->whereIn('device_id', $ids)
                ->get(['device_id', 'processor_type', 'processor_index'])
                ->groupBy('device_id');
            $mempools = DB::table('mempools')
                ->whereIn('device_id', $ids)
                ->where('mempool_class', 'system')
                ->get(['device_id', 'mempool_type', 'mempool_class', 'mempool_index'])
                ->groupBy('device_id');

            $rows = [];
            $missing = [];
            foreach ($chunk as $row) {
                $baseline = $baselines->forDevice(
                    $row->hostname,
                    ($processors[$row->device_id] ?? collect())
                        ->map(fn ($p) => ['type' => (string) $p->processor_type, 'index' => (string) $p->processor_index])
                        ->values()->all(),
                    ($mempools[$row->device_id] ?? collect())
                        ->map(fn ($m) => ['type' => (string) $m->mempool_type, 'class' => (string) $m->mempool_class, 'index' => (string) $m->mempool_index])
                        ->values()->all(),
                    $slot,
                );
                if ($baseline['cpu'] === null && $baseline['mem'] === null) {
                    $missing[] = $row->device_id;

                    continue;
                }
                $rows[] = [
                    'device_id' => $row->device_id,
                    'cpu_perc' => $baseline['cpu'],
                    'mem_perc' => $baseline['mem'],
                    'days' => $baseline['days'],
                    'valid_from' => $slot,
                    'valid_until' => $validUntil,
                ];
            }
            if ($rows !== []) {
                DB::table('device_metric_baselines')->upsert($rows, ['device_id'], ['cpu_perc', 'mem_perc', 'days', 'valid_from', 'valid_until']);
                $saved += count($rows);
            }
            if ($missing !== []) {
                DB::table('device_metric_baselines')->whereIn('device_id', $missing)->delete();
            }
        });

        // Devices that were deleted or disabled since.
        DB::table('device_metric_baselines')->where('valid_until', '<', $slot - 86400)->delete();

        $this->info(trans('commands.maintenance:device-metric-baselines.saved', ['count' => $saved]));

        return 0;
    }
}
