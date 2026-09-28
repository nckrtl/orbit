<?php

declare(strict_types=1);

namespace App\Commands\ProxyCli;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\ProxyCli\TeardownProxyCliRequest;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliStatusResponse;

final class TeardownProxyCliCommand extends ProxyCliCommand
{
    #[\Override]
    protected $signature = 'proxycli:teardown
        {--yes : Confirm the fleet teardown without a prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Tear down the collector and withdraw collector.cli-proxy-api.orbit.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        if (! $this->confirmAction(
            'Tear down the ProxyCli fleet feature? This stops the collector, withdraws collector.cli-proxy-api.orbit and its certificate, and deletes the stored management key and tokens.',
            'ProxyCli teardown cancelled.',
            requiredMessage: 'Non-interactive ProxyCli teardown requires --yes.',
        )) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new TeardownProxyCliRequest,
            ProxyCliStatusResponse::class,
            ['Teardown proxycli', 'Stopping the collector', 'Tore down proxycli'],
        );

        return $response instanceof ProxyCliStatusResponse
            ? $this->renderStatus($response, 'proxycli fleet feature is torn down.')
            : self::FAILURE;
    }
}
