<?php

declare(strict_types=1);

namespace App\Commands\ProxyCli;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\ProxyCli\ShowProxyCliStatusRequest;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliStatusResponse;

final class StatusProxyCliCommand extends ProxyCliCommand
{
    #[\Override]
    protected $signature = 'proxycli:status {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show whether the fleet feature is enabled.';

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
            new ShowProxyCliStatusRequest,
            ProxyCliStatusResponse::class,
            ['Show proxycli status', 'Loading proxycli status', 'Loaded proxycli status'],
        );

        return $response instanceof ProxyCliStatusResponse
            ? $this->renderStatus($response, 'proxycli status.')
            : self::FAILURE;
    }
}
