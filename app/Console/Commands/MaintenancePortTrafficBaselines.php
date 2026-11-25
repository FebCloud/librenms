<?php

namespace App\Console\Commands;

use App\Console\LnmsCommand;
use App\Services\PortTrafficBaselineService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\InputOption;

/**
 * Refreshes port_traffic_baselines for the current hour, read by the
 * port_*_baseline and port_*_drop_perc alert macros.
 */
class MaintenancePortTrafficBaselines extends LnmsCommand
{
    protected $name = 'maintenance:port-traffic-baselines';

    public function __construct()
    {
        parent::__construct();
        $this->addOption('device', 'd', InputOption::VALUE_REQUIRED);
    }

    public function handle(PortTrafficBaselineService $baselines): int
    {
        $slot = intdiv(time(), PortTrafficBaselineService::SLOT) * PortTrafficBaselineService::SLOT;
        // A little past the hour, so a late run does not leave a gap.
        $validUntil = $slot + PortTrafficBaselineService::SLOT + 600;
        $saved = 0;

        $ports = DB::table('ports')
            ->join('devices', 'devices.device_id', '=', 'ports.device_id')
            ->where('ports.deleted', 0)
            ->where('ports.disabled', 0)
            ->where('ports.ignore', 0)
            ->where('devices.disabled', 0)
            ->where('devices.ignore', 0)
            ->select(['ports.port_id', 'devices.hostname'])
            ->orderBy('ports.port_id');

        $device = $this->option('device');
        if ($device !== null) {
            $ports->where(fn ($query) => $query->where('devices.hostname', $device)->orWhere('devices.device_id', $device));
        }

        $ports->chunk(500, function ($chunk) use ($baselines, $slot, $validUntil, &$saved): void {
            $rows = [];
            $missing = [];
            foreach ($chunk as $port) {
                $baseline = $baselines->forPort($port->hostname, (int) $port->port_id, $slot);
                if ($baseline['in'] === null && $baseline['out'] === null) {
                    $missing[] = $port->port_id;

                    continue;
                }
                $rows[] = [
                    'port_id' => $port->port_id,
                    'in_rate' => $baseline['in'],
                    'out_rate' => $baseline['out'],
                    'days' => $baseline['days'],
                    'valid_from' => $slot,
                    'valid_until' => $validUntil,
                ];
            }
            if ($rows !== []) {
                DB::table('port_traffic_baselines')->upsert($rows, ['port_id'], ['in_rate', 'out_rate', 'days', 'valid_from', 'valid_until']);
                $saved += count($rows);
            }
            if ($missing !== []) {
                DB::table('port_traffic_baselines')->whereIn('port_id', $missing)->delete();
            }
        });

        // Ports that were deleted or disabled since.
        DB::table('port_traffic_baselines')->where('valid_until', '<', $slot - 86400)->delete();

        $this->info(trans('commands.maintenance:port-traffic-baselines.saved', ['count' => $saved]));

        return 0;
    }
}
