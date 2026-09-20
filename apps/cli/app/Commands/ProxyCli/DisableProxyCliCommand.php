<?php

declare(strict_types=1);

namespace App\Commands\ProxyCli;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\ProxyCli\DisableProxyCliRequest;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliStatusResponse;

final class DisableProxyCliCommand extends ProxyCliCommand
{
    #[\Override]
    protected $signature = 'proxycli:disable {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Stop the collector and withdraw proxycli.orbit.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        if (($blocked = $this->guardExtension()) !== null) {
            return $blocked;
        }

        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new DisableProxyCliRequest,
            ProxyCliStatusResponse::class,
            ['Disable proxycli', 'Stopping the collector', 'Disabled proxycli'],
        );

        return $response instanceof ProxyCliStatusResponse
            ? $this->renderStatus($response, 'proxycli is disabled.')
            : self::FAILURE;
    }
}
