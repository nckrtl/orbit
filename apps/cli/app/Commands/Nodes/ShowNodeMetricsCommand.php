<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Nodes\ShowNodeMetricsRequest;
use Orbit\Sdk\Responses\Nodes\NodeMetricsResponse;

final class ShowNodeMetricsCommand extends NodeCommand
{
    #[\Override]
    protected $signature = 'node:metrics
        {node : Numeric node ID or registered node name}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show one synchronous metrics snapshot for a node: cores, memory, swap, load, uptime, pressure, and disks.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->resolveNodeId($connector, $this->argument('node'));

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $metrics = $this->sendWithProgress(
            $connector,
            new ShowNodeMetricsRequest($nodeId),
            NodeMetricsResponse::class,
            ['Show Node metrics', 'Loading Node metrics', 'Loaded Node metrics'],
        );

        if (! $metrics instanceof NodeMetricsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($metrics->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Node metrics: {$metrics->nodeName}", [
            'Node ID' => $metrics->nodeId,
            'Cores' => implode(', ', array_map(
                static fn (float $ratio): string => round($ratio * 100).'%',
                $metrics->cores,
            )),
            'Memory' => $this->bytesFraction($metrics->memory['used'], $metrics->memory['total']),
            'Swap' => $this->bytesFraction($metrics->swap['used'], $metrics->swap['total']),
            'Load' => sprintf('%.2f, %.2f, %.2f', $metrics->load['one'], $metrics->load['five'], $metrics->load['fifteen']),
            'Uptime' => $this->humanDuration($metrics->uptimeSeconds),
            'CPU pressure' => sprintf('%.1f%%', $metrics->pressure['cpu']['some_avg10']),
            'Memory pressure' => sprintf('%.1f%%', $metrics->pressure['memory']['some_avg10']),
            'IO pressure' => sprintf('%.1f%%', $metrics->pressure['io']['some_avg10']),
            'Disks' => $this->disksSummary($metrics->disks),
            'Request ID' => $metrics->requestId,
        ]));

        return self::SUCCESS;
    }

    /** @param list<array{mount: string, used: int, total: int}> $disks */
    private function disksSummary(array $disks): string
    {
        if ($disks === []) {
            return '—';
        }

        return implode(', ', array_map(
            fn (array $disk): string => "{$disk['mount']}: ".$this->bytesFraction($disk['used'], $disk['total']),
            $disks,
        ));
    }

    private function bytesFraction(int $used, int $total): string
    {
        return $this->humanBytes($used).' / '.$this->humanBytes($total);
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) max(0, $bytes);
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) (int) $value : number_format($value, 1)).' '.$units[$unit];
    }

    private function humanDuration(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return "{$days}d {$hours}h {$minutes}m";
        }

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }

        return "{$minutes}m";
    }
}
