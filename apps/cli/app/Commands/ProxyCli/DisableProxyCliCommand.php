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
    protected $signature = 'proxycli:disable
        {--yes : Confirm the fleet-wide stop without a prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Stop the collector and withdraw collector.cli-proxy-api.orbit.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        if (($blocked = $this->guardExtension()) !== null) {
            return $blocked;
        }

        if (! $this->confirmAction(
            'Disable ProxyCli for the whole fleet? This stops the collector, withdraws collector.cli-proxy-api.orbit and its certificate, and deletes the stored management key and tokens.',
            'ProxyCli disable cancelled.',
            requiredMessage: 'Non-interactive ProxyCli disable requires --yes.',
        )) {
            return self::FAILURE;
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
