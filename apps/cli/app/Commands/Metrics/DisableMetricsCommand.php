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

        if ($purge && ! $force) {
            return $this->renderGatewayFailure('metrics.force_required', '--purge-data requires --force.');
        }

        $connector = $this->connector($repository, $factory);
        if ($connector === null) {
            return self::FAILURE;
        }

        if (! $force) {
            $status = $this->sendWithProgress(
                $connector,
                new ShowMetricsStatusRequest,
                MetricsStatusResponse::class,
                ['Resolve Metrics status', 'Loading Metrics status', 'Loaded Metrics status'],
            );
            if (! $status instanceof MetricsStatusResponse) {
                return self::FAILURE;
            }

            if (! $this->confirmAction(
                'Disable Metrics? Data: preserve. Assignment: '.($status->assignment === null ? 'none' : 'remove').'.',
                'Metrics disable cancelled.',
                option: 'force',
                requiredCode: 'metrics.force_required',
                requiredMessage: 'Use --force to confirm Metrics disable.',
            )) {
                return self::FAILURE;
            }
        }

        $response = $this->sendWithProgress(
            $connector,
            new DisableMetricsRequest(force: true, purgeData: $purge),
            MetricsMutationResponse::class,
            ['Disable Metrics', 'Disabling Metrics', 'Disabled Metrics'],
        );

        return $response instanceof MetricsMutationResponse ? $this->mutationOutput($response) : self::FAILURE;
    }
}
