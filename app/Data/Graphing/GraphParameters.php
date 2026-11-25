<?php

namespace App\Data\Graphing;

use LibreNMS\Enum\ImageFormat;
use LibreNMS\Util\Clean;
use LibreNMS\Util\Time;

/**
 * Typed graph input: graph type, entities, time range, size, style and visible elements
 */
readonly class GraphParameters
{
    public const MAX_SIZE = 10000;
    /** Graph type and subtype, a subtype may contain spaces: customoid subtypes are the description */
    public const TYPE_PATTERN = '/^([a-zA-Z0-9]+)_([a-zA-Z0-9_. -]+)$/';
    public const SUBTYPE_PATTERN = '/^[a-zA-Z0-9_. -]+$/';
    private const int SMALL = 224;

    /** @var array<string, bool> */
    private array $visibleElements;

    /** Input graphs read their own options from, such as bill_hist_id */
    public GraphInput $input;

    public string $type;
    public string $subtype;
    public ?int $id;
    public ?int $deviceId;
    public ?int $portId;

    public ?string $user_title;
    public ImageFormat $imageFormat;

    public string $style;
    public string $font_color;
    public string $background;
    public string $canvas;

    public int $width;
    public int $height;
    public bool $full_size;
    public bool $is_small;

    public int $from;
    public int $to;
    /** No end time was requested, so the graph ends at the current time */
    public bool $ends_now;
    public int $period;
    public int $prev_from;

    /** Text size the client asked for, so it also sized the canvas for that text */
    public ?int $fontSize;
    public bool $clientFontSize;

    public ?bool $trafficSameAxis;
    public string $trafficDirection;
    /** 'cacti' draws total inbound as a green area and total outbound as a blue line. */
    public string $trafficStyle;
    public bool $supportsTrafficDirection;
    public bool $physicalDisksOnly;
    /** Disk graphs: all, physical (whole disks) or partitions. */
    public string $diskScope;
    /** Disk graphs: one disk and its partitions, or null for every disk. */
    public ?string $diskName;
    /** Disk graphs: both, read or write. */
    public string $diskDirection;

    public bool $inverse;
    public string $in;
    public string $out;

    // user requested scale, graph options take precedence
    public ?float $scale_min;
    public ?float $scale_max;
    public ?bool $scale_rigid;

    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(array $input)
    {
        $this->input = $input = new GraphInput($input);

        $this->imageFormat = ImageFormat::forGraph($input->string('graph_type'));
        [$this->type, $this->subtype] = $this->extractType($input->string('type') ?? '');

        $this->id = $input->int('id');
        $this->deviceId = $input->int('device') ?? ($this->type === 'device' ? $this->id : null);
        $this->portId = $input->int('port') ?? ($this->type === 'port' ? $this->id : null);

        $this->width = $this->size($input->int('width') ?? 400);
        $this->height = $this->size($input->int('height') ?? intdiv($this->width, 3));
        $this->full_size = $input->bool('absolute') ?? false;
        $this->is_small = $this->width < self::SMALL;

        $type = $input->string('type') ?? '';
        $isDiskGraph = in_array($type, ['device_diskio_bits', 'device_diskio_ops'], true);
        $diskScope = $input->string('disk_scope');
        $this->diskScope = $isDiskGraph && in_array($diskScope, ['physical', 'partitions'], true) ? $diskScope : 'all';
        $this->physicalDisksOnly = $this->diskScope === 'physical';
        $disk = $input->string('disk');
        $this->diskName = $isDiskGraph && $disk !== null && preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $disk) ? $disk : null;
        $diskDirection = $input->string('disk_direction');
        $this->diskDirection = $isDiskGraph && in_array($diskDirection, ['read', 'write'], true) ? $diskDirection : 'both';

        $this->supportsTrafficDirection = in_array($type, ['device_bits', 'port_bits'], true);
        $trafficDirection = $input->string('traffic_direction');
        $this->trafficDirection = $this->supportsTrafficDirection && in_array($trafficDirection, ['in', 'out'], true) ? $trafficDirection : 'both';
        $this->trafficSameAxis = $this->supportsTrafficDirection ? $input->bool('traffic_same_axis') : null;
        $this->trafficStyle = $this->supportsTrafficDirection && $input->string('traffic_style') === 'cacti' ? 'cacti' : 'default';

        // Clients drawing large graphs may ask for larger text; the axis uses one size less.
        $fontSize = $input->int('font_size');
        $this->fontSize = $fontSize !== null && $fontSize >= 6 && $fontSize <= 16 ? $fontSize : null;
        $this->clientFontSize = $this->fontSize !== null;

        $this->style = $input->string('style') ?? '';
        $this->font_color = Clean::alphaDash($input->string('font') ?? '');

        $this->canvas = Clean::alphaDash($input->string('bg') ?? '');
        $this->background = Clean::alphaDash($input->string('bbg') ?? '');

        $this->user_title = $input->string('graph_title'); // if the user sets a title, show it
        $title = $input->string('title');
        $this->visibleElements = [
            'title' => $this->user_title !== null || ($title !== null && $title !== 'no'),
            'legend' => $input->string('legend') !== 'no',
            'total' => ! ($input->bool('nototal') ?? $this->is_small),
            'details' => ! ($input->bool('nodetails') ?? $this->is_small),
            'aggregate' => ! ($input->bool('noagg') ?? false),
            'previous' => $input->string('previous') === 'yes',
        ];

        $this->from = Time::parseAt($input->string('from') ?: '-1d') ?: Time::parseAt('-1d');
        $to = Time::parseAt($input->string('to') ?: 0);
        $this->ends_now = $to === 0;
        $this->to = $to ?: time();
        $this->period = $this->to - $this->from;
        $this->prev_from = $this->from - $this->period;
        $this->scale_min = $input->float('scale_min');
        $this->scale_max = $input->float('scale_max');
        $this->scale_rigid = $input->bool('scale_rigid');

        $this->inverse = $input->bool('inverse') ?? false;
        $this->in = $this->inverse ? 'out' : 'in';
        $this->out = $this->inverse ? 'in' : 'out';
    }

    /**
     * Whether the shared same-axis and direction rewriting applies. Cacti-style
     * traffic graphs build their own definition, which that rewriting would recolour.
     */
    public function usesTrafficRestyling(): bool
    {
        return $this->trafficStyle !== 'cacti';
    }

    public function visible(string $element): bool
    {
        return $this->visibleElements[$element] ?? true;
    }

    private function size(int $pixels): int
    {
        return max(1, min(self::MAX_SIZE, $pixels));
    }

    /**
     * @return array{string, string} type and subtype
     */
    private function extractType(string $type): array
    {
        return preg_match(self::TYPE_PATTERN, $type, $matches) ? [$matches[1], $matches[2]] : ['', ''];
    }
}
