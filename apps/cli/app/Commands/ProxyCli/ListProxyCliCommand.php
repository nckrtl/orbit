<?php

declare(strict_types=1);

namespace App\Commands\ProxyCli;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\ProxyCli\ListProxyCliProvidersRequest;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliProviderResponse;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliProvidersResponse;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliWindowResponse;

final class ListProxyCliCommand extends ProxyCliCommand
{
    #[\Override]
    protected $signature = 'proxycli:list {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List provider pools from the Valkey snapshot.';

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
            new ListProxyCliProvidersRequest,
            ProxyCliProvidersResponse::class,
            ['List proxycli providers', 'Loading provider pools', 'Loaded provider pools'],
        );

        if (! $response instanceof ProxyCliProvidersResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Provider', 'Windows', 'Accounts'],
            array_map(static fn (ProxyCliProviderResponse $provider): array => [
                $provider->provider,
                implode(', ', array_map(
                    static fn (ProxyCliWindowResponse $window): string => sprintf(
                        '%s %s%% remaining',
                        $window->label,
                        rtrim(rtrim(number_format($window->remainingPercent, 1), '0'), '.'),
                    ),
                    $provider->windows,
                )),
                (string) count($provider->accounts),
            ], $response->providers),
            'No provider pools in the snapshot.',
        ));
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
