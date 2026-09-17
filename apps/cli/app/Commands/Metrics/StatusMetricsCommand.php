<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
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
        $response = $this->sendWithProgress(
            $connector,
            new ShowMetricsStatusRequest,
            MetricsStatusResponse::class,
            ['Show Metrics status', 'Loading Metrics status', 'Loaded Metrics status'],
        );
        if (! $response instanceof MetricsStatusResponse) {
            return self::FAILURE;
        }
        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $assignment = $response->assignment;

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail('Metrics status.', [
            'Enabled' => $response->enabled,
            'URL' => $response->url,
            'Assignment ID' => $assignment['id'] ?? null,
            'Assignment status' => $assignment['status'] ?? null,
            'Assignment node ID' => $assignment['node_id'] ?? null,
            'Assignment node' => $assignment['node_name'] ?? null,
            'Failed step' => $assignment['failed_step'] ?? null,
            'Error code' => $assignment['error_code'] ?? null,
            'Prometheus' => $response->prometheus,
            'Grafana' => $response->grafana,
        ]));

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['ID', 'Node', 'Desired', 'Actual', 'Reason', 'Degraded'],
            array_map(static fn (array $row): array => [
                $row['id'],
                $row['name'],
                $row['desired'],
                $row['actual'],
                $row['reason'],
                $row['degraded_reason'],
            ], $response->exporters),
            'No Metrics exporters configured.',
        ));

        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
