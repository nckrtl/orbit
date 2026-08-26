<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Responses\Activities\ActivityResponse;

final class InvalidGatewayDtoCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'test:gateway-invalid-dto {--json}';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        return $this->send($connector, new InvalidGatewayDtoRequest, ActivityResponse::class) === null
            ? self::FAILURE
            : self::SUCCESS;
    }
}
