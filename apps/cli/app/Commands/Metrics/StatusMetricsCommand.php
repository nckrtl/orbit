<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Metrics\ShowMetricsStatusRequest;
use Orbit\Sdk\Responses\Metrics\MetricsStatusResponse;

final class StatusMetricsCommand extends MetricsCommand
{
    #[\Override]
    protected $signature = 'metrics:status {--json : Return machine-readable JSON}';
    #[\Override]
    protected $description = 'Show Metrics status.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $connector = $this->connector($repository, $factory);
        if ($connector === null) {
            return self::FAILURE;
        }
        $response = $this->send($connector, new ShowMetricsStatusRequest, MetricsStatusResponse::class);
        if (! $response instanceof MetricsStatusResponse) {
            return self::FAILURE;
        }
        if ($this->option('json') === true) {
            /** @var array<string, mixed> $payload */
            $payload = $response->toArray();
            $this->writeJson($payload);

            return self::SUCCESS;
        }

        $assignment = $response->assignment;
        $assignmentId = is_int($assignment['id'] ?? null) ? (string) $assignment['id'] : '-';
        $assignmentStatus = is_string($assignment['status'] ?? null) ? $assignment['status'] : '-';
        $this->table(['Field', 'Value'], [
            ['Enabled', $response->enabled ? 'yes' : 'no'],
            ['URL', $response->url ?? '-'],
            [
                'Assignment',
                $assignment === null
                    ? '-'
                    : sprintf('#%s (%s)', $assignmentId, $assignmentStatus),
            ],
            ['Prometheus', $response->prometheus],
            ['Grafana', $response->grafana],
        ]);
        if ($response->exporters !== []) {
            $this->table(['ID', 'Node', 'Desired', 'Actual', 'Reason'], array_map(static fn (array $row): array => [
                $row['id'],
                $row['name'],
                $row['desired'] ? 'yes' : 'no',
                $row['actual'],
                $row['reason'],
            ], $response->exporters));
        }
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
