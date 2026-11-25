<?php

namespace App\Graphs\Rrd;

use App\Data\Graphing\DataSeries;
use App\Data\Graphing\GraphImage;
use App\Data\Graphing\GraphOptions;
use App\Data\Graphing\GraphParameters;
use App\Data\Graphing\GraphExclusion;
use App\Data\Graphing\GraphSeriesSelection;
use App\Data\Graphing\TrafficGraphStyle;
use App\Facades\LibrenmsConfig;
use Closure;
use LibreNMS\Enum\ImageFormat;
use LibreNMS\Exceptions\GraphException;
use LibreNMS\Exceptions\InvalidGraphInput;
use LibreNMS\Exceptions\NoGraphData;
use LibreNMS\Exceptions\RrdGraphException;
use LibreNMS\RRD\Backend\RrdBackendInterface;

/**
 * Draws rrdtool graph definitions: adds the common options, runs rrdtool graph and builds the debug command
 */
class RrdGraphRenderer
{
    public function __construct(
        private readonly GraphParameters $params,
        private readonly RrdFileResolver $rrdFiles,
    ) {
    }

    /**
     * @param  array<string, DataSeries>  $series
     *
     * @throws GraphException
     */
    public function render(string $title, array $series, GraphOptions $options): GraphImage
    {
        return $this->draw($title, $this->seriesCommandOptions($title, $series, $options));
    }

    /**
     * @param  array<string, DataSeries>  $series
     *
     * @throws GraphException
     */
    public function seriesDebugCommand(string $title, array $series, GraphOptions $options): string
    {
        return $this->debugCommand($this->seriesCommandOptions($title, $series, $options));
    }

    /**
     * @param  array<string, DataSeries>  $series
     * @return array<int, string>
     *
     * @throws GraphException
     */
    public function seriesCommandOptions(string $title, array $series, GraphOptions $options): array
    {
        $definition = new RrdSeriesDefinition($series, $options, $this->rrdFiles)->build($this->params);

        return $this->commandOptions($title, $options, $definition);
    }

    /**
     * The full rrdtool graph argument list
     *
     * @param  array<int, string>  $definition  the graph elements: DEF, CDEF, LINE, AREA, GPRINT, etc.
     * @return array<int, string>
     *
     * @throws GraphException
     */
    public function commandOptions(string $title, GraphOptions $options, array $definition): array
    {
        if (empty($definition)) {
            throw new GraphException('Graph Definition Error', 'Def Error');
        }

        $params = $this->params;
        $type = $params->input->string('type') ?? '';

        // The Cacti style draws its own axis, colours and direction.
        if ($params->usesTrafficRestyling() && function_exists('generate_stacked_graphs')
            && generate_stacked_graphs(false, '88', $params->trafficSameAxis)['stacked'] === '1') {
            $definition = TrafficGraphStyle::sameAxis($definition);
        }
        if ($params->usesTrafficRestyling() && $params->supportsTrafficDirection) {
            $definition = TrafficGraphStyle::direction($definition, $params->trafficDirection);
        }
        // Disk graphs draw reads as the "in" series and writes as "out".
        if ($params->diskDirection !== 'both') {
            $definition = TrafficGraphStyle::direction($definition, $params->diskDirection === 'read' ? 'in' : 'out');
        }

        $commandOptions = [
            ...new RrdOptionsBuilder($params)->build($title, $options),
            ...$definition,
        ];

        $series = $params->input->string('graph_series');
        if ($series !== null) {
            if (! GraphSeriesSelection::supports($type)) {
                throw new InvalidGraphInput('Series selection is not supported for this graph');
            }
            $commandOptions = GraphSeriesSelection::select($commandOptions, $type, $series);
        }

        return GraphExclusion::apply($commandOptions, GraphExclusion::parse($params->input->string('exclude')));
    }

    /**
     * A shell command that draws the graph, rrd file names are relative to the rrd directory
     *
     * @param  array<int, string>  $commandOptions
     */
    public function debugCommand(array $commandOptions): string
    {
        return 'cd ' . escapeshellarg((string) LibrenmsConfig::get('rrd_dir')) . ' && ' . implode(' ', array_map(escapeshellarg(...), [
            'rrdtool',
            'graph',
            '-',
            ...$commandOptions,
        ]));
    }

    /**
     * Draw the graph in the timezone of the session
     *
     * @param  array<int, string>  $commandOptions
     * @param  (Closure(): bool)|null  $dataFilesMissing  whether a failed draw is because of missing data files
     *
     * @throws GraphException
     */
    public function draw(string $title, array $commandOptions, ?Closure $dataFilesMissing = null): GraphImage
    {
        try {
            $image = app(RrdBackendInterface::class)->graph($commandOptions, session('preferences.timezone'));
            $type = $this->params->input->string('type') ?? '';
            if ($this->params->imageFormat === ImageFormat::Svg && GraphSeriesSelection::supports($type)) {
                // tell the client which legend entries it can draw alone
                $image = GraphSeriesSelection::metadata($image, $commandOptions, $type, $this->params->input->string('graph_series'));
            }

            return new GraphImage($this->params->imageFormat, $title, $image);
        } catch (RrdGraphException $e) {
            if ($dataFilesMissing !== null && $dataFilesMissing()) {
                throw new NoGraphData('No Data files', $e);
            }

            throw new RrdGraphException('Error: ' . $e->getMessage(), 'Draw Error', $e);
        }
    }
}
