<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Metrics\DisableMetricsRequest;
use Orbit\Sdk\Requests\Metrics\ShowMetricsStatusRequest;
use Orbit\Sdk\Responses\Metrics\MetricsMutationResponse;
use Orbit\Sdk\Responses\Metrics\MetricsStatusResponse;

final class DisableMetricsCommand extends MetricsCommand
{
    #[\Override]
    protected $signature = 'metrics:disable {--force : Skip confirmation} {--purge-data : Delete Metrics data} {--json : Return machine-readable JSON}';
    #[\Override]
    protected $description = 'Disable Metrics.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $force = $this->option('force') === true;
        $purge = $this->option('purge-data') === true;
        $nonInteractive = $this->option('json') === true || ! $this->input->isInteractive();

        if ($nonInteractive && ! $force) {
            return $this->renderGatewayFailure(
                'metrics.force_required',
                'Non-interactive Metrics disable requires --force.',
            );
        }

        if ($purge && ! $force) {
            return $this->renderGatewayFailure('metrics.force_required', '--purge-data requires --force.');
        }

        $connector = $this->connector($repository, $factory);
        if ($connector === null) {
            return self::FAILURE;
        }
        $status = $this->send($connector, new ShowMetricsStatusRequest, MetricsStatusResponse::class);
        if (! $status instanceof MetricsStatusResponse) {
            return self::FAILURE;
        }

        if (! $force) {
            $this->line('Metrics disable preview:');
            $this->line('  Data: preserve');
            $this->line('  Assignment: '.($status->assignment === null ? 'none' : 'remove'));
            if (! $this->confirm('Disable Metrics?', false)) {
                return $this->renderGatewayFailure('metrics.confirmation_required', 'Confirmation is required.');
            }
        }

        $response = $this->send(
            $connector,
            new DisableMetricsRequest(force: true, purgeData: $purge),
            MetricsMutationResponse::class,
        );

        return $response instanceof MetricsMutationResponse ? $this->mutationOutput($response) : self::FAILURE;
    }
}
