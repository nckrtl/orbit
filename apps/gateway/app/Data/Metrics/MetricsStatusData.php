<?php

declare(strict_types=1);

namespace App\Data\Metrics;

final readonly class MetricsStatusData
{
    /**
     * @param  list<MetricsExporterData>  $exporters
     * @param  'healthy'|'degraded'  $reconcileStatus
     */
    public function __construct(
        public bool $enabled,
        public ?string $url,
        public ?MetricsAssignmentData $assignment,
        public string $prometheus,
        public string $grafana,
        public array $exporters,
        public ?string $reconcileErrorCode = null,
        public string $reconcileStatus = 'healthy',
    ) {}

    /**
     * @return array{
     *     enabled: bool,
     *     url: ?string,
     *     assignment: ?array{id: int, node_id: int, node_name: string, status: string, failed_step: ?string, error_code: ?string},
     *     prometheus: string,
     *     grafana: string,
     *     exporters: list<array{id: int, name: string, desired: bool, actual: string, reason: string, degraded_reason: ?string, degraded_error_code: ?string}>,
     *     reconcile_status: 'healthy'|'degraded',
     *     reconcile_error_code: ?string,
     * }
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'url' => $this->url,
            'assignment' => $this->assignment?->toArray(),
            'prometheus' => $this->prometheus,
            'grafana' => $this->grafana,
            'exporters' => array_map(
                static fn (MetricsExporterData $exporter): array => $exporter->toArray(),
                $this->exporters,
            ),
            'reconcile_status' => $this->reconcileStatus,
            'reconcile_error_code' => $this->reconcileErrorCode,
        ];
    }
}
