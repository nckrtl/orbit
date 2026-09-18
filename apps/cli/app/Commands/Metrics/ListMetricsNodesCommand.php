<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Metrics\ListMetricsNodesRequest;
use Orbit\Sdk\Responses\Metrics\MetricsNodesResponse;

final class ListMetricsNodesCommand extends MetricsCommand
{
    #[\Override]
    protected $signature = 'metrics:node:list {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List one metrics snapshot per Node, read from the Metrics role Prometheus.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $connector = $this->connector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ListMetricsNodesRequest,
            MetricsNodesResponse::class,
            ['List Node metrics', 'Loading Node metrics', 'Loaded Node metrics'],
        );

        if (! $response instanceof MetricsNodesResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($response->nodes as $node) {
            $rows[] = [
                $node->nodeId,
                $node->nodeName,
                $node->available ? 'yes' : 'no',
                $node->available ? $this->coresSummary($node->cores) : '—',
                $node->available ? $this->bytesFraction($node->memory['used'], $node->memory['total']) : '—',
                $node->available ? sprintf('%.2f, %.2f, %.2f', $node->load['one'], $node->load['five'], $node->load['fifteen']) : '—',
                $node->reason ?? '—',
            ];
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['ID', 'Node', 'Available', 'Cores', 'Memory', 'Load', 'Reason'],
            $rows,
            'No Node metrics found.',
        ));
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }

    /** @param  list<float>  $cores */
    private function coresSummary(array $cores): string
    {
        if ($cores === []) {
            return '—';
        }

        $average = array_sum($cores) / count($cores);

        return round($average * 100).'% avg, '.count($cores).' cores';
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
}
