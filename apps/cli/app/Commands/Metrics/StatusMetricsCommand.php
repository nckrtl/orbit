<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Metrics\ShowMetricsStatusRequest;
use Orbit\Sdk\Responses\Metrics\MetricsStatusResponse;

/**
 * @mago-expect lint:cyclomatic-complexity The human table surfaces every optional assignment field defensively.
 */
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

        $this->table(['Field', 'Value'], $this->statusRows($response));
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

    /** @return list<array{0: string, 1: string}> */
    private function statusRows(MetricsStatusResponse $response): array
    {
        $assignment = $response->assignment;
        $summary = $assignment === null
            ? '-'
            : sprintf(
                '#%s (%s) on %s',
                $this->text($assignment, 'id') ?? '-',
                $this->text($assignment, 'status') ?? '-',
                $this->text($assignment, 'node_name') ?? '-',
            );

        $rows = [
            ['Enabled', $response->enabled ? 'yes' : 'no'],
            ['URL', $response->url ?? '-'],
            ['Assignment', $summary],
        ];

        foreach (['Failed step' => 'failed_step', 'Error code' => 'error_code'] as $label => $key) {
            $value = $this->text($assignment, $key);

            if ($value !== null) {
                $rows[] = [$label, $value];
            }
        }

        $rows[] = ['Prometheus', $response->prometheus];
        $rows[] = ['Grafana', $response->grafana];

        return $rows;
    }

    /**
     * Reads one printable assignment field; absent, null, and unexpected shapes all read as absent.
     *
     * @param array<string, mixed>|null $assignment
     */
    private function text(?array $assignment, string $key): ?string
    {
        $value = $assignment[$key] ?? null;

        return is_string($value) || is_int($value) ? (string) $value : null;
    }
}
