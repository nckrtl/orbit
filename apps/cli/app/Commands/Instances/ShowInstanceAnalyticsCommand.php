<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Analytics\ShowInstanceAnalyticsRequest;
use Orbit\Sdk\Responses\Analytics\InstanceAnalyticsResponse;

final class ShowInstanceAnalyticsCommand extends InstanceAnalyticsCommand
{
    #[\Override]
    protected $signature = 'instance:analytics:show
        {instance : Numeric instance ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show the analytics tracking hosts of an Instance.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ShowInstanceAnalyticsRequest($instanceId),
            InstanceAnalyticsResponse::class,
            ['Show analytics', 'Loading tracking hosts', 'Loaded tracking hosts'],
        );

        return $response instanceof InstanceAnalyticsResponse
            ? $this->renderAnalytics($response, 'Instance analytics.')
            : self::FAILURE;
    }
}
