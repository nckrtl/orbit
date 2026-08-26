<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Gateway\ShowGatewayStatusRequest;
use Orbit\Sdk\Responses\Gateway\GatewayStatusResponse;

final class GatewayStatusCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'gateway:status
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show the active gateway status.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $profile = $this->activeGatewayProfile($repository);

        if ($profile === null) {
            return self::FAILURE;
        }

        $status = $this->send(
            $connectors->make($profile),
            new ShowGatewayStatusRequest,
            GatewayStatusResponse::class,
        );

        if (! $status instanceof GatewayStatusResponse) {
            return self::FAILURE;
        }

        $payload = [
            'gateway' => $profile->name,
            'url' => $profile->url,
            ...$status->toArray(),
        ];

        if ($this->option('json') === true) {
            $this->writeJson($payload);

            return self::SUCCESS;
        }

        $statusLabel = $status->status !== '' ? $status->status : '-';
        $version = $status->version !== '' ? $status->version : '-';

        $this->info("{$profile->name}: {$statusLabel} ({$version})");
        $this->line("URL: {$profile->url}");
        $this->line("Request ID: {$status->requestId}");

        return self::SUCCESS;
    }
}
