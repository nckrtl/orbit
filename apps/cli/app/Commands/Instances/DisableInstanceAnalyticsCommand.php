<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Analytics\DisableInstanceAnalyticsRequest;
use Orbit\Sdk\Responses\Analytics\InstanceAnalyticsResponse;

final class DisableInstanceAnalyticsCommand extends InstanceAnalyticsCommand
{
    #[\Override]
    protected $signature = 'instance:analytics:disable
        {instance : Numeric instance ID}
        {--yes : Confirm removing every tracking host}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove the analytics tracking hosts of an Instance.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        // Visits stop being counted the moment the hosts are gone, so this asks first.
        if (! $this->confirmAction(
            "Remove every analytics tracking host of Instance #{$instanceId}?",
            'Analytics tracking was not disabled.',
        )) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new DisableInstanceAnalyticsRequest($instanceId),
            InstanceAnalyticsResponse::class,
            ['Disable analytics', 'Removing tracking hosts', 'Removed tracking hosts'],
        );

        return $response instanceof InstanceAnalyticsResponse
            ? $this->renderAnalytics($response, 'Analytics tracking disabled.')
            : self::FAILURE;
    }
}
